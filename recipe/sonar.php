<?php
/*
 * sonar recipe for Deployer 6: reports every deployment to sonar, bannerstop's monitoring, so that a Project
 * shows what was deployed when (`POST /intake/v1/deployment`, see the contract at /intake/v1/openapi.yaml of the
 * sonar instance). Same configuration and behaviour as the recipe of the 7.x release line.
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

 - `after('deploy:update_code')`: the start (`status: started`). By now the release is cloned, its revision is known,
   and the commits of the deployment are read from its clone: `git log previous..current` (merge commits included),
   at most 100 (sha, subject, author), where `previous` is the revision of `current` before the deployment. The first
   deployment has no previous revision and sends no commits.
 - `after('success')`: the end with `status: succeeded` (the deployment has to run the `success` task, as the
   Deployer recipes do); `after('deploy:failed')`: `status: failed` (only when the start was reported: a
   deployment that failed before it had a revision says nothing).
 - `before('rollback')` / `after('rollback')`: `status: rolled_back`, with the revision that is live again and the
   revision that was rolled back as the previous one.

The start and the end share one client-generated id, so sonar records one Deployment that it completes.

## Best effort

Reporting never breaks a deployment: each request has a timeout of 5 seconds, every error (sonar unreachable, a
refusal, a slow answer) is only printed as a warning, and nothing is retried.

## Configuration

 - `sonar_url` - base URL of the sonar instance, defaults to the environment variable `SONAR_URL`
 - `sonar_token` - Intake token of the Project, defaults to the environment variable `SONAR_TOKEN`
 - `sonar_environment` - the environment of the deployment, a lower-case name of up to 32 letters, digits, dashes and
   underscores. **Set it explicitly** (`production` or `staging`); it falls back to the target (stage) of the host,
   cleaned up to fit, which is only right when the target is the environment.
   ```
   set('sonar_environment', 'production');
   ```
 */
namespace Deployer;

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
    $name = strtolower(trim((string) (sonarSetting('target') ?? 'production')));
    $name = trim((string) preg_replace('/[^a-z0-9_-]+/', '-', $name), '-_');

    return $name === '' ? 'production' : substr($name, 0, 32);
});

/**
 * A setting, or null when it is not set (Deployer 6 throws for `get()` of a name that does not exist when the default is null, and `has()` ignores the global settings).
 */
function sonarSetting(string $name)
{
    try {
        return get($name);
    } catch (\Throwable $exception) {
        return null;
    }
}

function sonarEnabled(): bool
{
    return sonarSetting('sonar_url') !== null && sonarSetting('sonar_token') !== null;
}

function sonarWarning(string $message): void
{
    writeln('<comment>' . $message . '</comment>');
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
        if (!extension_loaded('curl')) {
            sonarWarning('sonar could not be told about the deployment (the curl extension is missing); the deployment goes on.');

            return;
        }
        $json = json_encode($body);
        $ch = curl_init(rtrim((string) get('sonar_url'), '/') . '/intake/v1/deployment');
        curl_setopt($ch, CURLOPT_USERAGENT, 'Deployer');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json),
            'Authorization: Bearer ' . get('sonar_token'),
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, SONAR_TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_TIMEOUT, SONAR_TIMEOUT_SECONDS);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status < 200 || $status >= 300) {
            sonarWarning('sonar did not take the deployment report' . ($status > 0 ? " (HTTP $status)" : ' (no answer)') . '; the deployment goes on.');
        }
    } catch (\Throwable $exception) {
        sonarWarning('sonar could not be told about the deployment (' . get_class($exception) . '); the deployment goes on.');
    }
}

/**
 * The commits between two revisions as sonar takes them, newest first as git prints them, at most 100.
 *
 * @return array[]
 */
function sonarCommits(string $old, string $new): array
{
    $format = escapeshellarg('%H%x09%an%x09%s');
    $log = trim(run(get('bin/git') . ' -C {{release_path}} log --max-count=' . SONAR_MAX_COMMITS . ' --format=' . $format . ' ' . $old . '..' . $new));
    $commits = [];
    foreach ($log === '' ? [] : explode("\n", $log) as $entry) {
        $fields = explode("\t", $entry, 3);
        if (count($fields) === 3) {
            $commits[] = ['sha' => $fields[0], 'subject' => mb_substr($fields[2], 0, 500), 'author' => mb_substr($fields[1], 0, 255)];
        }
    }

    return $commits;
}

/**
 * The revision of the clone at `$path`, null when there is none.
 */
function sonarRevisionOf(string $path)
{
    try {
        $revision = trim(run(get('bin/git') . ' -C ' . $path . ' rev-parse HEAD'));
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
        $previous = sonarRevisionOf('{{deploy_path}}/current');
        try {
            $commits = $previous === null || $previous === $revision ? [] : sonarCommits($previous, $revision);
        } catch (\Throwable $exception) {
            // the previous revision is not in the clone of the release: report without a list
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
        sonarWarning('sonar could not be told about the start of the deployment (' . get_class($exception) . '); the deployment goes on.');
    }
})->setPrivate();
after('deploy:update_code', 'sonar:deployment:start');

function sonarFinish(string $status): void
{
    $deployment = sonarSetting('sonar_deployment');
    if (!sonarEnabled() || !is_array($deployment)) {
        return;
    }
    // a repeated report of the same id completes the Deployment
    sonarReport(array_merge($deployment, ['status' => $status, 'finishedAt' => sonarNow()]));
    set('sonar_deployment', null);
}

desc('Tells sonar that a deployment succeeded');
task('sonar:deployment:success', function () {
    sonarFinish('succeeded');
})->setPrivate();
after('success', 'sonar:deployment:success');

desc('Tells sonar that a deployment failed');
task('sonar:deployment:failure', function () {
    sonarFinish('failed');
})->setPrivate();
after('deploy:failed', 'sonar:deployment:failure');

desc('Remembers the revision that is live before a rollback');
task('sonar:rollback:prepare', function () {
    if (!sonarEnabled()) {
        return;
    }
    set('sonar_rolled_back_revision', sonarRevisionOf('{{deploy_path}}/current'));
})->setPrivate();
before('rollback', 'sonar:rollback:prepare');

desc('Tells sonar that a deployment was rolled back');
task('sonar:rollback:report', function () {
    $rolledBack = sonarSetting('sonar_rolled_back_revision');
    if (!sonarEnabled() || $rolledBack === null) {
        return;
    }
    try {
        $revision = sonarRevisionOf('{{deploy_path}}/current');
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
        sonarWarning('sonar could not be told about the rollback (' . get_class($exception) . '); the rollback is done.');
    }
})->setPrivate();
after('rollback', 'sonar:rollback:report');
