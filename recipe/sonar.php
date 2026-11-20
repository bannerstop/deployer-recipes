<?php
/*
 * sonar recipe for Deployer 6: reports every deployment to sonar, bannerstop's monitoring, so that a Project
 * shows what was deployed when (`POST /intake/v1/deployment`, see the contract at /intake/v1/openapi.yaml of the
 * sonar instance). The recipe of the 7.x release line behaves the same.
 */
/*
## Installing

Require the recipe. It hooks itself into the tasks of the deployment (`deploy:update_code`, `deploy:symlink`, `deploy:failed`), nothing else has to be added:

```
require 'vendor/bannerstop/deployer-recipes/recipe/sonar.php';
set('sonar_environment', 'production');
```

Give the deploy environment the URL of the sonar instance and the Intake token of the Project (the Pulse token
of the Project, `sonar_pls_...`, not the token of one Check or the Relay's token), e.g. as CI secrets. The recipe
never stores them and never prints the token:

```
SONAR_URL=https://sonar.example.org
SONAR_TOKEN=sonar_pls_...
```

Without `SONAR_URL` and `SONAR_TOKEN` the recipe does nothing, silently. `SONAR_URL` has to be an `https://` address:
the token travels in it, so a plain `http://` address is refused with a warning and nothing is reported (`SONAR_ALLOW_HTTP=1`
lifts that for local tests only).

## What is reported

 - `after('deploy:update_code')`: the start (`status: started`). By now the release is cloned, its revision is known,
   and the commits of the deployment are read from its clone: `git log previous..current` (merge commits included),
   at most 100 (sha, subject, author), where `previous` is the revision of `current` before the deployment. The first
   deployment has no previous revision and sends no commits. When the clone does not know `previous` (a shallow
   clone), it is deepened once; if it still does not, the list stays empty.
 - `after('deploy:symlink')`: the end with `status: succeeded`, as soon as the new release is live, whichever other tasks the
   flow is made of (a task after it that fails does not turn the Deployment into a failed one: sonar keeps a finished
   Deployment as it is); `after('deploy:failed')`: `status: failed` (only when the start was reported: a deployment that
   failed before it had a revision says nothing). A flow without `deploy:symlink` has to hook `sonar:deployment:success`
   itself.
 - `before('rollback')` / `after('rollback')`: `status: rolled_back`, with the revision that is live again and the
   revision that was rolled back as the previous one. A rollback that changes nothing (only one release) reports nothing.

The start and the end share one client-generated id, so sonar records one Deployment that it completes. With several
hosts every host reports its own Deployment.

Commit subjects and authors that are not valid UTF-8 (a latin1 commit) are read as ISO-8859-1 before they are sent, so
the report is always valid JSON. Note that `dep -vvv` prints the output of `git log`, so author names appear in the log of a CI job
that runs with it.

## Best effort

Reporting never breaks a deployment: each request has a timeout of 5 seconds, every error (sonar unreachable, a
refusal, a slow answer) is only printed as a warning, and nothing is retried.

## Configuration

 - `sonar_url` - base URL of the sonar instance, defaults to the environment variable `SONAR_URL`
 - `sonar_token` - Intake token of the Project, defaults to the environment variable `SONAR_TOKEN`
 - `sonar_environment` - the environment of the deployment, a lower-case name of up to 32 letters, digits, dashes and
   underscores. **Set it explicitly** (`production` or `staging`); it falls back to the target of the host, cleaned up to fit.
   When neither gives a valid name, nothing is reported (with a warning) rather than guessing `production`.
 */
namespace Deployer;

const SONAR_TIMEOUT_SECONDS = 5;
const SONAR_MAX_COMMITS = 100;
const SONAR_DEEPEN_COMMITS = 500;

/**
 * An environment variable, trimmed, or null when it is not set or blank.
 */
