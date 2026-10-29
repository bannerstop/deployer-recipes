<?php
/*
 * Rocket.Chat recipe for Deployer 7. Same configuration keys and behaviour as the recipe of the 6.x
 * release line, extended by the person who deployed and the list of deployed commits.
 *
 * @see https://deployer.org/docs/7.x/contrib/rocketchat
 */
/*
## Installing

Create a RocketChat incoming webhook, through the administration panel.

Add hook on deploy:

```
before('deploy', 'rocketchat:notify');
```

## Configuration

 - `rocketchat_webhook` - incoming rocketchat webook **required**
   ```
   set('rocketchat_webhook', 'https://rocketchat.yourcompany.com/hooks/XXXXX');
   ```

 - `rocketchat_title` - the title of the application, defaults to `{{application}}`
 - `deploy_user` - the person who triggered the deployment. `{{user}}` is not usable for this,
   as every key of a host in `hosts.yml` is also set as host config, so the SSH login name
   overrides it. Resolved from the `DEPLOY_USER`, `GITHUB_ACTOR` or `GITLAB_USER_LOGIN`
   environment variable, then from the bind mount paths when Deployer runs inside a container,
   and finally falls back to `{{user}}`.
   ```
   set('deploy_user', 'ci');
   ```
 - `rocketchat_commits` - whether the success notification lists the commits that went live with the
   deployment, defaults to `true`. The list covers the revision that was in `current` before the
   deployment (exclusive) up to the revision just deployed (inclusive), without merge commits. The
   history is read from the bare repository cache under `{{deploy_path}}/.dep/repo`, the revisions
   from the `REVISION` file of the releases. It is left
   out on the first deployment, when the previous revision cannot be determined and when nothing changed.
   Failing to build the list never fails the deployment or suppresses the notification.
   ```
   set('rocketchat_commits', false);
   ```
 - `rocketchat_commits_limit` - maximum number of commits listed, defaults to `20`. Further commits are
   summed up in a last line that links to the GitHub compare view. Commit hashes and the compare link are
   only linked when `repository` is a GitHub repository.
   ```
   set('rocketchat_commits_limit', 50);
   ```
 - `rocketchat_text` - notification message
   ```
   set('rocketchat_text', '_{{deploy_user}}_ deploying {{target}} to {{where}}');
   ```

 - `rocketchat_success_text` – success template, default:
  ```
  set('rocketchat_success_text', 'Deploy to *{{where}}* successful');
  ```
 - `rocketchat_failure_text` – failure template, default:
  ```
  set('rocketchat_failure_text', 'Deploy to *{{where}}* failed');
  ```

 - `rocketchat_color` – color's attachment
 - `rocketchat_success_color` – success color's attachment
 - `rocketchat_failure_color` – failure color's attachment

## Usage

If you want to notify only about beginning of deployment add this line only:

```php
before('deploy', 'rocketchat:notify');
```

If you want to notify about successful end of deployment add this too:

```php
after('deploy:success', 'rocketchat:notify:success');
```

If you want to notify about failed deployment add this too:

```php
after('deploy:failed', 'rocketchat:notify:failure');
```

 */
namespace Deployer;

use Deployer\Utility\Httpie;

set('rockchat_title', function () {
    return get('application', 'Project');
});

set('rocketchat_icon_emoji', ':robot:');
set('rocketchat_icon_url', null);

set('rocketchat_channel', null);
set('rocketchat_room_id', null);
set('rocketchat_username', null);
set('rocketchat_webhook', null);

set('rocketchat_color', '#000000');
set('rocketchat_success_color', '#00c100');
set('rocketchat_failure_color', '#ff0909');

set('deploy_user', function () {
    foreach (['DEPLOY_USER', 'GITHUB_ACTOR', 'GITLAB_USER_LOGIN'] as $variable) {
        $value = getenv($variable);

        if (is_string($value) && $value !== '') {
            return $value;
        }
    }

    // Deployer usually runs inside a container, where neither the login name nor the git config
    // of the person deploying is available. The bind mount source paths still carry their home
    // directory though, e.g. "/home/jdoe/.ssh /transfer/.ssh" for the mounted SSH directory.
    $mounts = @file('/proc/self/mountinfo') ?: [];

    foreach ([' /transfer/.ssh ', null] as $preferred) {
        foreach ($mounts as $mount) {
            if ($preferred !== null && strpos($mount, $preferred) === false) {
                continue;
            }

            if (preg_match('#\s(?:/home|/Users)/([^/\s]+)/#', $mount, $matches)) {
                return $matches[1];
            }
        }
    }

    return get('user');
});

set('rocketchat_commits', true);
set('rocketchat_commits_limit', 20);

set('rocketchat_text', '_{{deploy_user}}_ deploying `{{target}}` to *{{where}}*');
set('rocketchat_success_text', 'Deploy to *{{where}}* successful');
set('rocketchat_failure_text', 'Deploy to *{{where}}* failed');

function callRocketChatWebhook(string $url, array $data): void
{
    Httpie::post($url)->jsonBody($data)->send();
}

/**
 * Derives the web URL of a GitHub repository from the SSH or HTTPS form of its clone URL.
 */
function rocketChatGitHubUrl(string $repository): ?string
{
    $pattern = '#^(?:git@github\.com:|(?:https?|ssh|git)://(?:[^@/]+@)?github\.com[:/])([^/\s]+/[^/\s]+?)(?:\.git)?/?$#';

    if (preg_match($pattern, trim($repository), $matches)) {
        return 'https://github.com/' . $matches[1];
    }

    return null;
}

/**
 * File on the host that carries the revisions of the deployment in progress from the moment before
 * `current` is switched to the success notification.
 */
