# How it works

- At the start of `composer update`, the plugin rebuilds `patches.lock.json`. Local patches of packages in the run are skipped, because their files are not on disk yet. At the end, it rebuilds the lock with all patches.
- Patches with the same content apply only once. The patch of the root project wins.
- If two different patches get the same name in one folder, both names get an 8-character sha256 prefix.
- The plugin deletes copies that nothing uses. It deletes an empty `patches/dependencies/` too.
- `patches.lock.json` has a top-level key `composer-patches-local`. It is a fingerprint of the patch inputs.
- On `composer install`, a missing or different fingerprint makes the install rebuild the lock like an update. If the fingerprint is the same, the install downloads nothing.
- For each patched package, the plugin stores a hash of the applied patches under the key `composer-patches-local` in `vendor/composer/installed.json`.
- After an install or update, the plugin reinstalls the packages whose stored hash differs from the locked patches. This runs at priority 10 on `post-install-cmd` and `post-update-cmd`. This is before Drupal scaffold (priority 0).
