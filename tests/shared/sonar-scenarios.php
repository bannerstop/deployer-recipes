<?php
/*
 * End-to-end scenarios for the sonar recipe, run by scenarios.php after the Rocket.Chat ones (it defines the helpers).
 * The sonar of these scenarios is the mock: it records every report with the Authorization header it came with.
 * The token is an invented one.
 */

const SONAR_TOKEN = 'sonar_pls_0000000000000000000000000000000000000000000000000000000000000000';

function sonarEnv(string $prefix = ''): array
{
    // the mock speaks http, which the recipe only takes with SONAR_ALLOW_HTTP
    return ['SONAR_URL' => 'http://mock:8080' . $prefix, 'SONAR_TOKEN' => SONAR_TOKEN, 'SONAR_ALLOW_HTTP' => '1'];
}

function sonarRequests(): array
{
    return json_decode(sh('curl -s http://mock:8080/sonar')[1], true);
}

/** `dep rollback` with the environment, as a deployer user runs it after a bad release. */
function rollback(array $env = []): array
{
    sh('curl -s -X DELETE ' . MOCK_URL);

    $assignments = '';
    foreach ($env as $name => $value) {
        $assignments .= $name . '=' . escapeshellarg($value) . ' ';
    }

    return sh('cd /opt/deployer && env ' . $assignments . 'vendor/bin/dep rollback test --no-interaction', false);
}

scenario('7. sonar gets the start and the end of a deployment with its commits, as one record', function ($name) {
    fresh();
    deploy();
    $old = head();
    $first = commit('Fix the cart total', 'Bob Example');
    $second = commit('Add voucher field', 'Alice Example');
    push();

    $result = deploy(sonarEnv());
    successAttachments($name, $result);
    $requests = sonarRequests();

    expect("$name: number of reports", 2, count($requests));
    [$start, $end] = $requests + [[], []];
    expect("$name: token", 'Bearer ' . SONAR_TOKEN, $start['authorization'] ?? null);
    expect("$name: same id", true, isset($start['body']['id']) && ($start['body']['id'] === ($end['body']['id'] ?? null)));
    expect("$name: id is a uuid", 1, preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $start['body']['id'] ?? ''));
    expect("$name: start status", 'started', $start['body']['status'] ?? null);
    expect("$name: start has no end", false, isset($start['body']['finishedAt']));
    expect("$name: end status", 'succeeded', $end['body']['status'] ?? null);
    expect("$name: end has finishedAt", 1, preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $end['body']['finishedAt'] ?? ''));
    expect("$name: same start time", $start['body']['startedAt'] ?? null, $end['body']['startedAt'] ?? null);
    foreach ([$start, $end] as $report) {
        expect("$name: environment", 'test', $report['body']['environment'] ?? null);
        expect("$name: revision", $second, $report['body']['revision'] ?? null);
        expect("$name: previous revision", $old, $report['body']['previousRevision'] ?? null);
        expect("$name: commits, newest first", [
            ['sha' => $second, 'subject' => 'Add voucher field', 'author' => 'Alice Example'],
            ['sha' => $first, 'subject' => 'Fix the cart total', 'author' => 'Bob Example'],
        ], $report['body']['commits'] ?? null);
    }
});

scenario('8. the first deployment is reported without previous revision and commits', function ($name) {
    fresh();

    $result = deploy(sonarEnv());
    successAttachments($name, $result);
    $requests = sonarRequests();

    expect("$name: number of reports", 2, count($requests));
    expect("$name: no previous revision", false, isset($requests[0]['body']['previousRevision']));
    expect("$name: no commits", [], $requests[0]['body']['commits'] ?? null);
    expect("$name: revision", head(), $requests[0]['body']['revision'] ?? null);
});

scenario('9. a deployment that fails is reported failed under the id of its start', function ($name) {
    fresh();
    deploy();
    commit('Fix the cart total', 'Bob Example');
    push();

    [$status, , $payloads] = deploy(sonarEnv() + ['E2E_BREAK' => '1']);
    $requests = sonarRequests();

    expect("$name: dep deploy fails", true, $status !== 0);
    expect("$name: failure notification of Rocket.Chat", 'Deploy to *test* failed', end($payloads)['attachments'][0]['text'] ?? null);
    expect("$name: number of reports", 2, count($requests));
    expect("$name: start status", 'started', $requests[0]['body']['status'] ?? null);
    expect("$name: end status", 'failed', $requests[1]['body']['status'] ?? null);
    expect("$name: same id", $requests[0]['body']['id'] ?? null, $requests[1]['body']['id'] ?? null);
    expect("$name: end has finishedAt", 1, preg_match('/^\d{4}-/', $requests[1]['body']['finishedAt'] ?? ''));
});

