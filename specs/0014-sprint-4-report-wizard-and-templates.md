<!-- type: feat -->

# #0014 Sprint 4 — Part B.2: Report wizard + audience templates

## Problem

Sprint 3 gave us a configurable `PlayerReportRenderer` that consumes a `ReportConfig`. But there's no way for a user to *construct* a non-standard config — everything falls back to the legacy "Standard" template.

Three distinct audiences have been identified (shaping from the idea): parent monthly, internal coaches detailed, player personal. Each needs different default sections, different privacy settings, and different visual tone. The shape of the input that differs between audiences is small enough to capture in a four-question wizard.

Who feels it: HoD and coaches. Currently they can only produce the standard report; after this sprint they pick an audience and get a tailored output.

## Proposal

A frontend wizard under the Administration tile group (or a dedicated Reports tile, TBD — decide during implementation). Four steps. Three initial audience templates. Role-gated. Produces a `ReportConfig`, feeds into `PlayerReportRenderer` (from Sprint 3), displays the HTML output with a print button.

Scout flow is **explicitly not in this sprint** — that's Sprint 5.

## Scope

### Wizard structure

View: `src/Shared/Frontend/FrontendReportWizardView.php`.

Four steps, presented as a progressive wizard (one visible at a time, previous steps stored as state). Back/forward navigation allowed.

**Step 1 — Audience.** Radio buttons for four options:
- Parent (monthly summary)
- Internal coaches (detailed)
- Player (personal keepsake)
- Standard (the legacy format — default, preserves existing workflow)

Each option has a one-line description of what it produces.

**Step 2 — Scope.** Time window selection:
- Last month (default for Parent)
- Last season (default for Internal)
- Year-to-date
- All time (default for Internal)
- Custom range (two date inputs)

Sensible defaults per audience pre-selected.

**Step 3 — Sections.** Multi-select of what to include:
- Profile
- Ratings
- Goals
- Sessions
- Attendance
- Coach notes

Defaults vary by audience (Parent: Profile, Ratings summary, Goals, Attendance summary; Internal: all; Player: Profile, Ratings highlights, Goals). User can override.

**Step 4 — Privacy.** Opt-ins, defaults conservative:
- Include contact details — default OFF
- Include full date of birth — default OFF (age shown regardless)
- Include photo — default ON
- Include coach free-text notes — default OFF
- Minimum rating threshold — default 0 (include all). Slider or numeric input.

Each privacy option has tooltip explaining what it does and when it might be appropriate.

After Step 4: "Preview" button generates the report via `PlayerReportRenderer` and displays inline. From the preview, user can:
- Adjust — go back to any previous step.
- Print — browser's print dialog (HTML-print, matches legacy flow).
- Save config as named preset — optional, for reuse. Stored in per-user transient or a small options-table entry.

### Audience templates

Each audience sets different defaults for steps 2–4. Implementations live in `src/Modules/Reports/AudienceDefaults.php`:

```php
public static function defaultsFor(AudienceType $audience): array {
    return match($audience) {
        AudienceType::PARENT_MONTHLY => [
            'scope' => 'last_month',
            'sections' => ['profile', 'ratings', 'goals', 'attendance'],
            'privacy' => ['include_photo' => true, 'include_coach_notes' => false, ...],
            'tone_variant' => 'warm',
        ],
        AudienceType::INTERNAL_DETAILED => [...],
        AudienceType::PLAYER_PERSONAL => [...],
        AudienceType::STANDARD => [...], // matches legacy
    };
}
```

### Tone differentiation in renderer

The `PlayerReportRenderer` from Sprint 3 gets a minor extension: each section's render method respects a `$config->tone_variant` property (new on `ReportConfig`).

Three variants:

- **warm** (parent monthly): "Max's strong areas this month were passing and positioning. He's working on finishing."
- **formal** (internal detailed): "Strengths (last 12 months, rolling-5 avg): Passing 4.2 (↑), Positioning 4.1 (→). Development areas: Finishing 2.8 (↑ from 2.3). 14 evaluations across 6 evaluators."
- **fun** (player personal): "Top attributes this season" with big visual ratings, no weak-spot section.

Each variant is a small set of template snippets per section. Not a full templating engine — just per-section variant methods (`renderRatings_warm()`, `renderRatings_formal()`, `renderRatings_fun()`).

### Role gating

Who can run the wizard, and for which players:

