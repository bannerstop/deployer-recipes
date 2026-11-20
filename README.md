# bannerstop/deployer-recipes

Collection of custom [deployer.org](https://deployer.org/) recipes.

## Installation

The major version of this package describes with which major version of deployer the recipes are compatible.
Use the release line 6.x when you use deployer 6.x.

```shell
composer require bannerstop/deployer-recipes ^6.0 --dev
```

To include a certain recipe (e.g. RocketChat) in your deployment pipeline:

```php
require 'vendor/bannerstop/deployer-recipes/recipe/rocketchat.php';
```

## Recipes

| Recipe     | Docs                                                     |
|------------|----------------------------------------------------------|
| rocketchat | [read](https://deployer.org/docs/7.x/contrib/rocketchat) |
| sonar      | [see below](#sonar)                                      |

### sonar

Reports every deployment to [sonar](https://sonar.bannerstop.org), so that a Project shows what was deployed when. The recipe hooks
itself into the deployment (the start after `deploy:update_code`, the end after `success` or `deploy:failed`, a rollback after
`rollback`); its header documents the details.

```php
require 'vendor/bannerstop/deployer-recipes/recipe/sonar.php';
```

It is configured by the environment of the deploy, never by files in a repository:

| Variable | Meaning |
|----------|---------|
| `SONAR_URL` | Base URL of the sonar instance |
| `SONAR_TOKEN` | The Pulse token (`sonar_pls_...`) of the Project, shown on its page in sonar |

Without both, the recipe does nothing and says nothing. With them, it sends `environment` (set it with `set('sonar_environment', 'production')`, or
`staging`; the target of the host is only the fallback), the revision, the previous revision and up to 100 commits (sha, subject, author, merge commits included) to
`POST /intake/v1/deployment`; a rollback is reported as a Deployment of its own. It is best effort: every request times out after 5 seconds and an error is only a warning, so a problem at
sonar never breaks a deployment. Needs a sonar release that serves the endpoint.

## Tests

Each major version has an end-to-end harness that drives a real `dep deploy` against a throwaway SSH host
and asserts on the payloads a Rocket.Chat and a sonar mock receive. It needs Docker and is not part of the installed package.

```shell
tests/deployer6/run.sh
```

## License

Licensed under the MIT license.
