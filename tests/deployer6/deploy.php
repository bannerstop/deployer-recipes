<?php

namespace Deployer;

require_once __DIR__ . '/vendor/deployer/deployer/recipe/common.php';
require_once __DIR__ . '/vendor/bannerstop/deployer-recipes/recipe/rocketchat.php';

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

task('deploy', [
    'deploy:prepare',
    'deploy:lock',
    'deploy:release',
    'deploy:update_code',
    'deploy:symlink',
    'deploy:unlock',
    'cleanup',
    'success',
]);

before('deploy', 'rocketchat:notify');
before('success', 'rocketchat:notify:success');
after('deploy:failed', 'rocketchat:notify:failure');