scenario('10. without SONAR_URL and SONAR_TOKEN nothing is sent and nothing is said', function ($name) {
    fresh();
    deploy();
    commit('Fix the cart total', 'Bob Example');
    push();

    foreach ([[], ['SONAR_URL' => 'http://mock:8080'], ['SONAR_TOKEN' => SONAR_TOKEN]] as $env) {
        $result = deploy($env);
        successAttachments($name, $result);
        expect("$name: no report (" . implode(',', array_keys($env)) . ')', [], sonarRequests());
        // Deployer lists the tasks it runs ("task sonar:deployment:start", "Executing task sonar:..." in 6); the recipe itself says nothing
        $said = array_values(array_filter(explode("\n", $result[1]), fn ($line) => stripos($line, 'sonar') !== false && strpos($line, 'task sonar:') !== 0 && strpos($line, 'Executing task sonar:') === false));
        expect("$name: no word about sonar", [], $said);
    }
});

scenario('11. sonar unreachable: the deployment succeeds and says so in a warning', function ($name) {
    fresh();
    deploy();
    commit('Fix the cart total', 'Bob Example');
    push();

    $result = deploy(['SONAR_URL' => 'http://sonar.invalid:8080', 'SONAR_TOKEN' => SONAR_TOKEN, 'SONAR_ALLOW_HTTP' => '1']);

    successAttachments($name, $result);
    expect("$name: warning", true, stripos($result[1], 'sonar could not be told') !== false || stripos($result[1], 'sonar did not take') !== false);
    expect("$name: the token is not printed", false, strpos($result[1], SONAR_TOKEN) !== false);
});

scenario('12. sonar refusing the report: the deployment succeeds', function ($name) {
    fresh();
    deploy();
    commit('Fix the cart total', 'Bob Example');
    push();

    $result = deploy(sonarEnv('/fail'));

    successAttachments($name, $result);
    expect("$name: both reports were tried", 2, count(sonarRequests()));
    expect("$name: warning with the status", true, strpos($result[1], 'HTTP 500') !== false);
});

scenario('13. sonar answering slowly costs 5 seconds per report at most', function ($name) {
    fresh();
    deploy();
    commit('Fix the cart total', 'Bob Example');
    push();

    // the mock answers after 30 seconds: without a timeout the two reports alone would take a minute
    $began = microtime(true);
    $result = deploy(sonarEnv('/slow'));
    $seconds = microtime(true) - $began;

    successAttachments($name, $result);
    expect("$name: deployment took less than 40 seconds ($seconds)", true, $seconds < 40);
    expect("$name: reports arrived", 2, count(sonarRequests()));
});

scenario('14. a rollback is reported as rolled back, with the revision that is live again', function ($name) {
    fresh();
    $first = head();
    deploy();
    commit('Fix the cart total', 'Bob Example');
    push();
    $second = head();
    deploy();

    [$status, $output] = rollback(sonarEnv());
    $requests = sonarRequests();

    expect("$name: dep rollback exit code ($output)", 0, $status);
    expect("$name: number of reports", 1, count($requests));
    expect("$name: status", 'rolled_back', $requests[0]['body']['status'] ?? null);
    expect("$name: revision", $first, $requests[0]['body']['revision'] ?? null);
    expect("$name: previous revision", $second, $requests[0]['body']['previousRevision'] ?? null);
    expect("$name: commits", [], $requests[0]['body']['commits'] ?? null);
});

scenario('15. the commits of a deployment include the merge commit', function ($name) {
    fresh();
    deploy();
    $first = commit('Fix the cart total', 'Bob Example');
    sh('cd ' . WORK_DIR . ' && git checkout -q -b feature/export');
    $second = commit('Export orders as CSV', 'Carol Example');
    sh('cd ' . WORK_DIR . ' && git checkout -q main');
    mergeBranch('feature/export', 'Alice Example');
    $merge = head();
    push();

    successAttachments($name, deploy(sonarEnv()));
    $requests = sonarRequests();

    expect("$name: commits, merge first", [
        ['sha' => $merge, 'subject' => "Merge branch 'feature/export'", 'author' => 'Alice Example'],
        ['sha' => $second, 'subject' => 'Export orders as CSV', 'author' => 'Carol Example'],
        ['sha' => $first, 'subject' => 'Fix the cart total', 'author' => 'Bob Example'],
    ], $requests[0]['body']['commits'] ?? null);
});

scenario('16. the end is reported by a flow without any success task', function ($name) {
    fresh();
    deploy();
    commit('Fix the cart total', 'Bob Example');
    push();

    [$status, $output] = deploy(sonarEnv() + ['E2E_FLOW' => 'nosuccess']);
    $requests = sonarRequests();

    expect("$name: dep deploy exit code ($output)", 0, $status);
    expect("$name: statuses", ['started', 'succeeded'], array_map(fn ($request) => $request['body']['status'] ?? null, $requests));
    expect("$name: same id", $requests[0]['body']['id'] ?? null, $requests[1]['body']['id'] ?? null);
});

