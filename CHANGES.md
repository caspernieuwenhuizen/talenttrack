# TalentTrack v4.3.22 — Wizard `submit()` post-create redirect fix (#940 follow-up)

## Symptom

Clicking **Create** on the new-team-blueprint wizard sent the coach to:

```
http://jg4it.mediamaniacs.nl/wp-admin/admin-post.php?tt_view=team-blueprints&id=2
```

instead of the expected dashboard URL with the same query args.

## Root cause — admin-post.php is the request context during submit

Direct consequence of the v4.3.16 / #940 admin-post.php switch. The wizard step `submit()` handlers build their post-create `redirect_url` via `WizardEntryPoint::currentDashboardUrl()`, which reads `$_SERVER['REQUEST_URI']`. During admin-post.php processing the request URI is `/wp-admin/admin-post.php` — the helper returned that path as the dashboard base, and `add_query_arg()` appended the wizard's `tt_view=team-blueprints&id=N` onto it.

`Modules/Wizards/TeamBlueprint/ReviewStep::submit()`:

```php
return [ 'redirect_url' => add_query_arg(
    [ 'tt_view' => 'team-blueprints', 'id' => $id ],
    WizardEntryPoint::currentDashboardUrl()   // ← /wp-admin/admin-post.php during admin-post
) ];
```

The same defect affects every wizard whose `submit()` returns a dashboard-relative `redirect_url` (the new-tournament wizard was on the same code path).

## Fix — per-process dashboard URL override

`WizardEntryPoint::currentDashboardUrl()` now consults a static override before falling back to `REQUEST_URI`. The admin-post handler installs the override (the return URL stripped of wizard-specific query args) before invoking any step methods.

**`WizardEntryPoint.php`** — new override slot:

```php
public static function setRequestContextOverride( ?string $url ): void { ... }
private static ?string $request_context_override = null;

public static function currentDashboardUrl(): string {
    if ( self::$request_context_override !== null ) {
        return self::$request_context_override;
    }
    // … existing REQUEST_URI fallback
}
```

**`FrontendWizardView::handleAdminPostStep()`** — install the override:

```php
WizardEntryPoint::setRequestContextOverride(
    self::dashboardOnly( $return_url )
);
```

New `dashboardOnly()` helper strips wizard-specific query args (`tt_view`, `tt_wizard`, `slug`, `restart`, `dismiss_resume`, `return_to`, `tt_back`) so step handlers see a clean dashboard base.

## What this restores

| Wizard | Before | After |
|---|---|---|
| new-team-blueprint Create | `/wp-admin/admin-post.php?tt_view=team-blueprints&id=N` | `<dashboard>/?tt_view=team-blueprints&id=N` |
| new-tournament Create | same defect | same fix |
| Any future wizard `submit()` returning a dashboard-relative `redirect_url` | bogus base | correct base |

## Validation

- Pilot install: complete the new-team-blueprint wizard → Create → lands on the dashboard's blueprint editor at `?tt_view=team-blueprints&id=N`.
- No regression on wizard step transitions (already-shipped admin-post path).
- No regression on wizards that return absolute `redirect_url` from `submit()` (the override doesn't affect callers that bypass `currentDashboardUrl()`).

## Why patch

Bug fix completing the #940 admin-post switch within the 4.3 minor.

## Bumped

`talenttrack.php` Version + `TT_VERSION` + `readme.txt` Stable tag: `4.3.21` → `4.3.22`.
