<?php
/*
 * sonar recipe for Deployer 7: reports every deployment to sonar, bannerstop's monitoring, so that a Project
 * shows what was deployed when (`POST /intake/v1/deployment`, see the contract at /intake/v1/openapi.yaml of the
 * sonar instance).
 */
/*
## Installing

Require the recipe. It hooks itself into the deployment, nothing else has to be added:

```
require 'vendor/bannerstop/deployer-recipes/recipe/sonar.php';
```

Give the deploy environment the URL of the sonar instance and the Intake token of the Project (the Pulse token
of the Project, `sonar_pls_...`, not the token of one Check or the Relay's token), e.g. as CI secrets. The recipe
never stores them and never prints the token:

```
SONAR_URL=https://sonar.example.org
SONAR_TOKEN=sonar_pls_...
```

Without `SONAR_URL` and `SONAR_TOKEN` the recipe does nothing, silently.

## What is reported

 - `after('deploy:update_code')`: the start (`status: started`). By now the revision of the release is known, and the
   commits of the deployment are read from the repository cache under `{{deploy_path}}/.dep/repo`: `git log
   previous..current` without merge commits, at most 100 (sha, subject, author), where `previous` is the revision of
   `current` before the deployment. The first deployment has no previous revision and sends no commits.
 - `after('deploy:success')`: the end with `status: succeeded`; `after('deploy:failed')`: `status: failed` (only when
   the start was reported: a deployment that failed before it had a revision says nothing).
 - `before('rollback')` / `after('rollback')`: `status: rolled_back`, with the revision that is live again and the
   revision that was rolled back as the previous one.

The start and the end share one client-generated id, so sonar records one Deployment that it updates.

## Best effort

Reporting never breaks a deployment: each request has a timeout of 5 seconds, every error (sonar unreachable, a
refusal, a slow answer) is only printed as a warning, and nothing is retried.

## Configuration

 - `sonar_url` - base URL of the sonar instance, defaults to the environment variable `SONAR_URL`
 - `sonar_token` - Intake token of the Project, defaults to the environment variable `SONAR_TOKEN`
 - `sonar_environment` - the environment of the deployment, a lower-case name of up to 32 letters, digits, dashes and
   underscores. Defaults to the alias of the host (`production`, `staging`, ...), cleaned up to fit.
   ```
   set('sonar_environment', 'production');
   ```
 */
namespace Deployer;

use Deployer\Utility\Httpie;

const SONAR_TIMEOUT_SECONDS = 5;
const SONAR_MAX_COMMITS = 100;

set('sonar_url', function () {
    $value = getenv('SONAR_URL');

    return is_string($value) && $value !== '' ? $value : null;
});

set('sonar_token', function () {
    $value = getenv('SONAR_TOKEN');

    return is_string($value) && $value !== '' ? $value : null;
});

set('sonar_environment', function () {
    $name = strtolower(trim((string) get('alias', 'production')));
    $name = trim((string) preg_replace('/[^a-z0-9_-]+/', '-', $name), '-_');

    return $name === '' ? 'production' : substr($name, 0, 32);
});

function sonarEnabled(): bool
{
    return get('sonar_url') !== null && get('sonar_token') !== null;
}

function sonarUuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function sonarNow(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

/**
 * Sends one report and never throws: a failure is only a warning, and the message never carries the token.
 */
function sonarReport(array $body): void
{
    try {
        $url = rtrim((string) get('sonar_url'), '/') . '/intake/v1/deployment';
        $info = [];
        Httpie::post($url)
            ->header('Authorization', 'Bearer ' . get('sonar_token'))
            ->setopt(CURLOPT_TIMEOUT, SONAR_TIMEOUT_SECONDS)
            ->setopt(CURLOPT_CONNECTTIMEOUT, SONAR_TIMEOUT_SECONDS)
            ->nothrow()
            ->jsonBody($body)
            ->send($info);
        $status = (int) ($info['http_code'] ?? 0);
        if ($status < 200 || $status >= 300) {
            warning('sonar did not take the deployment report' . ($status > 0 ? " (HTTP $status)" : ' (no answer)') . '; the deployment goes on.');
        }
    } catch (\Throwable $exception) {
        warning('sonar could not be told about the deployment (' . get_class($exception) . '); the deployment goes on.');
    }
}

/**
 * The commits between two revisions as sonar takes them, newest first as git prints them, at most 100.
 *
 * @return list<array{sha: string, subject: string, author: string}>
 */
function sonarCommits(string $old, string $new): array
{
    $git = '--git-dir={{deploy_path}}/.dep/repo';
    $format = escapeshellarg('%H%x09%an%x09%s');
    $log = trim(run('{{bin/git}} ' . $git . ' log --no-merges --max-count=' . SONAR_MAX_COMMITS . ' --format=' . $format . ' ' . $old . '..' . $new));
    $commits = [];
    foreach ($log === '' ? [] : explode("\n", $log) as $entry) {
        $fields = explode("\t", $entry, 3);
        if (count($fields) === 3) {
            $commits[] = ['sha' => $fields[0], 'subject' => mb_substr($fields[2], 0, 500), 'author' => mb_substr($fields[1], 0, 255)];
        }
    }

    return $commits;
}

function sonarRevisionOf(string $path): ?string
{
    try {
        $revision = trim(run('cat ' . $path . '/REVISION'));
    } catch (\Throwable $exception) {
        return null;
    }

    return preg_match('/^[0-9a-f]{7,64}$/', $revision) === 1 ? $revision : null;
}

desc('Tells sonar that a deployment started');
task('sonar:deployment:start', function () {
    if (!sonarEnabled()) {
        return;
    }

    try {
        $revision = sonarRevisionOf('{{release_path}}');
        if ($revision === null) {
            return;
        }
        $previous = sonarRevisionOf('{{current_path}}');
        try {
            $commits = $previous === null || $previous === $revision ? [] : sonarCommits($previous, $revision);
        } catch (\Throwable $exception) {
            // the previous revision is not in the repository cache any more: report without a list
            $commits = [];
        }
        $deployment = [
            'id' => sonarUuid(),
            'environment' => get('sonar_environment'),
            'revision' => $revision,
            'commits' => $commits,
            'startedAt' => sonarNow(),
            'status' => 'started',
        ];
        if ($previous !== null) {
            $deployment['previousRevision'] = $previous;
        }
        set('sonar_deployment', $deployment);
        sonarReport($deployment);
    } catch (\Throwable $exception) {
        warning('sonar could not be told about the start of the deployment (' . get_class($exception) . '); the deployment goes on.');
    }
})->hidden();
after('deploy:update_code', 'sonar:deployment:start');

function sonarFinish(string $status): void
{
    $deployment = get('sonar_deployment', null);
    if (!sonarEnabled() || !is_array($deployment)) {
        return;
    }
    // a repeated report of the same id updates the Deployment
    sonarReport(array_merge($deployment, ['status' => $status, 'finishedAt' => sonarNow()]));
    set('sonar_deployment', null);
}

desc('Tells sonar that a deployment succeeded');
task('sonar:deployment:success', function () {
    sonarFinish('succeeded');
})->hidden();
after('deploy:success', 'sonar:deployment:success');

desc('Tells sonar that a deployment failed');
task('sonar:deployment:failure', function () {
    sonarFinish('failed');
})->hidden();
after('deploy:failed', 'sonar:deployment:failure');

desc('Remembers the revision that is live before a rollback');
task('sonar:rollback:prepare', function () {
    if (!sonarEnabled()) {
        return;
    }
    try {
        set('sonar_rolled_back_revision', sonarRevisionOf('{{current_path}}'));
    } catch (\Throwable $exception) {
        set('sonar_rolled_back_revision', null);
    }
})->hidden();
before('rollback', 'sonar:rollback:prepare');

desc('Tells sonar that a deployment was rolled back');
task('sonar:rollback:report', function () {
    $rolledBack = get('sonar_rolled_back_revision', null);
    if (!sonarEnabled() || $rolledBack === null) {
        return;
    }
    try {
        $revision = sonarRevisionOf('{{current_path}}');
        if ($revision === null) {
            return;
        }
        $now = sonarNow();
        sonarReport([
            'id' => sonarUuid(),
            'environment' => get('sonar_environment'),
            'revision' => $revision,
            'previousRevision' => $rolledBack,
            'commits' => [],
            'startedAt' => $now,
            'finishedAt' => $now,
            'status' => 'rolled_back',
        ]);
    } catch (\Throwable $exception) {
        warning('sonar could not be told about the rollback (' . get_class($exception) . '); the rollback is done.');
    }
})->hidden();
after('rollback', 'sonar:rollback:report');
