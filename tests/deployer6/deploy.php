<?php

namespace Deployer;

require_once __DIR__ . '/vendor/deployer/deployer/recipe/common.php';
require_once __DIR__ . '/vendor/bannerstop/deployer-recipes/recipe/rocketchat.php';
require_once __DIR__ . '/vendor/bannerstop/deployer-recipes/recipe/sonar.php';

set('application', 'acme/app');
set('repository', getenv('E2E_REPOSITORY') ?: 'https://github.com/acme/app.git');
set('branch', 'main');
set('keep_releases', 3);

set('rocketchat_webhook', 'http://mock:8080/hook');
set('rocketchat_channel', 'ci_cd');
set('rocketchat_username', 'deployer');

if (getenv('E2E_COMMITS') === 'off') {
    set('rocketchat_commits', false);
}

inventory('hosts.yml');

// The environment of the report is set by the scenarios that want another one (or an invalid one)
if (getenv('E2E_SONAR_ENV') !== false) {
    set('sonar_environment', getenv('E2E_SONAR_ENV'));
}

// A flow without any success task: the end of the deployment is still reported
task('deploy', array_merge([
    'deploy:prepare',
    'deploy:lock',
    'deploy:release',
    'deploy:update_code',
    'deploy:symlink',
    'deploy:unlock',
    'cleanup',
], getenv('E2E_FLOW') === 'nosuccess' ? [] : ['success']));

before('deploy', 'rocketchat:notify');
before('success', 'rocketchat:notify:success');
after('deploy:failed', 'rocketchat:notify:failure');

// A deploy that fails after the code is in place, to see the failure reported
task('e2e:break', function () {
    if (getenv('E2E_BREAK') === '1') {
        throw new \RuntimeException('The deployment is broken on purpose.');
    }
});
before('deploy:symlink', 'e2e:break');
