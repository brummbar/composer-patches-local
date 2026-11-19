# composer-patches-local

> [!WARNING]
> This plugin is a proof of concept. It is not ready for production.

A Composer plugin for [cweagans/composer-patches](https://github.com/cweagans/composer-patches) v2. It makes remote patches local.

The plugin copies each patch that your dependencies declare, both remote and local, into the `patches/` folder of your project.
This way, your project applies these patches from files that you commit and review, not from a URL during an install.

The copies also let the local patches of your dependencies apply. Without this plugin, composer-patches looks for these files in your project, not in the dependency.

## Requirements

- PHP 8.3 or later.
- Composer 2.9 or later.
- cweagans/composer-patches 2. Composer installs it together with this plugin.

## Setup

Add this to your `composer.json`:

```json
{
    "config": {
        "allow-plugins": {
            "cweagans/composer-patches": true,
            "brummbar/composer-patches-local": true
        }
    },
    "extra": {
        "composer-patches": {
            "disable-resolvers": ["\\cweagans\\Composer\\Resolver\\Dependencies"]
        }
    }
}
```

Then run:

```shell
composer require brummbar/composer-patches-local
```

The `disable-resolvers` setting turns off the dependency resolver of composer-patches. This plugin replaces it.

Only the root project requires this plugin. Packages that declare patches do not need it.

Your project patches must be files in the `patches/` folder. Download each remote patch into that folder, and declare its path in `extra.patches` or in your patches file:

```json
{
    "extra": {
        "patches": {
            "drupal/core": [
                {
                    "description": "Fix the cache",
                    "url": "patches/core-fix-cache.patch",
                    "sha256": "…"
                }
            ]
        }
    }
}
```

A URL or a path outside this folder stops the run.

## How it works

The plugin copies each dependency patch to `patches/dependencies/<declaring package>/<patched package>/`.
For example, the package `acme/module` declares a local patch and a remote patch for `drupal/core`.
After `composer update`, your project has these files:

```
patches/
├── core-fix-cache.patch
└── dependencies/
    └── acme/module/
        └── drupal/core/
            ├── 3123456-12.patch
            └── core-fix-search-form.patch
```

`patches.lock.json` points to the copies. For a remote patch, `extra.source` keeps the URL.

- The plugin ignores the `extra` data of a dependency patch, for example `extra.freeform`.
  If a dependency patch needs it, copy the patch to the `patches/` folder and declare it as a project patch.
- Like composer-patches, the plugin skips the patches for a package that is not in `composer.lock`.
- A dependency cannot patch a Composer plugin (package types `composer-plugin` and `composer-installer`). Only the root project can.

## Settings

### Allowed URL schemes

A URL of a dependency patch must have an allowed scheme. By default, these are `http` and `https`.
To change the list, set `allowed-schemes`. Your list replaces the default:

```json
{
    "extra": {
        "composer-patches-local": {
            "allowed-schemes": ["http", "https", "ftp"]
        }
    }
}
```

### Ignore the patches of a dependency

To ignore all patches that one dependency declares, add it to the composer-patches setting `ignore-dependency-patches`:

```json
{
    "extra": {
        "composer-patches": {
            "ignore-dependency-patches": ["acme/module"]
        }
    }
}
```

## Daily use

- After `composer update`, commit `composer.lock`, `patches.lock.json` and `patches/` together.
- After you add, change or remove a project patch, run `composer update --lock`.
  Do not use `composer patches-relock`: after it, the next `composer install` rebuilds `patches.lock.json` and downloads the remote dependency patches again.
- If `patches.lock.json` is missing or out of date, `composer install` rebuilds it, like `composer update`.
  With `--no-dev`, the install stops with an error and does not rebuild it.
- When the patches of a package change, `composer install` and `composer update` reinstall the package with the new patches.
  You do not need `composer patches-repatch`.
- `composer update --no-install` does not update `patches.lock.json` or the copies. Run `composer install` after it.
- Run `composer patches-local-check` in CI, after `composer install`.
  It checks that each project patch and each entry in `patches.lock.json` is a file in the `patches/` folder, and that each file matches its sha256.

## Limitations

- Like composer-patches, the plugin reads dependency patches only from the `extra.patches` of each dependency.
  It ignores the patches that your dev dependencies declare.
- If two patches for the same package have the same content, only one applies. A project patch has priority over a dependency patch.
- When the patches of a package change, the plugin reinstalls that package at the end of the run.
  During `composer update`, Composer can then install the package twice.

## Best practices

- In CI, run `git diff --exit-code -- patches.lock.json patches/` after `composer install`.
  It fails when the committed `patches.lock.json` is out of date.
- A library that does not commit `composer.lock` can add `patches.lock.json` and `patches/dependencies/` to `.gitignore`.
- Check the changes in `patches/dependencies/` during code review. They show which dependency patches changed.
- Do not change or add files in `patches/dependencies/`. The plugin overwrites the copies and deletes all other files there.
- To use your own version of a dependency patch, ignore the patches of that dependency and declare your version as a project patch.
- If you remove this plugin, also remove the `disable-resolvers` entry. Otherwise composer-patches ignores all dependency patches.

## Troubleshooting

### `composer install` stops with `The "<path>" file could not be downloaded`

This can occur on a fresh install, when `vendor/` is empty.
The cause is a missing `disable-resolvers` setting. composer-patches then runs before this plugin, and it looks for the local patch of a dependency in your project, not in the dependency.
Add the setting from Setup.

### A patch does not apply because another patch has the same fix

Two different patch files contain the same fix, for example your project patch and a dependency patch.
composer-patches tries to apply both, and the second one fails.
Remove your project patch, or ignore the patches of that dependency (see Settings).

### The autoloader does not match the classes of a patched package

Composer writes the autoloader before the plugin reinstalls a package with changed patches.
If you use a classmap (a `classmap` autoload rule or an optimized autoloader), run `composer dump-autoload`.

### The patches of a Composer plugin changed, but its code did not

The plugin does not reinstall Composer plugins (package types `composer-plugin` and `composer-installer`). It shows a warning.
Run `composer reinstall <package>` to apply the new patches.

## License

EUPL-1.2. See [LICENSE](LICENSE).

## Documentation

- [Internals](docs/how-it-works.md)
- [Development](docs/development.md)
- [Use of the composer-patches API](docs/composer-patches-api.md)