function sonarEnvValue(string $name): ?string
{
    $value = getenv($name);
    $value = is_string($value) ? trim($value) : '';

    return $value === '' ? null : $value;
}

set('sonar_url', function () {
    return sonarEnvValue('SONAR_URL');
});

set('sonar_token', function () {
    return sonarEnvValue('SONAR_TOKEN');
});

set('sonar_environment', function () {
    $name = strtolower(trim((string) sonarSetting('target')));

    return trim((string) preg_replace('/[^a-z0-9_-]+/', '-', $name), '-_');
});

/**
 * A setting, or null when it is not set (Deployer 6 throws for `get()` of a name that does not exist when the default is
 * null, and `has()` ignores the global settings).
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

/**
 * The address reports go to, or null (with a warning) when SONAR_URL is not an https address.
 */
function sonarEndpoint(): ?string
{
    $url = (string) sonarSetting('sonar_url');
    $https = preg_match('#^https://#i', $url) === 1;
    $http = preg_match('#^http://#i', $url) === 1 && sonarEnvValue('SONAR_ALLOW_HTTP') === '1';
    if (!$https && !$http) {
        sonarWarning('SONAR_URL has to be an https:// address, the token travels in it; nothing is reported to sonar.');

        return null;
    }

    return rtrim($url, '/') . '/intake/v1/deployment';
}

/**
 * The environment of the deployment, or null (with a warning) when the setting is no valid name.
 */
function sonarEnvironment(): ?string
{
    $name = (string) sonarSetting('sonar_environment');
    if (preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $name) !== 1) {
        sonarWarning("sonar_environment is not a valid environment name (lower-case letters, digits, dashes and underscores, up to 32); nothing is reported to sonar. Set it, e.g. set('sonar_environment', 'production').");

        return null;
    }

    return $name;
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
 * Text that json_encode takes: a string that is not valid UTF-8 (a latin1 commit) is read as ISO-8859-1.
 */
function sonarClean(string $text): string
{
    return mb_check_encoding($text, 'UTF-8') ? $text : (string) mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
}

/**
 * Sends one report and never throws: a failure is only a warning, and the message never carries the token.
 */
function sonarReport(array $body): void
{
    try {
        $url = sonarEndpoint();
        if ($url === null) {
            return;
        }
        if (!extension_loaded('curl')) {
            sonarWarning('sonar could not be told about the deployment (the curl extension is missing); the deployment goes on.');

            return;
        }
        $json = json_encode($body);
        if ($json === false) {
            sonarWarning('sonar could not be told about the deployment (' . json_last_error_msg() . '); the deployment goes on.');

            return;
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Deployer');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . sonarSetting('sonar_token'),
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, SONAR_TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_TIMEOUT, SONAR_TIMEOUT_SECONDS);
        $answer = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = $answer === false ? curl_error($ch) : '';
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
        if ($status < 200 || $status >= 300) {
            sonarWarning('sonar did not take the deployment report' . ($status > 0 ? " (HTTP $status)" : ' (' . ($error !== '' ? $error : 'no answer') . ')') . '; the deployment goes on.');
        }
    } catch (\Throwable $exception) {
        sonarWarning('sonar could not be told about the deployment (' . substr(trim($exception->getMessage()), 0, 200) . '); the deployment goes on.');
    }
}

/**
 * The git command of the clone at `$path` and nothing else: with `--git-dir` a directory that is no clone never falls
 * back to a repository above it.
 */
function sonarGit(string $path): string
{
    return '{{bin/git}} --git-dir=' . $path . '/.git';
}

function sonarKnows(string $path, string $revision): bool
{
    try {
        run(sonarGit($path) . ' cat-file -e ' . escapeshellarg($revision . '^{commit}'));

        return true;
    } catch (\Throwable $exception) {
        return false;
    }
}

/**
 * The commits between two revisions as sonar takes them, newest first as git prints them, at most 100. Empty when the
 * clone does not know the old revision, even after it was deepened once.
 *
 * @return array[]
 */
