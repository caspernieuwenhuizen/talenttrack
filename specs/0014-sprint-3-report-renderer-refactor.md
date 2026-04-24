<!-- type: feat -->

# #0014 Sprint 3 — Part B.1: Generalize `PlayerReportView` into a configurable renderer

## Problem

`PlayerReportView` today is a monolithic class that produces exactly one report shape: rate card + FIFA card + some fixed blocks, A4-printable via `?print=1`. It's good at the one thing it does, but the epic calls for multiple audience-specific report types (parent monthly, scout detailed, player personal, internal). Forking `PlayerReportView` three times would mean three codebases that drift.

The right move is to separate *what goes in the report* from *how the report renders*. The renderer stays one thing; what it includes becomes data. Future sprints (B.2 and B.3) build wizards and flows that produce this data; this sprint lays the plumbing.

Who feels it (immediately): nobody — this is pure plumbing. But every future report feature depends on it.

## Proposal

Introduce a `ReportConfig` value object that captures every decision the wizard will eventually make: audience, scope, sections, privacy settings. Refactor `PlayerReportView` into a `PlayerReportRenderer` that consumes a `ReportConfig` and outputs HTML. The existing `?print=1` flow continues to work by constructing a default "Standard" config under the hood.

**Explicitly: no new user-facing features this sprint.** Nothing visibly changes. The existing reports render identically to before. This is foundation work for B.2.

## Scope

### `ReportConfig` value object

New: `src/Modules/Reports/ReportConfig.php` (or wherever the Reports module lives).

A plain PHP object with typed properties:

```php
final class ReportConfig {
    public AudienceType $audience;      // Enum: parent_monthly|scout_detailed|player_personal|internal_coaches|standard
    public DateRangeSpec $scope;        // From/to dates or a semantic range (last_month, last_season, all_time)
    public array $sections;             // Whitelist of sections to include: profile, ratings, goals, sessions, attendance, coach_notes
    public PrivacySettings $privacy;    // Nested object — see below
    public int $player_id;
    public string $generated_by;        // user ID of the generator
    public \DateTimeImmutable $generated_at;

    public static function standard(int $player_id, int $generated_by): self;  // The legacy default
    public static function fromArray(array $data): self;                        // For wizard construction later
    public function toArray(): array;                                           // For persistence (scout flow Sprint 5)
}
```

`PrivacySettings`:

```php
final class PrivacySettings {
    public bool $include_contact_details;  // Default false
    public bool $include_full_dob;         // Default false (age-only by default)
    public bool $include_photo;            // Default true
    public bool $include_coach_notes;      // Default false
    public float $min_rating_threshold;    // Omit ratings below this. Default 0 (include all).
}
```

### `PlayerReportRenderer` refactor

Rename: `PlayerReportView` → `PlayerReportRenderer`. Keep the old class name as a thin shim that delegates to the new name, so external callers don't break.

New signature:

```php
public function render(ReportConfig $config): string;  // returns HTML
```

Internals:

- Method per section: `renderProfile()`, `renderRatings()`, `renderGoals()`, `renderSessions()`, `renderAttendance()`, `renderCoachNotes()`.
- Each method checks `$config->sections` and `$config->privacy` to decide whether/how to render.
- Shared layout (`renderHeader()`, `renderFooter()`) unchanged from current.

### `?print=1` flow continuity

Surface: wherever the existing `?print=1` URL is handled (likely a REST endpoint or an admin_init action).

- On request, construct a `ReportConfig::standard(...)` equivalent to today's behavior.
- Pass to `PlayerReportRenderer::render(...)`.
- Output the resulting HTML with the existing print-friendly CSS.

Visible behavior: identical to before. The code path is new.

### Section-method implementations

For each of the 6 sections, the refactor pulls existing code out of `PlayerReportView` into its own method:

