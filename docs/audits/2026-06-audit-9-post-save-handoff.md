<!-- audience: dev -->

# Audit 9 — Post-save redirect + form-handoff destination plumbing

Closes the audit scoped in #1183. Three families of end-of-flow plumbing
checks: A) compound `data-redirect-after-save` strings vs. the JS
handler in `public.js`; B) wizard handoff via `admin-post.php` does not
regress #969; C) `FormSaveButton::render()` calls honour `BackLink::resolve()`
(CLAUDE.md §6).

---

## A. Compound redirect strings parsed at every consumer

### A.1 Shapes catalogued in PHP

| Shape | Example file | Notes |
|---|---|---|
| `list` | `FrontendTournamentsManageView.php:434`, `FrontendPlayersManageView.php:266`, `FrontendPeopleManageView.php:154`, `FrontendGoalsManageView.php:327`, `FrontendFunctionalRolesView.php:320`, `FrontendActivitiesManageView.php:1290`, `FrontendPdpManageView.php:240` | Bare `list` — strip `action/id/edit`, stay on current `tt_view`. |
| `list:<slug>` | `FrontendScoutingPlanView.php:261` (`list:scouting-visits`) | Sets `tt_view=<slug>` then strips `action/id/edit`. |
| `detail:<entity>:<id>` | `FrontendScoutingPlanView.php:261` (`detail:scouting-visit:<id>`) | Sets `tt_view=<entity>&id=<id>`, strips `action/edit`. |
| `reload` | `FrontendPlayerDetailView.php:998`, `FrontendMyPdpView.php:131,152,166` | `window.location.reload()`. |
| `1` | `FrontendTeamChemistryView.php:579`, `CoachForms.php:108` (eval form) | Same branch as `list`. |
| `dashboard` | `FrontendTestTrainingsView.php:61` | **NOT parsed by `public.js`** — see A.2. |
| `<empty>` + `data-redirect-after-save-url=<URL>` | `FrontendPdpManageView.php:628` | URL fallback branch; parsed correctly. |

### A.2 Findings — shapes used in PHP but not parsed by JS

| File:line | Shape | Symptom | Severity |
|---|---|---|---|
| `src/Shared/Frontend/FrontendTestTrainingsView.php:61` | `data-redirect-after-save="dashboard"` | `public.js:188-248` matches `'1'`, `'list'`, `'list:*'`, `'detail:*'`, `'reload'`, or the URL fallback. `'dashboard'` matches **none** — falls through to the `else` branch and runs `form.reset()`. The new test-training record persists in the DB but the user is left staring at an empty form with no visible state change. Same regression class as #795. | **HIGH** — single producer, single bug, user-visible. |

### A.3 Shapes specified but not used in PHP

