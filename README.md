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

## Tests

Each major version has an end-to-end harness that drives a real `dep deploy` against a throwaway SSH host
and asserts on the payloads a Rocket.Chat mock receives. It needs Docker and is not part of the installed package.

```shell
tests/deployer6/run.sh
```

## License

Licensed under the MIT license.
