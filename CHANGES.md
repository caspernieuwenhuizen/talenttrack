# TalentTrack v2.6.7 — Fix PHP parse error + bundle v2.6.6

## What was wrong

Since v2.6.4, your GitHub Actions release workflow has been failing at the PHP lint step. That's why the 3rd release asset (`talenttrack.zip` built by the workflow) has been missing from the releases page — the build never got past lint.

The CI error was:
```
PHP Parse error: Unclosed '{' on line 158 in src/Infrastructure/Database/MigrationRunner.php on line 318
```

The cause: v2.6.5's MigrationRunner had a `// comment` containing a literal PHP close-tag sequence. PHP's lexer treats that sequence as an actual close tag even inside `//` comments (it's not a bug — it's how the language is specified). Result: the file dropped into HTML mode mid-function, and the closing brace was never found.

This was entirely my mistake, not a PHP version issue or an infrastructure problem. The lint step is doing exactly what it should do — catching bad code before it ships. That you've still been able to install ZIPs is just because `php -l` is stricter than PHP's runtime about the same file.

## What v2.6.7 contains

**The corrected MigrationRunner.php** — removed literal PHP close-tag sequences from comments (and also split the regex to avoid any `?>` sequence in the source code at all, belt-and-suspenders).

**Everything from v2.6.6** that you didn't get to install:
- New Activator with inline schema reconciliation via `dbDelta`
- The authoritative full schema for all TalentTrack tables
- Legacy constraint relaxation (makes v1.x `category_id` and `rating` columns nullable)
- Attendance status backfill from legacy `present` column
- Marks migrations 0001-0004 as applied so the runtime runner has nothing to do

## Install

1. Extract ZIP into `/wp-content/plugins/talenttrack/` (overwriting).
2. Commit, push, tag `v2.6.7`, create release.
3. **Verify CI passes this time.** Go to GitHub → Actions → the v2.6.7 run should be green, and the release page should show 3 assets (2 auto from GitHub + `talenttrack.zip` from the workflow).
4. WordPress admin → Plugins → **Deactivate TalentTrack → Activate TalentTrack.** This triggers the schema reconciliation.
5. Verify with SQL (see CHANGES.md from v2.6.6 for the exact queries).

## Files in this release

### Modified
- `src/Core/Activator.php` — the v2.6.6 schema-reconciling activator
- `src/Infrastructure/Database/MigrationRunner.php` — v2.6.5 code with the parse error fixed
- `talenttrack.php` — version bump
- `readme.txt` — stable tag + changelog

## Everything else unchanged

The usual list — Migrations admin page, MenuExtension, modules, dashboards, REST, auth, custom fields, fail-loud save handlers. All intact.

## What this teaches

PHP's `?>` token closes the PHP block even when embedded in a `//` comment. I knew this abstractly but didn't catch it when writing what was supposed to be descriptive comment text about stripping trailing PHP close tags. The lint step caught it in CI, which is exactly what lint is for. Going forward I'll be more careful about that specific character sequence.

The good news: once this release ships cleanly, the `talenttrack.zip` asset will be attached to the release by your workflow, auto-update should start working (folder name is now correct, asset is present), and you should get a pretty normal release experience. After that we can revisit the automation question you asked earlier.
