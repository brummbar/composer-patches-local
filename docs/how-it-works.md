# How it works

- At the start of `composer update`, the plugin rebuilds `patches.lock.json`. Local patches of packages in the run are skipped, because their files are not on disk yet. At the end, it rebuilds the lock with all patches.
- A copy of a dependency patch keeps only `extra.provenance` (`dependency:<declaring package>`) and `extra.source` (the declared URL or path). The plugin removes all other `extra` keys of the dependency, so that a dependency cannot control the patcher.
- The resolver skips a target that is not the exact name of a locked package, one of the two packages of composer-patches, and a target with an empty patch list. composer-patches does not apply these patches.
- A dependency patch for a Composer plugin (package types `composer-plugin` and `composer-installer`) stops the run. The lock check also rejects such an entry in `patches.lock.json`.
- Patches with the same content apply only once. The patch of the root project wins.
- Copy names are lowercase. Git on macOS and Windows does not record a change of a file name that is only in case.
- If two different patches get the same name in one folder, both names get an 8-character sha256 prefix.
- The plugin deletes copies that nothing uses. It deletes an empty `patches/dependencies/` too.
- Before the first relock of a run, the plugin takes a snapshot of `patches/dependencies/` (path and sha256 of each file). After the cleanup, it compares the snapshot with the folder and lists the new or changed copies and the deleted copies. The snapshot is a static property of `CopyReport`, so that it survives a plugin update in the run.
- `patches.lock.json` has a top-level key `composer-patches-local`. It is a fingerprint of the patch inputs. Of the locked package names, it contains only the targets of dependency patches.
- On `composer install`, a missing or different fingerprint makes the install rebuild the lock like an update. With `--no-dev`, the install stops with an error instead. If the fingerprint is the same, the install downloads nothing.
- For each patched package, the plugin stores a hash of the applied patches under the key `composer-patches-local` in `vendor/composer/installed.json`.
- After an install or update, the plugin reinstalls the packages whose stored hash differs from the locked patches. This runs at priority 10 on `post-install-cmd` and `post-update-cmd`. This is before Drupal scaffold (priority 0).
- A root script can call a script event by name, for example `"post-install-cmd": "@post-update-cmd"`. Composer then dispatches the second event inside the first event. The plugin ignores the second event.
- The command `patches-local-import` downloads the remote patches of the root project with the downloader of composer-patches. It downloads all patches before it writes a file.
  If a file with other content exists at the target path, the name of the new file starts with the first 8 characters of its sha256. The command does not overwrite a file.
- The command writes each changed file again with the standard formatting of Composer, and changes only the `url` values. If `composer.lock` was current before, the command updates its content hash, because the hash includes `extra`.
- When `composer update` installs a new version of composer-patches or of this plugin, Composer loads the new code under a renamed class, for example `Patches_composer_tmp1`. That class does not extend the original class. Because of this, the plugin also accepts the renamed class of composer-patches, and its own classes use the interface `SkippedPackages` and not the class `Plugin`.
