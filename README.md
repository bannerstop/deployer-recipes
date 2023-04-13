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

## License

Licensed under the MIT license.