`back` is mentioned in the audit spec (#1183) as a potential shape but
no PHP producer emits it today. No bug; not a finding.

---

## B. Wizard handoff via `admin-post.php` (regression check on #969)

`WizardEntryPoint::currentDashboardUrl()`
(`src/Shared/Wizards/WizardEntryPoint.php:158-182`) uses the
`$request_context_override` static when set, falling through to
`home_url($path)` from `REQUEST_URI` only when no override is
installed.

`FrontendWizardView::handleAdminPostStep()`
(`src/Shared/Frontend/FrontendWizardView.php:366-381`) reads the
`tt_wizard_return_url` hidden field from the POST and calls
`WizardEntryPoint::setRequestContextOverride( self::dashboardOnly( $return_url ) )`
before invoking the step. `FrontendWizardView.php:245` emits that
hidden field on every wizard step form pointing at
`self::wizardStepUrl( $slug )` — the canonical
`?tt_view=wizard&slug=…` URL.

**Finding:** None. The #969 fix is in place. Override is installed
before any step `submit()` runs, so wizard redirects continue to
target the dashboard, not `/wp-admin/admin-post.php`.

A small belt-and-braces nit: `currentDashboardUrl()` returns the
override **verbatim**. If a future call path POSTs to admin-post.php
without setting the override (e.g. a new wizard handler bypasses
`handleAdminPostStep`), the bug returns silently. Not worth a fix
today — the boot path is the only call site — but worth a comment
in `setRequestContextOverride()` warning future implementers.

---

## C. Cancel buttons via `BackLink::resolve()` (CLAUDE.md §6)

### C.1 Summary table — every `FormSaveButton::render()` call site

| File:line | Form | `cancel_url` source | Verdict |
|---|---|---|---|
| `FrontendPlayersManageView.php:446` | Player create/edit | `$back['url'] ?? $detail_url/$list_url` via `BackLink::resolve()` | OK |
| `FrontendTeamsManageView.php:213` | Team create/edit | Same | OK |
| `FrontendPeopleManageView.php:205` | Person create/edit | Same | OK |
| `FrontendGoalsManageView.php:456` | Goal create/edit | Same | OK |
| `FrontendActivitiesManageView.php:1622` | Activity create/edit | Same | OK |
| `FrontendTestTrainingsView.php:94` | New test training | Same | OK |
| `CoachForms.php:326` | Coach eval form | Same | OK |
| `FrontendScoutingPlanView.php:319` | Scouting visit | `$back['url']` else `RecordLink::detailUrlFor()` else list | OK |
| `FrontendTournamentMatchAddView.php:176` | Tournament match add | `$resolved_back` from `BackLink::resolve()` | OK |
| `FrontendTournamentsManageView.php:475` | Tournament create/edit | Hardcoded `add_query_arg(['tt_view'=>'tournaments', …])` — does **not** consult `BackLink::resolve()` / `tt_back` | **FIX** |
| `FrontendTeamDetailView.php:559` | VCT defaults card on team detail | Hardcoded `add_query_arg(['tt_view'=>'teams','id'=>$team_id], RecordLink::dashboardUrl())` | **FIX** |
| `FrontendPlayerDetailView.php:1712` | PHV flag panel on player detail | Hardcoded `add_query_arg(['tt_view'=>'players','id'=>$player_id,'tab'=>'profile'], RecordLink::dashboardUrl())` | **FIX** |
| `FrontendFunctionalRolesView.php:249` | Role-type lookup edit | Hardcoded inline-list URL | Documented OK — §6 (b) inline lookup editor, but pattern omits `BackLink::resolve()`. |
| `FrontendFunctionalRolesView.php:365` | Role assignment create | Hardcoded inline-list URL | Documented OK — same. |
| `FrontendCustomFieldsView.php:176` | Inline custom-fields editor | Standalone `<a>` Cancel via `remove_query_arg` | Documented OK — §6 (b) inline lookup. |
| `FrontendEvalCategoriesView.php:192` | Inline eval-categories editor | Standalone `<a>` Cancel via `remove_query_arg` | Documented OK — §6 (b) inline lookup. |
| `FrontendConfigurationView.php:821, 1009` | Lookup-value drawer | Hardcoded `$base` (current lookup-list URL) | Documented OK — §6 (a) settings sub-form / §6 (b) inline lookup. |
| `FrontendConfigurationView.php:1653, 1715, 1743, 1770, 1991` | Branding / theme / rating-scale / menus / default-dashboard | **No `cancel_url`** | Documented OK — §6 (a) settings sub-form exemption. |
| `CoachForms.php:504, 553` | Inline coach activity-save / add-goal sub-forms on multi-form page | **No `cancel_url`** | Documented OK — §6 (a) settings sub-form pattern (multiple forms on one page). |

### C.2 Findings — flag for fix

#### C.2.1 Tournaments create/edit — hardcoded cancel, ignores `tt_back`

`src/Shared/Frontend/FrontendTournamentsManageView.php:430-432`

```php
$cancel_url = $is_edit
    ? esc_url( add_query_arg( [ 'tt_view' => 'tournaments', 'id' => (int) $tournament->id ], remove_query_arg( [ 'action' ] ) ) )
    : esc_url( add_query_arg( [ 'tt_view' => 'tournaments' ], remove_query_arg( [ 'action', 'id' ] ) ) );
```

Cancel goes to the tournaments list / tournament detail unconditionally,
even when the entry point was an upstream surface that captured `tt_back`
(e.g. a tournament prep tile on the coach dashboard, the team detail
"Plan tournament" CTA). Same fix pattern as
`FrontendPlayersManageView.php:444-445` — call `BackLink::resolve()`,
fall back to the hardcoded list/detail when no back-target is present.

#### C.2.2 Team VCT defaults — hardcoded cancel, ignores `tt_back`

`src/Shared/Frontend/FrontendTeamDetailView.php:508-511`

VCT defaults card lives inside team detail. Cancel sends the operator
back to the team detail (correct fallback) but ignores `tt_back` —
when the operator landed here from a workflow task or a deep-link,
Cancel drops them on team detail rather than where they came from.

#### C.2.3 PHV flag panel — hardcoded cancel, ignores `tt_back`

`src/Shared/Frontend/FrontendPlayerDetailView.php:1653-1656`

PHV (Physical / Health / Vitality) flag form on the player profile tab.
Cancel goes to `players?id=…&tab=profile` — sensible fallback but
ignores `tt_back`. A coach who landed here from a workflow task
("Set PHV flag for player X") gets dumped on the player profile rather
than the task list.

#### C.2.4 Functional roles — type + assignment forms

`src/Shared/Frontend/FrontendFunctionalRolesView.php:245-248` (type)
and `:361-364` (assignment).

Type form is genuinely a §6 (b) inline lookup edit — the destination
list URL is the right cancel target by construction. Assignment form
is closer to a record-creation form but ships in the same admin
console; current behaviour (back to the list of assignments) is
correct as a fallback. Both ignore `tt_back`, which **does** matter
for the assignment form because assignments are reachable from
upstream surfaces (people detail, team detail in future). Documented
here but kept off the fix list for now — the priority is the three
above which sit on higher-traffic surfaces. Revisit if a workflow
task ever links into the assignment form.

---

## Summary

- **A.** One shape (`'dashboard'`) used in PHP but not parsed by JS,
  causing a silent form-reset regression on the New test training
  surface. Bug class identical to #795.
- **B.** Wizard handoff via `admin-post.php` correctly honours the
  original `?tt_view=wizard&slug=…` URL via
  `setRequestContextOverride`. #969 stays fixed.
- **C.** Three `FormSaveButton::render()` call sites hardcode
  `cancel_url` and ignore `tt_back`: tournaments create/edit,
  team-detail VCT defaults card, player-detail PHV flag panel.
