<?php
/*
 * End-to-end scenarios for the Rocket.Chat recipe.
 *
 * Every scenario runs real `dep deploy` calls against a throwaway SSH host and asserts on the JSON
 * payloads that arrive at the Rocket.Chat mock. Runs inside the deployer container of the major
 * version under test; see run.sh next to the docker-compose.yml.
 */

const REPO_URL = 'ssh://deploy@sshd/srv/git/app.git';
const GITHUB_URL = 'https://github.com/acme/app';
const MOCK_URL = 'http://mock:8080/payloads';
const WORK_DIR = '/tmp/work';
const KEY_FILE = '/tmp/e2e-id';

$sshOptions = '-i ' . KEY_FILE . ' -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR';
putenv('GIT_SSH_COMMAND=ssh ' . $sshOptions);

$clock = 1767225600;
$failures = [];
$checks = 0;

function sh(string $command, bool $mustSucceed = true): array
{
    exec($command . ' 2>&1', $lines, $status);
    $output = implode("\n", $lines);

    if ($mustSucceed && $status !== 0) {
        fwrite(STDERR, "Command failed ($status): $command\n$output\n");
        exit(2);
    }

    return [$status, $output];
}

function remote(string $command, bool $mustSucceed = true): array
{
    global $sshOptions;

    return sh('ssh ' . $sshOptions . ' deploy@sshd ' . escapeshellarg($command), $mustSucceed);
}

function commit(string $subject, string $author, bool $quiet = true): string
{
    global $clock;

    $clock++;
    file_put_contents(WORK_DIR . '/file.txt', $subject . "\n", FILE_APPEND);
    $date = '@' . $clock . ' +0000';
    $env = sprintf(
        'GIT_AUTHOR_NAME=%1$s GIT_AUTHOR_EMAIL=%2$s GIT_COMMITTER_NAME=%1$s GIT_COMMITTER_EMAIL=%2$s GIT_AUTHOR_DATE=%3$s GIT_COMMITTER_DATE=%3$s',
        escapeshellarg($author),
        escapeshellarg(strtolower(str_replace(' ', '.', $author)) . '@example.com'),
        escapeshellarg($date)
    );
    sh('cd ' . WORK_DIR . ' && git add -A && env ' . $env . ' git commit -q -m ' . escapeshellarg($subject));

    return head();
}

function mergeBranch(string $branch, string $author): void
{
    global $clock;

    $clock++;
    $date = '@' . $clock . ' +0000';
    $env = sprintf(
        'GIT_AUTHOR_NAME=%1$s GIT_AUTHOR_EMAIL=a@example.com GIT_COMMITTER_NAME=%1$s GIT_COMMITTER_EMAIL=a@example.com GIT_AUTHOR_DATE=%2$s GIT_COMMITTER_DATE=%2$s',
        escapeshellarg($author),
        escapeshellarg($date)
    );
    sh('cd ' . WORK_DIR . ' && env ' . $env . ' git merge -q --no-ff -m ' . escapeshellarg("Merge branch '$branch'") . ' ' . escapeshellarg($branch));
}

function head(): string
{
    return sh('cd ' . WORK_DIR . ' && git rev-parse HEAD')[1];
}

function short(string $hash): string
{
    return sh('cd ' . WORK_DIR . ' && git rev-parse --short ' . escapeshellarg($hash))[1];
}

function push(): void
{
    sh('cd ' . WORK_DIR . ' && git push -q -f origin main');
}

function fresh(): void
{
    sh('curl -s -X DELETE ' . MOCK_URL);
    remote('rm -rf ~/app /srv/git/app.git && git init -q --bare -b main /srv/git/app.git');
    sh('rm -rf ' . WORK_DIR . ' && git init -q ' . WORK_DIR . ' && git -C ' . WORK_DIR . ' symbolic-ref HEAD refs/heads/main');
    sh('cd ' . WORK_DIR . ' && git remote add origin ' . REPO_URL);
    commit('Initial commit', 'Alice Example');
    push();
}

function deploy(array $env = []): array
{
    sh('curl -s -X DELETE ' . MOCK_URL);

    $assignments = '';
    foreach ($env as $name => $value) {
        $assignments .= $name . '=' . escapeshellarg($value) . ' ';
    }

    [$status, $output] = sh('cd /opt/deployer && env ' . $assignments . 'vendor/bin/dep deploy test --no-interaction', false);
    $payloads = json_decode(sh('curl -s ' . MOCK_URL)[1], true);

    return [$status, $output, $payloads];
}