| Role | Can generate for | Audiences allowed |
| --- | --- | --- |
| Head of Development (`tt_head_dev`) | Any player | All four |
| Coach (`tt_coach`) | Players on their teams only | Parent, Player, Internal |
| Player (`tt_player`) | Themselves only | Player personal only |
| Staff, Scout | Not in this sprint | — |

Gating implemented via existing capability patterns. New capability: `tt_generate_report` (granted to head_dev + coach by default; players get it implicitly for their own record).

### Navigation / entry point

From the player edit view (Sprint 3 of #0019): "Generate report" action → opens wizard with player pre-filled.

From a player's profile (Part A of this epic, Sprint 2): HoD/coaches viewing a player they have access to see a "Generate report" button.

From the player's own profile: players see "Generate my own keepsake" button that shortcuts to Step 2 with audience preselected as Player.

## Out of scope

- **Scout audience and emailed scout links.** Sprint 5.
- **Batch/bulk report generation** (e.g. generate parent reports for everyone on U13 at once). Future idea if demand emerges.
- **Custom tone variants beyond the three defined.** Extensible later; v1 has three.
- **Report persistence.** Scout reports will be persisted (Sprint 5); other reports are generate-and-print.
- **Translating report content** (beyond what the plugin already translates via Dutch `.po`).
- **Email-the-report functionality for non-scout audiences.** Parent reports are printed and handed over or PDF'd via the browser.

## Acceptance criteria

### Wizard flow

- [ ] HoD/coach can open the wizard from a player edit view or player profile.
- [ ] Player can open the wizard from their own profile (shortcutted to their own Player-audience report).
- [ ] All four steps render correctly.
- [ ] Defaults per audience are applied when the audience is selected.
- [ ] User can override defaults in steps 2–4.
- [ ] "Preview" generates the report via the existing renderer.
- [ ] "Print" opens the browser print dialog.

### Audience differences visible

- [ ] A Parent Monthly report shows warm prose and omits coach free-text notes by default.
- [ ] An Internal Detailed report shows formal prose with specific numbers.
- [ ] A Player Personal report shows engaging visuals with no weak-spot callouts by default.
- [ ] Standard report is byte-identical to the pre-wizard output (no regression).

### Role gating

- [ ] A coach cannot generate a report for a player not on their team.
- [ ] A player cannot generate a report for another player.
- [ ] HoD can generate for any player with any audience.

### Privacy defaults

- [ ] Contact details, full DOB, coach notes are OFF by default for Parent and Player audiences.
- [ ] Privacy checkboxes change what the preview renders (e.g. toggling "include coach notes" ON in Internal mode makes notes appear in preview).

### No regression

- [ ] Legacy `?print=1` URLs still work (they construct Standard config, produce same output).
- [ ] `PlayerReportRenderer` continues to work for any caller.

## Notes

### Sizing

~14–18 hours. Breakdown:

- Wizard UI (four-step flow, responsive): ~6 hours
- AudienceDefaults + tone variants in renderer: ~4 hours
- Three per-audience template variants (warm/formal/fun) across 6 sections: ~4 hours
- Role gating + entry points from existing views: ~2 hours
- Testing across audience/role combinations: ~2 hours

### Key design decisions from shaping

- **Three initial audiences + standard** (not counting scout). Scout deferred to Sprint 5 because of the privacy complexity.
- **Tone variants are section-method variants**, not a full templating engine. Simpler, easier to maintain.
- **Role gating via capabilities**, consistent with rest of plugin. New `tt_generate_report` cap.

### Depends on

- #0014 Sprint 3 (`ReportConfig` + `PlayerReportRenderer`).
- #0019 Sprint 1 (shared form components for the wizard steps).
- Ideally #0019 Sprint 3 (player edit view as an entry point), though could ship earlier with the entry point deferred.

### Blocks

Sprint 5 of this epic (scout flow builds on the wizard).

### Touches

- `src/Shared/Frontend/FrontendReportWizardView.php` (new)
- `src/Modules/Reports/AudienceDefaults.php` (new)
- `src/Modules/Stats/Admin/PlayerReportRenderer.php` (extend from Sprint 3 — add tone variant methods)
- `includes/REST/Reports_Controller.php` (new or expand — handles wizard-state persistence and preview generation)
- `includes/Activator.php` — register `tt_generate_report` capability
- Player edit view, player profile view — add "Generate report" entry points
