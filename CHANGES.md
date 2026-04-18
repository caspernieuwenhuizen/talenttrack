# TalentTrack v2.2.0 — Delivery Changes

## What this ZIP does

This delivery completes **Sprint 0 Phase 2 Part 2: REST envelope**. All REST API
responses now use a standardized `{success, data, errors}` format.

## How to install

1. Unzip this archive.
2. Open the resulting `talenttrack-v2.2.0/` folder.
3. Copy its **contents** (not the folder itself) into your local `talenttrack/`
   repository folder — allow overwrites when prompted.
4. GitHub Desktop will show you the files that changed.
5. Commit with a message like: `v2.2.0 — REST envelope`.
6. Push to origin.
7. On GitHub.com → Releases → create a new release tagged `v2.2.0`.
8. GitHub Actions builds the ZIP automatically and attaches it to the release.
9. WordPress auto-updates within a few hours, or you can force-check in
   **Dashboard → Updates**.

## Files in this delivery

### Modified
- `talenttrack.php` — version bumped to 2.2.0 (header + `TT_VERSION` constant)
- `readme.txt` — stable tag 2.2.0, changelog entry added
- `src/Infrastructure/REST/PlayersRestController.php` — uses envelope + proper
  HTTP status codes
- `src/Infrastructure/REST/EvaluationsRestController.php` — uses envelope +
  proper HTTP status codes

### Added
- `src/Infrastructure/REST/RestResponse.php` — envelope factory
  (`success()`, `error()`, `errors()`)
- `src/Infrastructure/REST/BaseController.php` — shared helpers for REST
  controllers (permissions, validation)

### Unchanged
- Everything else in the plugin — frontend, admin UI, modules, migrations,
  database, etc.

## Breaking change notice

Any external consumer of the REST API must update its response parsing:

**Before (v2.1.0):**
```json
{ "id": 42, "first_name": "Liam", "last_name": "Jansen" }
```

**After (v2.2.0):**
```json
{
    "success": true,
    "data": { "id": 42, "first_name": "Liam", "last_name": "Jansen" },
    "errors": []
}
```

If you're not yet consuming the REST API anywhere, no action needed.

## Post-install verification

In a browser, while logged into WordPress, open:
```
https://your-site.com/wp-json/talenttrack/v1/players
```

The JSON response should start with `"success": true` and contain your players
inside `"data"`. That confirms the envelope is live.
