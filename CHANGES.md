# TalentTrack v2.6.5 — the real fix

## What the v2.6.4 diagnostic revealed

The error message in v2.6.4 said exactly what was happening:

> Migration file returned integer (value: 1) instead of an object. (This often means the file was already included earlier in the request and PHP returned its default success value instead of re-running it.)

And that's the truth. The Kernel calls `( new MigrationRunner() )->run()` on every page boot. That run traverses all pending migrations and `require`'s each file. Later in the same request, when you click Run in the admin page, the runner tries to `require` the same file again — but PHP has already loaded it in this request, so `require` returns `int(1)` instead of the object.

Neither `require`, `include`, `require_once`, `include_once`, nor wrapping in a closure gets around this. It's a fundamental property of PHP's include tracking, which is global per-request.

## The fix

`eval()`. I read the file contents with `file_get_contents`, strip the leading `<?php` tag, and evaluate the code as a string. This gives a fresh execution scope on every call regardless of prior include history.

`eval()` is normally a bad smell — injection risk, debugging pain, etc. But here it's the right tool:

- The input is a plugin-bundled PHP file, not user input. No injection surface.
- Errors (parse errors, fatal errors, exceptions) are caught and surfaced to the admin page.
- We explicitly need fresh evaluation on every call, which is what `eval()` gives us and what `require` actively resists.

## Install

1. Extract ZIP, drop into `/wp-content/plugins/talenttrack/` (overwriting).
2. Commit, push, tag `v2.6.5`, release.
3. TalentTrack → Migrations → click **Run** next to 0004.
4. Expect: green success notice, 0004 flips to ✓ Applied with a duration in ms.

## Why I'm confident this time

The v2.6.4 error message was unambiguous: PHP's include cache was returning `1`. That's a known PHP behavior with a known workaround (`eval`). There's no third layer of silent-skip hiding behind this one — the evaluation happens in-memory, isolated from the file-include tracker.

## Files changed

- `src/Infrastructure/Database/MigrationRunner.php` — eval()-based loader
- `database/migrations/0004_schema_reconciliation.php` — unchanged from v2.6.4 (uses classic extends Migration pattern)
- `talenttrack.php` — version bump
- `readme.txt` — stable tag + changelog

## Everything else is unchanged from v2.6.3/v2.6.4

- Migrations admin page
- MenuExtension with pending-migration warning
- Fail-loud save handlers (v2.6.2)
- Schema reconciliation migration (v2.6.2)
- All modules, dashboards, REST, auth, etc.
