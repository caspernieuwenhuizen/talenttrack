# TalentTrack v4.3.16 — Wizard `name=` 404 fix + admin-post.php switch + CI lint gate (closes #940, supersedes #937)

## The pilot's six issues, one architectural class

Every step transition on the new-tournament wizard 404'd on `jg4it.mediamaniacs.nl`. Same defect lurked in new-team and team-blueprint. The history:

| # | Version | What it fixed | What it missed |
|---|---|---|---|
| #766 | v3.110.172 | Step-transition redirect host validation. | Real bug; correct fix. |
| #782 | v3.110.180 | Step redirect via `home_url($path)`. | Real bug; correct fix. |
| #860 | v4.0.1 | `RecordLink::dashboardUrl()` canonicalisation. | Real bug; correct fix. |
| #901 | v4.2.1 | URL `slug=` query var collision (central helper rename). | Layer 2 + Layer 3. |
| #937 | open | The 5 surviving `slug=` direct writes. | Layer 3. |
| **THIS** | **4.3.16** | **Layer 3** — `name=` POST-body collision + admin-post.php + CI lint covering both classes. Subsumes #937 Layer 2. |

The bug class isn't URL construction — it's *"WP reserved query var names showing up in TT-controlled URLs or form bodies."*

## Root cause

`WP::parse_request()` reads public query vars from `$_POST` **before** `$_GET`. Three wizard step files emitted `<input type="text" name="name" required>` with no `action=` on the form, so POSTing `name=Den Helder` made WP set `query_vars['name'] = 'Den Helder'` → resolve a post with slug `den-helder` → not found → `is_404 = true` → 404 template before `[talenttrack_dashboard]` ran.

The new-activity wizard works because it uses `name="title"` — `title` isn't a public query var. Accidentally fine; not by design.

## Three-layer fix

### Layer 1 — wizard form POSTs go through admin-post.php

`admin-post.php` loads `wp-load.php` but does NOT invoke `WP::main()` for the front-end template path. The public-query-var resolution doesn't run there. Battle-tested by the WP ecosystem for 15+ years; security plugins / hosting WAFs whitelist it.

`FrontendWizardView::render()` is now GET-only. New static method `FrontendWizardView::handleAdminPostStep()` mirrors the legacy POST branch:

1. Verify `tt_wizard_nonce` against `tt_wizard_<slug>_<step>`.
2. Load wizard + state from registry + `WizardState::load()`.
3. Dispatch on `tt_wizard_action`: `cancel` / `back` / `save-as-draft` / `skip` / `next`.
4. Run step's `validate()` + `WizardState::merge()`.
5. `wp_safe_redirect()` back to the wizard URL carried in `tt_wizard_return_url`.

Validation errors carry across the redirect via a 60-second `tt_wizard_err_<uid>_<slug>` transient; nonce/expired errors carry via a `?tt_wizard_error=expired` query param. `render()` surfaces both on the next GET as the same `tt-notice` it always did.

Hook registered in `WizardsModule::boot()`:

```php
add_action( 'admin_post_tt_wizard_step', [ FrontendWizardView::class, 'handleAdminPostStep' ] );
// No admin_post_nopriv — every wizard requires login.
```

Form opens now carry four hidden fields:

```php
<form method="post" action="<admin-post.php>" novalidate>
  <input type="hidden" name="action" value="tt_wizard_step">
  <input type="hidden" name="tt_wizard_slug" value="<slug>">
  <input type="hidden" name="tt_wizard_step" value="<step>">
  <input type="hidden" name="tt_wizard_return_url" value="<step-url>">
  <?php wp_nonce_field(...); ?>
```

### Layer 2 — close the 5 surviving `slug=` URL writes (subsumes #937)

| File | Old | New |
|---|---|---|
| `FrontendTeamBlueprintsView.php` | `add_query_arg(['tt_view'=>'wizard','slug'=>'new-team-blueprint',…])` | `WizardEntryPoint::buildUrl('new-team-blueprint', ['team_id' => …])` |
| `FrontendMatchPrepView.php` | hand-rolled | `WizardEntryPoint::buildUrl('match-prep', ['activity_id' => …])` |
| `FrontendActivitiesManageView.php` | hand-rolled | `WizardEntryPoint::buildUrl('mark-attendance', ['activity_id' => …, 'restart' => 1])` |
| `ParentSearchPickerComponent.php` | `'slug' => 'new-person'` | `WizardEntryPoint::buildUrl('new-person', ['role_hint' => 'parent', …])` |
| `OnboardingPage.php` | hand-rolled, uses `home_url('/')` | `WizardEntryPoint::buildUrl('new-team')` |

`WizardEntryPoint::urlFor()` gains a third `$extra_args` parameter. New sibling `WizardEntryPoint::buildUrl( $wizard_slug, $extra_args = [] )` is the caller-friendly variant when there's no flat-form fallback URL.

### Layer 3 — CI lint gate prevents the class

New workflow `.github/workflows/wizard-form-lint.yml` with two scans:

**Scan A** — fails the build if any `*Step.php` under `src/Modules/Wizards/`, `src/Modules/Tournaments/Wizard/`, `src/Modules/MatchPrep/Wizards/`, or `src/Modules/Vct/Wizard/` declares an `<input|select|textarea>` whose `name=` matches any of ~25 WP-reserved public query vars (`name`, `m`, `p`, `cat`, `s`, `tag`, `feed`, …).

**Scan B** — fails the build if any file outside the allow-list (`WizardEntryPoint`, `FrontendWizardView`, `MfaLoginGuard`) builds a wizard URL via `add_query_arg(['tt_view'=>'wizard',…])`. Forces every wizard URL through the central helper.

Future code that re-introduces either defect fails CI; merge gates on it.

## Defensive field renames

Even though Layer 1 makes them strictly unnecessary, the three wizard steps with `name="name"` are renamed for self-documentation. State keys (`'name'`) unchanged so downstream `submit()` / repository writes don't change.

| File | Field rename | State key |
|---|---|---|
| `Tournaments/Wizard/BasicsStep.php` | `name="name"` → `name="tournament_name"` | stays `'name'` |
| `Wizards/Team/BasicsStep.php` | `name="name"` → `name="team_name"` | stays `'name'` |
| `Wizards/TeamBlueprint/SetupStep.php` | `name="name"` → `name="blueprint_name"` | stays `'name'` |

## Validation

- Pilot install: new-tournament wizard Step 1 → Next → Step 2 renders. Repeat for new-team and team-blueprint. No 404.
- DOM inspection: wizard form action attribute reads `<host>/wp-admin/admin-post.php`.
- `grep -REn "add_query_arg.*'tt_view'\s*=>\s*'wizard'" src/ --include='*.php'` returns matches only in the central helper + `FrontendWizardView::wizardStepUrl` + `MfaLoginGuard`.
- Probe commits that re-introduce a `name="name"` field or a hand-rolled wizard URL fail the new CI gate.

## Why patch

Bug fix restoring three broken wizards on installs that exhibit the `name=` POST-body resolution behaviour, plus architectural-class regression prevention. No surface change visible on healthy installs.

## Bumped

`talenttrack.php` Version + `TT_VERSION` + `readme.txt` Stable tag: `4.3.15` → `4.3.16`.
