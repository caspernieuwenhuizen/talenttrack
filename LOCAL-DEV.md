# Local development (XAMPP) — quick reference

This file documents the local dev/test/deploy loop on this machine. It is
machine-specific (paths assume XAMPP at `C:\xampp`) and is gitignored — it is
not part of the plugin.

## How the plugin is wired in

The plugin folder in WordPress is a **directory junction** to this repo:

```
C:\xampp\htdocs\wordpress\wp-content\plugins\talenttrack  ->  C:\Users\caspe\Documents\GitHub\talenttrack
```

So WordPress runs the repo files directly. Edit here, refresh the browser —
what you test is exactly what you commit. No copy step, no drift.

Recreate the junction if it is ever lost:

```powershell
New-Item -ItemType Junction `
  -Path   "C:\xampp\htdocs\wordpress\wp-content\plugins\talenttrack" `
  -Target "C:\Users\caspe\Documents\GitHub\talenttrack"
```

## The daily loop

1. Start MySQL + Apache in the XAMPP Control Panel.
2. Edit PHP / JS / CSS in the repo.
3. Refresh the page that hosts the dashboard shortcode:
   `http://localhost/wordpress/talenttrack/` (page ID 58, contains
   `[talenttrack_dashboard]`). You must be logged in for TT content to show.
4. PHP errors are logged (not printed) to
   `C:\xampp\htdocs\wordpress\wp-content\debug.log`. Tail it while developing.

## Toolchain on this machine

| Tool | Location | Notes |
| --- | --- | --- |
| PHP 8.2.12 | `C:\xampp\php\php.exe` | XAMPP's bundled PHP. On PATH in new terminals. |
| Composer | `C:\Users\caspe\bin\composer.cmd` | phar driven by XAMPP PHP. |
| wp-cli | `C:\Users\caspe\bin\wp.cmd` | run as `wp --path="C:\xampp\htdocs\wordpress" <cmd>`. |
| mysql client | `C:\xampp\mysql\bin` | on PATH; needed by `wp db ...`. |

`composer install` was run, so `vendor/` (phpspreadsheet, dompdf, phpstan) is
present. `php.ini` has `gd`, `zip` enabled (required by phpspreadsheet/dompdf).
**Restart Apache after any php.ini change** so the web server picks it up.

## Before you push: run dev-check

```powershell
.\tools\dev-check.ps1          # fast: only files changed vs origin/main
.\tools\dev-check.ps1 -All     # full sweep, like CI
```

It mirrors the gating CI checks you can run locally (PHP lint, PHPStan,
the bin/*-selfcheck.php scripts, .po syntax). The grep-based gates
(legacy-sessions vocab, wizard-form-lint, lookup-translation-lint,
docs-audience, migration-lint, i18n hardcoded-English) run in CI on the PR;
they only fail if a banned token is reintroduced.

## Deploy

The repo auto-publishes a GitHub release on every version bump that lands on
`main` (`.github/workflows/auto-release.yml` reads the `Version:` header in
`talenttrack.php`). Pilot sites pull it via the Plugin Update Checker.

So the deploy path is: finish locally -> bump the version per SemVer
(`DEVOPS.md` "When to bump what") -> open a PR -> merge to main. The release
ZIP builds itself. No manual tagging needed.

## E2E (separate stack)

Playwright tests target a `wp-env` Docker instance (`localhost:8888`), not this
XAMPP site. To run them: `npm install` then `.\tools\dev-check.ps1 -E2E`
(needs `npx wp-env start` first). This is independent of the XAMPP setup above.