scenario('17. a latin1 commit still gives a valid report (git hands the recipe UTF-8, the cleanup of the recipe is the safety net)', function ($name) {
    fresh();
    deploy();
    // the bytes of a latin1 commit must not pass through the PHP of the driver (escapeshellarg drops them): a message file and printf
    global $clock;
    $clock++;
    file_put_contents(WORK_DIR . '/file.txt', "latin1\n", FILE_APPEND);
    file_put_contents(WORK_DIR . '/message.txt', "Caf\xE4 fix\n");
    $date = '@' . $clock . ' +0000';
    sh('cd ' . WORK_DIR . ' && git add -A && GIT_AUTHOR_NAME="$(printf \'Bj\\366rn Example\')" GIT_AUTHOR_EMAIL=b@example.com GIT_COMMITTER_NAME=e2e GIT_COMMITTER_EMAIL=e@example.com GIT_AUTHOR_DATE=' . escapeshellarg($date) . ' GIT_COMMITTER_DATE=' . escapeshellarg($date) . ' git commit -q -F message.txt');
    $latin1 = head();
    push();

    $result = deploy(sonarEnv());
    successAttachments($name, $result);
    $requests = sonarRequests();

    expect("$name: number of reports", 2, count($requests));
    expect("$name: commit", [['sha' => $latin1, 'subject' => 'Cafä fix', 'author' => 'Björn Example']], $requests[0]['body']['commits'] ?? null);
});

scenario('18. quotes, dollar signs, backticks, backslashes and unicode in subjects and authors arrive as they are', function ($name) {
    fresh();
    deploy();
    $subject = 'He said "hi" $HOME `uname` \'x\' \\ ünïcödé ✓ 日本';
    $author = "O'Brien Müller";
    // through files: escapeshellarg drops non-ASCII characters in the locale of some deployer images
    global $clock;
    $clock++;
    file_put_contents(WORK_DIR . '/file.txt', "unicode\n", FILE_APPEND);
    file_put_contents(WORK_DIR . '/message.txt', $subject . "\n");
    file_put_contents(WORK_DIR . '/author.sh', 'export GIT_AUTHOR_NAME="' . $author . '"' . "\n");
    $date = '@' . $clock . ' +0000';
    sh('cd ' . WORK_DIR . ' && git add -A && . ./author.sh && GIT_AUTHOR_EMAIL=o@example.com GIT_COMMITTER_NAME=e2e GIT_COMMITTER_EMAIL=e@example.com GIT_AUTHOR_DATE=' . escapeshellarg($date) . ' GIT_COMMITTER_DATE=' . escapeshellarg($date) . ' git commit -q -F message.txt');
    $hash = head();
    push();

    $result = deploy(sonarEnv());
    successAttachments($name, $result);
    $requests = sonarRequests();

    expect("$name: commit", [['sha' => $hash, 'subject' => $subject, 'author' => $author]], $requests[0]['body']['commits'] ?? null);
});

scenario('19. more than 100 commits are cut to the newest 100', function ($name) {
    fresh();
    deploy();
    $hashes = [];
    for ($i = 1; $i <= 105; $i++) {
        $hashes[$i] = commit("Change $i", 'Bob Example');
    }
    push();

    $result = deploy(sonarEnv());
    successAttachments($name, $result);
    $requests = sonarRequests();

    $expected = [];
    for ($i = 105; $i > 5; $i--) {
        $expected[] = ['sha' => $hashes[$i], 'subject' => "Change $i", 'author' => 'Bob Example'];
    }
    expect("$name: the newest 100", $expected, $requests[0]['body']['commits'] ?? null);
});

scenario('20. the environment is the one that is set, and an invalid one reports nothing but a warning', function ($name) {
    fresh();
    deploy();
    commit('Fix the cart total', 'Bob Example');
    push();

    $result = deploy(sonarEnv() + ['E2E_SONAR_ENV' => 'staging']);
    successAttachments($name, $result);
    expect("$name: explicit environment", ['staging', 'staging'], array_map(fn ($request) => $request['body']['environment'] ?? null, sonarRequests()));

    commit('Add voucher field', 'Alice Example');
    push();
    $result = deploy(sonarEnv() + ['E2E_SONAR_ENV' => 'Not Valid']);
    successAttachments($name, $result);
    expect("$name: nothing reported", [], sonarRequests());
    expect("$name: warning", true, strpos($result[1], 'sonar_environment is not a valid environment name') !== false);
});

scenario('21. a plain http address is refused with a warning and nothing is sent', function ($name) {
    fresh();
    deploy();
    commit('Fix the cart total', 'Bob Example');
    push();

    $result = deploy(['SONAR_URL' => 'http://mock:8080', 'SONAR_TOKEN' => SONAR_TOKEN]);

    successAttachments($name, $result);
    expect("$name: nothing sent", [], sonarRequests());
    expect("$name: warning", true, strpos($result[1], 'has to be an https:// address') !== false);
});

scenario('22. a rollback with only one release reports nothing', function ($name) {
    fresh();
    deploy();

    rollback(sonarEnv());

    expect("$name: nothing reported", [], sonarRequests());
});
