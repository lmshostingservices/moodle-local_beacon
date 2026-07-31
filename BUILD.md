# Build — local_beacon

Frankenstyle component: `local_beacon`
ZIP root folder: **`beacon`** (NOT `local_beacon`)
Source folder on disk: `moodle-plugin/local_beacon/`

## Sync points (update all four every release)
1. `version.php` — `$plugin->version` (YYYYMMDDNNN) + `$plugin->release`
2. `BUILD_INFO.json` — `version` + `numeric_version`
3. `server/routes.ts` — `zipFile:` in `PLUGIN_ZIP_CONFIG['local_beacon']` (folder stays `beacon`)
4. `client/src/lib/pluginConfig.ts` — `version:` + prepend changelog (single quotes only)

## Build (from /tmp, never workspace root)
```bash
rm -f public/downloads/local_beacon_vOLD.zip
BUILD_TMP=/tmp/beacon_build_$$
rm -rf "$BUILD_TMP" && mkdir -p "$BUILD_TMP"
cp -r moodle-plugin/local_beacon "$BUILD_TMP/beacon"      # ZIP root = beacon
cd "$BUILD_TMP"
zip -q -r /tmp/local_beacon_vX.Y.Z.zip beacon -x "*.DS_Store" -x "__MACOSX/*"
mv /tmp/local_beacon_vX.Y.Z.zip /home/runner/workspace/public/downloads/
rm -rf "$BUILD_TMP"
```

## Verify
```bash
python3 -c "import zipfile; z=zipfile.ZipFile('public/downloads/local_beacon_vX.Y.Z.zip'); \
print('Tops:', sorted(set(n.split('/')[0] for n in z.namelist()))); \
print('Bad:', [n for n in z.namelist() if not n.startswith('beacon')] or 'NONE')"
# Tops must be ['beacon']; Bad must be NONE
find moodle-plugin/local_beacon -name '*.php' | xargs -I{} php -l {} 2>&1 | grep -v 'No syntax'
node tools/verify-plugin-versions.cjs --only local_beacon
node tools/check-stale-zips.js
```

## Notes
- AMD modules use **named** `define('local_beacon/ui', ...)` / `define('local_beacon/table', ...)`.
  Keep `amd/src/*.js`, `amd/build/*.js` and `amd/build/*.min.js` identical.
- DB tables: `local_beacon_request`, `local_beacon_snapshot`. `db/upgrade.php` retires the
  obsolete 0.2.x recipe tables on upgrade — keep the savepoint == `$plugin->version`.
- No AI credits, no portal API calls. Reads nothing from `local_aiconfig`.
- Every report query is gated on the table it needs (`is_available()`), so plugins/subsystems
  that are absent simply hide their reports instead of erroring.