function rocketChatRangeFile(): string
{
    return '{{deploy_path}}/.dep/rocketchat_deployed_range';
}

function rocketChatCommitsEnabled(): bool
{
    return filter_var(get('rocketchat_commits'), FILTER_VALIDATE_BOOLEAN);
}

/**
 * Builds the attachment that lists the deployed commits, null if there is nothing to list.
 */
function rocketChatCommitsAttachment(): ?array
{
    $git = '--git-dir={{deploy_path}}/.dep/repo';
    $range = trim(run('cat ' . rocketChatRangeFile()));

    if (!preg_match('/^([0-9a-f]{40}) ([0-9a-f]{40})$/', $range, $matches)) {
        return null;
    }

    [, $old, $new] = $matches;

    // The range was recorded for the deployment that is being announced, not for an earlier one
    if (trim(run('cat {{current_path}}/REVISION')) !== $new) {
        return null;
    }

    $limit = max(1, (int) get('rocketchat_commits_limit'));
    $total = (int) run("{{bin/git}} $git rev-list --no-merges --count $old..$new");

    if ($total === 0) {
        return null;
    }

    $format = escapeshellarg('%h%x09%H%x09%an%x09%s');
    $log = trim(run("{{bin/git}} $git log --no-merges --max-count=$limit --format=$format $old..$new"));
    $url = rocketChatGitHubUrl((string) get('repository'));

    $lines = [];
    foreach (explode("\n", $log) as $entry) {
        [$short, $hash, $author, $subject] = explode("\t", $entry, 4);
        $lines[] = sprintf('%s %s (%s)', $url === null ? $short : "[$short]($url/commit/$hash)", $subject, $author);
    }

    if ($total > $limit) {
        $more = sprintf('… and %d more', $total - $limit);
        $lines[] = $url === null ? $more : "[$more]($url/compare/$old...$new)";
    }

    return [
        'title' => 'Deployed commits',
        'text' => implode("\n", $lines),
        'color' => get('rocketchat_success_color'),
    ];
}

desc('Notifies RocketChat');
task('rocketchat:notify', function () {
    if (null === get('rocketchat_webhook')) {
        return;
    }

    $body = [
        'text' => get('rockchat_title'),
        'username' => get('rocketchat_username'),
        'attachments' => [[
            'text' => get('rocketchat_text'),
            'color' => get('rocketchat_color'),
        ]]
    ];

    if (get('rocketchat_channel')) {
        $body['channel'] = get('rocketchat_channel');
    }
    if (get('rocketchat_room_id')) {
        $body['roomId'] = get('rocketchat_room_id');
    }
    if (get('rocketchat_icon_url')) {
        $body['avatar'] = get('rocketchat_icon_url');
    } elseif (get('rocketchat_icon_emoji')) {
        $body['emoji'] = get('rocketchat_icon_emoji');
    }

    callRocketChatWebhook(get('rocketchat_webhook'), $body);
});

desc('Records the revision that is live before the new release is activated');
task('rocketchat:commits:prepare', function () {
    if (null === get('rocketchat_webhook') || !rocketChatCommitsEnabled()) {
        return;
    }

    $file = rocketChatRangeFile();

    try {
        run("rm -f $file");
        $old = trim(run('cat {{current_path}}/REVISION'));
        $new = trim(run('cat {{release_path}}/REVISION'));
        run("echo '$old $new' > $file");
    } catch (\Throwable $exception) {
        // First deployment or no revision on record, the notification then comes without a commit list
    }
})->hidden();
before('deploy:symlink', 'rocketchat:commits:prepare');

desc('Notifies RocketChat about deploy finish');
task('rocketchat:notify:success', function () {
    if (null === get('rocketchat_webhook')) {
        return;
    }

    $body = [
        'text' => get('rockchat_title'),
        'username' => get('rocketchat_username'),
        'attachments' => [[
            'text' => get('rocketchat_success_text'),
            'color' => get('rocketchat_success_color'),
        ]]
    ];

    if (rocketChatCommitsEnabled()) {
        try {
            $commits = rocketChatCommitsAttachment();
        } catch (\Throwable $exception) {
            $commits = null;
        }

        if ($commits !== null) {
            $body['attachments'][] = $commits;
        }
    }

    if (get('rocketchat_channel')) {
        $body['channel'] = get('rocketchat_channel');
    }
    if (get('rocketchat_room_id')) {
        $body['roomId'] = get('rocketchat_room_id');
    }
    if (get('rocketchat_icon_url')) {
        $body['avatar'] = get('rocketchat_icon_url');
    } elseif (get('rocketchat_icon_emoji')) {
        $body['emoji'] = get('rocketchat_icon_emoji');
    }

    callRocketChatWebhook(get('rocketchat_webhook'), $body);
});

desc('Notifies RocketChat about deploy failure');
task('rocketchat:notify:failure', function () {
    if (null === get('rocketchat_webhook')) {
        return;
    }

    $body = [
        'text' => get('rockchat_title'),
        'username' => get('rocketchat_username'),
        'attachments' => [[
            'color' => get('rocketchat_failure_color'),
            'text' => get('rocketchat_failure_text')
        ]]
    ];

    if (get('rocketchat_channel')) {
        $body['channel'] = get('rocketchat_channel');
    }
    if (get('rocketchat_room_id')) {
        $body['roomId'] = get('rocketchat_room_id');
    }
    if (get('rocketchat_icon_url')) {
        $body['avatar'] = get('rocketchat_icon_url');
    } elseif (get('rocketchat_icon_emoji')) {
        $body['emoji'] = get('rocketchat_icon_emoji');
    }

    callRocketChatWebhook(get('rocketchat_webhook'), $body);
});

