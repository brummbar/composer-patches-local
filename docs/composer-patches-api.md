# Use of the composer-patches API

The plugin needed no change to the internals of composer-patches. It uses only public classes and methods.
This note lists what it uses and what the public API could not solve.

## Public API in use

- Capability `ResolverProvider`: `LocalResolverProvider` gives composer-patches a resolver for dependencies.
- Class `ResolverBase`: `DependencyResolver` extends it and uses `findPatchesInJson()`.
- `Patches::getConfig()`, `getLockFile()`, `getLocker()`, `resolvePatches()`, `download()`, and `guessDepth()`.
- `Patches::loadLockedPatches()` and `getPatchCollection()`.
- `Locker::setLockData()`, to write patches.lock.json.
- Classes `Patch` and `PatchCollection`, including `Patch::fromJson()` and the `extra` property.
- The composer-patches setting `disable-resolvers`.

## Points that the public API could not solve

### The lock exists before this plugin is active

On a fresh install, composer-patches starts before this plugin. It creates patches.lock.json at once, with its own resolvers.
Its dependency resolver fails on a local patch of a dependency.

- The root project must turn off that resolver with `disable-resolvers`. The plugin checks the setting and shows the JSON to add.
- The plugin writes a fingerprint into patches.lock.json. An install with a missing or different fingerprint rebuilds the lock. With `--no-dev`, the install stops with an error instead.

### The Locker drops unknown keys

`Locker::setLockData()` writes only the patches and `_hash`. It drops other top-level keys.
There is no event after `composer patches-relock`.

- The plugin writes its fingerprint after its own call of `setLockData()`.
- After `patches-relock`, the fingerprint is gone. The next install rebuilds the lock, or stops with an error with `--no-dev`.

### `getPatchCollection()` before the first load

`getPatchCollection()` has a return type of `?PatchCollection`, but the property has no default value.
Before composer-patches loads patches, the call throws an `\Error`.

- `ComposerPatches::loadedPatches()` catches the `\Error` and returns `null`.

### Wrong provenance in the core Dependencies resolver

The core resolver sets `provenance` to `dependency:<package>`. The variable `<package>` is the target package, not the declaring package.

- The plugin has its own resolver. It sets `extra.provenance` to `dependency:<declaring package>`.
- It stores the declared URL or path in `extra.source`.

### No event for the repatch

composer-patches patches a package only when Composer installs it. It has no command or event to patch a package again.

- The plugin stores a hash of the applied patches in `installed.json`.
- It reinstalls packages whose hash differs from the locked patches. It uses the public Composer installation manager.
