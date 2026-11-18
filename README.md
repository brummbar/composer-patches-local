# composer-patches-local

> [!WARNING]
> This plugin is a proof of concept. It is not ready for production.

A Composer plugin for [cweagans/composer-patches](https://github.com/cweagans/composer-patches) v2.
It makes sure that every patch your project applies is a file in the `patches/` folder at the root of your project.

- The local patches of your dependencies apply.
  Without this plugin, composer-patches looks for these files in your project, not in the dependency.
- The plugin copies all dependency patches, local or remote, into `patches/dependencies/`.
  `composer update` keeps the copies up to date.
- Project patches must be files in `patches/`.
- When the patches of a package change, `composer install` and `composer update` reinstall the package with the new patches.
  You do not need `composer patches-repatch`.
- Packages that declare patches do not need this plugin.

Requirements: PHP 8.3 or later, Composer 2.9 or later.

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
If the setting is missing, the plugin stops and shows the JSON to add.
On a fresh install, composer-patches can stop first, with the error `The "<path>" file could not be downloaded`.

## Project patches

Declare project patches in `extra.patches` or in your patches file (`patches.json`):

```json
{
    "extra": {
        "patches": {
            "drupal/core": {
                "Fix the cache": "patches/core-fix-cache.patch"
            }
        }
    }
}
```

A URL or a path outside `patches/` stops `composer install` and `composer update` with an error.

## Dependency patches

Each copy is in `patches/dependencies/<declaring package>/<patched package>/`.

For example, the `composer.json` of the package `acme/module` declares two patches for `drupal/core`, a local file and a URL:

```json
{
    "extra": {
        "patches": {
            "drupal/core": {
                "Fix the search form": "patches/core-fix-search-form.patch",
                "Fix the menu links": "https://www.drupal.org/files/issues/2026-01-01/3123456-12.patch"
            }
        }
    }
}
```

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

`patches.lock.json` points to the copies. This is the entry for the remote patch (shortened):

```json
{
    "description": "Fix the menu links",
    "url": "patches/dependencies/acme/module/drupal/core/3123456-12.patch",
    "sha256": "…",
    "extra": {
        "provenance": "dependency:acme/module",
        "source": "https://www.drupal.org/files/issues/2026-01-01/3123456-12.patch"
    }
}
```

The plugin keeps only the patch file, the description and the depth of a dependency patch.
It ignores the `extra` data of the dependency, for example `extra.freeform`.
A dependency patch that needs a different patcher does not apply.
Copy such a patch to `patches/` and declare it as a project patch.

If different patches get the same file name in one folder, the plugin puts the first 8 characters of their sha256 hash in front of each name, for example `1a2b3c4d-fix.patch`.

The plugin skips a dependency patch that composer-patches does not apply. This is a patch for a package that is not in `composer.lock`, or for `cweagans/composer-patches` or `cweagans/composer-configurable-plugin`.
It makes no copy and adds no entry to `patches.lock.json`.

A dependency cannot patch a Composer plugin (package types `composer-plugin` and `composer-installer`). Only the root project can.

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

## Daily use

- After `composer update`, commit `composer.lock`, `patches.lock.json` and `patches/` together.
- After you add, change or remove a project patch, run `composer update --lock`.
  Do not use `composer patches-relock`: after it, the next `composer install` rebuilds `patches.lock.json` and downloads the remote dependency patches again.
- If `patches.lock.json` is missing or out of date, `composer install` rebuilds it, like `composer update`.
- Run `composer patches-local-check` in CI, after `composer install`.
  It checks that each project patch and each entry in `patches.lock.json` is a file in `patches/`, and that each file matches its sha256.
  It also checks that a dependency patch in `patches.lock.json` has no other `extra` data.

## Limitations

- Like composer-patches, the plugin reads dependency patches only from the `extra.patches` of each dependency.
  It ignores the patches that your dev dependencies declare.
- If two patches for the same package have the same content, only one applies. A project patch has priority over a dependency patch.
- If two different patch files contain the same fix, composer-patches tries to apply both, and the second one fails.
  Remove your project patch, or ignore the patches of that dependency (see Best practices).
- When the patches of a package change, the plugin reinstalls that package at the end of the run.
  During `composer update`, Composer can then install the package twice.
- Composer writes the autoloader before this reinstall.
  If a patch adds or removes classes and you use a classmap (a `classmap` autoload rule or an optimized autoloader), run `composer dump-autoload`.
- The plugin does not reinstall Composer plugins (package types `composer-plugin` and `composer-installer`).
  When their patches change, it shows a warning. Run `composer reinstall <package>` to apply the new patches.
- `composer update --no-install` does not update `patches.lock.json` or the copies. Run `composer install` after it.

## Best practices

- In CI, run `git diff --exit-code -- patches.lock.json patches/` after `composer install`.
  It fails when the committed `patches.lock.json` is out of date.
- A library that does not commit `composer.lock` can add `patches.lock.json` and `patches/dependencies/` to `.gitignore`.
- Check the changes in `patches/dependencies/` during code review. They show which dependency patches changed.
- Do not change or add files in `patches/dependencies/`. The plugin overwrites the copies and deletes all other files there.
- To ignore all patches that one dependency declares, add it to the composer-patches setting `ignore-dependency-patches`:

  ```json
  {
      "extra": {
          "composer-patches": {
              "ignore-dependency-patches": ["acme/module"]
          }
      }
  }
  ```

- To use your own version of a dependency patch, ignore the patches of that dependency and declare your version as a project patch.
- If you remove this plugin, also remove the `disable-resolvers` entry. Otherwise composer-patches ignores all dependency patches.

## License

EUPL-1.2. See [LICENSE](LICENSE).

## Documentation

- [How it works](docs/how-it-works.md)
- [Development](docs/development.md)
- [Use of the composer-patches API](docs/composer-patches-api.md)
