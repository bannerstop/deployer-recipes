<?php

namespace Deployer;

require 'recipe/common.php';
require __DIR__ . '/vendor/bannerstop/deployer-recipes/recipe/rocketchat.php';
require __DIR__ . '/vendor/bannerstop/deployer-recipes/recipe/sonar.php';

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

import(__DIR__ . '/hosts.yml');

before('deploy', 'rocketchat:notify');
after('deploy:success', 'rocketchat:notify:success');
after('deploy:failed', 'rocketchat:notify:failure');

// A deploy that fails after the code is in place, to see the failure reported
task('e2e:break', function () {
    if (getenv('E2E_BREAK') === '1') {
        throw new \RuntimeException('The deployment is broken on purpose.');
    }
});
before('deploy:symlink', 'e2e:break');
