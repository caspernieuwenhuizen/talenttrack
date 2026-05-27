# TalentTrack v4.3.14 — Match Prep + Match Execution critical-error fix (closes #955)

## Symptom

Loading either `?tt_view=match-prep&activity_id=<id>` or `?tt_view=match-execution&activity_id=<id>` showed the generic WordPress "There has been a critical error on this website" page. Both surfaces were unreachable since the v3.110.214 / v3.110.216 ships landed them.

## Root cause — PHP visibility narrowing

Both views extend `FrontendViewBase`, which declares `enqueueAssets()` as `protected static`. The two subclasses redeclared the method as `private static`, which PHP rejects at class-load time:

> *Access level to FrontendMatchPrepView::enqueueAssets() must be protected (as in class FrontendViewBase) or weaker.*

`FrontendMatchExecutionView`'s override additionally widened the signature (`(int $activity_id, ?object $execution): void`), making it a double-fault — either alone is fatal.

## Fix

Rename each view's local enqueue to `enqueueViewAssets()` (preserving its per-view signature) so it is no longer an override. Call `parent::enqueueAssets()` first inside `render()` so the shared chrome assets (`tt-frontend-mobile.css`, `tt-table-tools.js`, `tt-frontend-archive-button.js`) load alongside the per-view scoped enqueues. Parent's idempotency guard (`self::$assets_enqueued`) prevents double-enqueue if a future caller also invokes parent.

Two-line change per file:

**`FrontendMatchPrepView.php`** (line 102, 266)

```php
// render():
parent::enqueueAssets();
self::enqueueViewAssets();

// rename:
private static function enqueueViewAssets(): void { /* unchanged body */ }
```

**`FrontendMatchExecutionView.php`** (line 102, 214)

```php
// render():
parent::enqueueAssets();
self::enqueueViewAssets( $activity_id, $execution );

// rename:
private static function enqueueViewAssets( int $activity_id, ?object $execution ): void { /* unchanged body */ }
```

## What this restores

| Surface | Before | After |
|---|---|---|
| `?tt_view=match-prep&activity_id=N` | Critical error page | Renders correctly with shared chrome |
| `?tt_view=match-execution&activity_id=N` | Critical error page | Renders correctly with shared chrome |
| Shared chrome assets on both | Missing | Loaded via `parent::enqueueAssets()` |

## Scope

- One-file-two-line per view. No schema, no migration, no REST contract change.
- `PersonaLandingRenderer` also has a `private static enqueueAssets()` but is a standalone final class with no inheritance — same footgun doesn't bite there. No other `FrontendViewBase` subclasses have this pattern.

## Validation

- Both `?tt_view=match-prep&activity_id=<id>` and `?tt_view=match-execution&activity_id=<id>` render without a critical error.
- Shared chrome assets load on both surfaces (verifiable via DevTools network tab).
- No regressions on other views extending `FrontendViewBase` — parent's `enqueueAssets` untouched.

## Why patch

Bug fix restoring two broken surfaces within the 4.3 minor. No surface change visible on healthy installs (these were 100% broken before).

## Bumped

`talenttrack.php` Version + `TT_VERSION` + `readme.txt` Stable tag: `4.3.13` → `4.3.14`.