function expect(string $label, $expected, $actual): void
{
    global $failures, $checks;

    $checks++;
    if ($expected !== $actual) {
        $failures[] = $label . "\n  expected: " . json_encode($expected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . "\n  actual:   " . json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

function commitLine(string $hash, string $subject, string $author, bool $linked = true): string
{
    $short = short($hash);

    return $linked
        ? sprintf('[%s](%s/commit/%s) %s (%s)', $short, GITHUB_URL, $hash, $subject, $author)
        : sprintf('%s %s (%s)', $short, $subject, $author);
}

/**
 * Asserts the notifications of a deploy that succeeded and returns the attachments of the success one.
 */
function successAttachments(string $scenario, array $result): array
{
    [$status, $output, $payloads] = $result;

    expect("$scenario: dep deploy exit code", 0, $status);
    if ($status !== 0) {
        fwrite(STDERR, $output . "\n");
    }
    expect("$scenario: number of notifications", 2, count($payloads));

    $success = end($payloads) ?: [];
    expect("$scenario: success text", 'Deploy to *test* successful', $success['attachments'][0]['text'] ?? null);

    return $success['attachments'] ?? [];
}

function scenario(string $name, callable $run): void
{
    global $failures;

    $before = count($failures);
    $run($name);
    echo ($before === count($failures) ? 'PASS' : 'FAIL') . '  ' . $name . "\n";
}

// The key is mounted read-only with the permissions of the host user, ssh refuses to use that
is_dir('/root/.ssh') || mkdir('/root/.ssh', 0700);
copy('/keys/id', KEY_FILE);
chmod(KEY_FILE, 0600);

$ready = false;
for ($attempt = 0; $attempt < 30 && !$ready; $attempt++) {
    $ready = remote('true', false)[0] === 0;
    $ready || sleep(1);
}
if (!$ready) {
    fwrite(STDERR, "The SSH host did not come up\n");
    exit(2);
}
sh('git config --global user.name e2e && git config --global user.email e2e@example.com');

scenario('1. first deploy sends no commit list', function ($name) {
    fresh();
    $attachments = successAttachments($name, deploy());

    expect("$name: attachments", 1, count($attachments));
});

scenario('2. follow-up deploy lists the commits and leaves out the merge commit', function ($name) {
    fresh();
    deploy();

    $first = commit('Fix the cart total', 'Bob Example');
    $second = commit('Add voucher field', 'Alice Example');
    sh('cd ' . WORK_DIR . ' && git checkout -q -b feature/export');
    $third = commit('Export orders as CSV', 'Carol Example');
    sh('cd ' . WORK_DIR . ' && git checkout -q main');
    mergeBranch('feature/export', 'Alice Example');
    push();

    $attachments = successAttachments($name, deploy());

    expect("$name: attachments", 2, count($attachments));
    expect("$name: commit lines", implode("\n", [
        commitLine($third, 'Export orders as CSV', 'Carol Example'),
        commitLine($second, 'Add voucher field', 'Alice Example'),
        commitLine($first, 'Fix the cart total', 'Bob Example'),
    ]), $attachments[1]['text'] ?? null);
});

scenario('3. follow-up deploy with 25 commits is capped at 20 lines', function ($name) {
    fresh();
    deploy();
    $old = head();

    $hashes = [];
    for ($i = 1; $i <= 25; $i++) {
        $hashes[$i] = commit("Change $i", 'Bob Example');
    }
    push();

    $attachments = successAttachments($name, deploy());

    $expected = [];
    for ($i = 25; $i > 5; $i--) {
        $expected[] = commitLine($hashes[$i], "Change $i", 'Bob Example');
    }
    $expected[] = sprintf('[… and 5 more](%s/compare/%s...%s)', GITHUB_URL, $old, $hashes[25]);

    expect("$name: attachments", 2, count($attachments));
    expect("$name: commit lines", implode("\n", $expected), $attachments[1]['text'] ?? null);
});

scenario('4. rocketchat_commits = false sends no commit list', function ($name) {
    fresh();
    deploy(['E2E_COMMITS' => 'off']);
    commit('Fix the cart total', 'Bob Example');
    push();

    $attachments = successAttachments($name, deploy(['E2E_COMMITS' => 'off']));

    expect("$name: attachments", 1, count($attachments));
});

scenario('5. undeterminable previous revision sends no commit list and the deploy succeeds', function ($name) {
    fresh();
    deploy();
    commit('Fix the cart total', 'Bob Example');
    push();
    remote(getenv('BREAK_PREVIOUS'));

    $attachments = successAttachments($name, deploy());

    expect("$name: attachments", 1, count($attachments));
});

scenario('6. repository outside GitHub lists the commits without links', function ($name) {
    $env = ['E2E_REPOSITORY' => '/srv/git/app.git'];

    fresh();
    deploy($env);
    $first = commit('Fix the cart total', 'Bob Example');
    $second = commit('Add voucher field', 'Alice Example');
    push();

    $attachments = successAttachments($name, deploy($env));

    expect("$name: commit lines", implode("\n", [
        commitLine($second, 'Add voucher field', 'Alice Example', false),
        commitLine($first, 'Fix the cart total', 'Bob Example', false),
    ]), $attachments[1]['text'] ?? null);
});

echo "\n" . $checks . ' checks, ' . count($failures) . " failed\n";
foreach ($failures as $failure) {
    echo "\n" . $failure . "\n";
}
exit($failures ? 1 : 0);