- **Profile**: photo (respecting `privacy->include_photo`), name, team, age group, DOB (full if `privacy->include_full_dob`, else age only).
- **Ratings**: rolling averages, per-category breakdowns, filtered by `privacy->min_rating_threshold`.
- **Goals**: active + recently-achieved goals.
- **Sessions**: session count, attendance summary.
- **Attendance**: percentages, patterns.
- **Coach notes**: free-text evaluator comments; only rendered if `privacy->include_coach_notes`.

### Tests (if adding)

- Unit tests for `ReportConfig::standard()` returning a config that, when rendered, produces output byte-identical (or semantically-identical) to the legacy `PlayerReportView` output. This is the regression gate.
- The plugin has no test suite today. If introducing tests feels premature, add a documented manual test procedure instead.

## Out of scope

- **Any new audience templates.** Only the "Standard" config for now. Parent, scout, player, internal templates are Sprint 4's scope.
- **Any wizard UI.** Sprint 4.
- **Scout flow.** Sprint 5.
- **Persistence of generated reports.** Sprint 5 (scout reports only).
- **PDF generation.** HTML-print stays the output format.
- **Configurable report sections in the UI.** Sprint 4 exposes section selection; this sprint just prepares the plumbing.

## Acceptance criteria

### Plumbing

- [ ] `ReportConfig` value object exists with all specified properties and factory methods.
- [ ] `PrivacySettings` exists as a nested value object with sensible defaults.
- [ ] `PlayerReportRenderer` exists and consumes a `ReportConfig`.
- [ ] The legacy class name `PlayerReportView` still works (thin shim delegating to the new renderer).

### Continuity

- [ ] `?print=1` URL produces HTML that is semantically identical to the legacy output (same content, same structure).
- [ ] Any existing caller of `PlayerReportView` (scan the codebase) continues to work.
- [ ] No visible change to end users.

### Section isolation

- [ ] Each of the 6 sections has its own render method on the renderer.
- [ ] Each method respects the `$config->sections` whitelist.
- [ ] Each method respects the relevant `$config->privacy` flags.
- [ ] Omitting a section doesn't leave visible artifacts (no empty `<h2>` tags, no "Section not included" text — just absent).

### No regression

- [ ] All existing reports render with the same content as before.
- [ ] No PHP warnings or notices in generated HTML.
- [ ] Print CSS unchanged.

## Notes

### Sizing

~8–10 hours. Breakdown:

- `ReportConfig` + `PrivacySettings` value objects: ~1 hour
- Renderer refactor (pulling sections into methods): ~3 hours
- Legacy class name shim: ~0.5 hour
- `ReportConfig::standard()` equivalent to legacy behavior: ~1 hour
- `?print=1` flow rewire: ~1 hour
- Testing (manual or automated) that legacy output is preserved: ~2 hours
- Documentation: ~0.5 hour

### Why this sprint exists separately

It'd be tempting to fold this into Sprint 4 ("wizard + templates"). But a separate plumbing sprint means:

- Sprint 4 starts with a clean foundation, not a refactor-plus-new-feature combined effort.
- If anything regresses the legacy behavior during the refactor, we catch it here — not entangled with new UX work.
- Sprint 4's estimate stays honest.

### Touches

- `src/Modules/Stats/Admin/PlayerReportView.php` (refactor → rename to `PlayerReportRenderer`, keep shim for old name)
- `src/Modules/Reports/ReportConfig.php` (new)
- `src/Modules/Reports/PrivacySettings.php` (new — or nested inside ReportConfig)
- `src/Modules/Reports/AudienceType.php` (new — enum)
- `src/Modules/Reports/DateRangeSpec.php` (new — value object)
- Any callers of the old `PlayerReportView` class

### Depends on

Nothing hard. This can ship any time after #0015 (the fatal bug fix).

### Blocks

Sprint 4 and Sprint 5 of this epic. Also #0017 (trial player module) needs this to generate admittance/denial letters — so this sprint blocks #0017 entirely.

### Naming note

`PlayerReportRenderer` is proposed. If the existing module structure suggests another name, use that. The point is: "renderer takes a config, produces HTML."