function sonarCommits(string $old, string $new): array
{
    $path = '{{release_path}}';
    if (!sonarKnows($path, $old)) {
        try {
            run(sonarGit($path) . ' fetch --quiet --deepen=' . SONAR_DEEPEN_COMMITS);
        } catch (\Throwable $exception) {
            // not a shallow clone, or no way to fetch: the check below decides
        }
        if (!sonarKnows($path, $old)) {
            return [];
        }
    }
    $format = escapeshellarg('%H%x09%an%x09%s');
    $log = rtrim(run(sonarGit($path) . ' log --max-count=' . SONAR_MAX_COMMITS . ' --format=' . $format . ' ' . $old . '..' . $new), "\n");
    $commits = [];
    foreach ($log === '' ? [] : explode("\n", $log) as $entry) {
        $fields = explode("\t", $entry, 3);
        if (count($fields) === 3 && preg_match('/^[0-9a-f]{40,64}$/', $fields[0]) === 1) {
            $commits[] = ['sha' => $fields[0], 'subject' => mb_substr(sonarClean($fields[2]), 0, 500), 'author' => mb_substr(sonarClean($fields[1]), 0, 255)];
        }
    }

    return $commits;
}

/**
 * The revision of the clone at `$path`, null when there is none.
 */
function sonarRevisionOf(string $path): ?string
{
    try {
        $revision = trim(run(sonarGit($path) . ' rev-parse HEAD'));
    } catch (\Throwable $exception) {
        return null;
    }

    return preg_match('/^[0-9a-f]{7,64}$/', $revision) === 1 ? $revision : null;
}

desc('Tells sonar that a deployment started');
task('sonar:deployment:start', function () {
    if (!sonarEnabled() || sonarEndpoint() === null) {
        return;
    }

    try {
        $environment = sonarEnvironment();
        $revision = sonarRevisionOf('{{release_path}}');
        if ($environment === null || $revision === null) {
            return;
        }
        $previous = sonarRevisionOf('{{deploy_path}}/current');
        try {
            $commits = $previous === null || $previous === $revision ? [] : sonarCommits($previous, $revision);
        } catch (\Throwable $exception) {
            // the history cannot be read: report without a list
            $commits = [];
        }
        $deployment = [
            'id' => sonarUuid(),
            'environment' => $environment,
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
// not `after('deploy')`: Deployer 6 knows only the tasks that are defined when a hook is made, and a project defines `deploy` after it requires its recipes
after('deploy:symlink', 'sonar:deployment:success');

desc('Tells sonar that a deployment failed');
task('sonar:deployment:failure', function () {
    sonarFinish('failed');
})->setPrivate();
after('deploy:failed', 'sonar:deployment:failure');

desc('Remembers the revision that is live before a rollback');
task('sonar:rollback:prepare', function () {
    set('sonar_rolled_back_revision', null);
    if (!sonarEnabled()) {
        return;
    }
    try {
        set('sonar_rolled_back_revision', sonarRevisionOf('{{deploy_path}}/current'));
    } catch (\Throwable $exception) {
        set('sonar_rolled_back_revision', null);
    }
})->setPrivate();
before('rollback', 'sonar:rollback:prepare');

desc('Tells sonar that a deployment was rolled back');
task('sonar:rollback:report', function () {
    $rolledBack = sonarSetting('sonar_rolled_back_revision');
    if (!sonarEnabled() || $rolledBack === null || sonarEndpoint() === null) {
        return;
    }
    try {
        $environment = sonarEnvironment();
        $revision = sonarRevisionOf('{{deploy_path}}/current');
        // the same revision is live: there was no release to go back to
        if ($environment === null || $revision === null || $revision === $rolledBack) {
            return;
        }
        $now = sonarNow();
        sonarReport([
            'id' => sonarUuid(),
            'environment' => $environment,
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
