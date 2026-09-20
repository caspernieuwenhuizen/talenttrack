# Team monthly report — notes

A head coach needs one document per team per month, printable to PDF and readable
online, that carries the monthly staff meeting: where the squad is, who needs a
conversation, and what changed.

`index.html` is a working composition surface: pick one of three report types,
tick the blocks you want, and the paper reflows live with a per-page fit meter.
Investigated 2026-09-16 on `main` @ `ebfaaff0`. Decision 2026-09-16: **ship all
three layouts, selectable at generation, with per-block selection.**

---

## The three findings that shape everything

### 1. Almost every number already has a query. This is assembly, not data mining.

| Block | Source | State |
| --- | --- | --- |
| Attendance per player, team average, at-risk list | `AttendanceRankingQuery::rows()` / `::atRisk()` — takes `from`, `to`, `team_id` | **ready** |
| Minutes per player, match counts, available minutes | `MinutesQuery::forTeam()` / `::matchCountsForTeam()` — same signature | **ready** |
| Green / amber / red per player, with `coverage` and `missing_inputs` | `PlayerStatusCalculator` → `StatusVerdict` | ready per player; **squad roll-up is new** |
| Injuries open / started / ended | `InjuryRepository::listForTeams()` | **ready** |
| Test results and movement vs the previous session | `TestTrendsQuery::forDefinition()`, `MeasurementSessionsRepository` | **ready** |
| Evaluation coverage | `EvalCoverageService::coverage()` | ready, but **season-window scoped, not month** |
| Squad average rating, average attendance | `TeamKpisRepository::avgSquadRating()` / `::avgAttendance()` | ready, but **`days`-based, needs a `from`/`to` variant** |
| "What changed" — injuries, position changes, team changes, PDP verdicts | `JourneyEventType` + the journey tables | rows exist; **no team + window query** |
| Open goals per player | `GoalsRepository` | **no team + window roll-up** |
| Register completeness (the coverage banner) | `ActivityRegisterProgress` | **proposed in #3446 / #3447, not built** |

The new code is a composer service (`TeamMonthlyReport`) calling existing queries
with one `from` / `to`, four small window-scoped variants of methods that
currently take `days`, and the exporter + view. Smaller than the scope suggests.

### 2. The monthly delivery rail already exists — and it sends the wrong thing

`ScheduledReportFrequency` already has `MONTHLY_FIRST`
(`src/Domain/Vocabularies/Lookups/ScheduledReportFrequency.php:37`), and
`ScheduledReportsRunner` is a working daily cron that renders a schedule and
mails it through Comms with the file attached. What it renders is
`CsvExporter::forKpi()` — a KPI CSV, which nobody opens in a staff meeting.

Teaching that runner to render this report as a PDF attachment is an extension
of a shipped rail. **This is also what forces the composition to be stored data
rather than UI state** — see below.

### 3. The PDF renderer is DomPDF 3.0 — no flexbox, no CSS grid

`composer.json` pins `dompdf/dompdf: ^3.0`, and `PdfRenderer` feeds it an HTML
string. DomPDF implements CSS 2.1: **tables, floats and `inline-block`. Flexbox
and CSS Grid render as plain blocks**, so a layout authored with `display:grid`
looks right in the browser and silently collapses to one column in the PDF.

Every paper layout in `index.html` is built on `<table>` on purpose — the KPI
strip, the two-column pairs, the bars (a `<div>` with a percentage width inside
a table cell, which DomPDF renders correctly). **Do not "clean this up" into
grid during the port.** The composition panel and the online view are
browser-only and use modern CSS freely.

Corollary: no charting library. Bars, a stacked status band and tables are the
entire visual vocabulary, and they are enough.

---

## Composition — the decision taken on 2026-09-16

All three layouts ship. The report type is chosen at generation, and the blocks
are individually selectable. Three consequences worth stating explicitly,
because they are where the work actually is.

### The composition is stored, not UI state

If block selection were ephemeral toggles on the online view, the monthly
schedule could not render it — the cron has no user and no session. So a
composition is a **saved object**:

```json
{ "layout": "B", "blocks": ["letterhead","coverage","kpi","status",
                            "attendance","minutes","attention","changes",
                            "tests","roster","notes","quality"] }
```

`SavedViewsRepository` is the right home. It already stores personal named
presets per surface with an opaque `filters_json` and a per-user **default**
(`findDefault()`), which is exactly "the config this coach uses every month".
Register `report-team-monthly` in `SavedViewsRegistry`.

**One caveat that must not be missed:** saved views are *personal, never shared*
(`SavedViewsRepository` docblock). A scheduled report runs unattended, so the
schedule row must store **its own copy** of the composition rather than pointing
at a preset id — otherwise the schedule breaks the day the coach renames or
deletes their preset, and silently mails a different document.

### Blocks are not uniformly available

`roster` (the per-player table) cannot be offered on the one-pager at full size.
Rather than grey it out, the one-pager offers a **compressed** roster — 8 columns
instead of 12, no per-match detail — and the panel says so. The landscape matrix
is the inverse: the roster *is* the page, so deselecting it there leaves a
mostly-empty sheet. The panel drives this from a per-layout availability map;
`letterhead` is the only permanently locked block.

### Selection overflows pages, so the panel has to say so

Arbitrary blocks × three layouts means a selection that does not fit. The panel
carries a live per-page fill meter, and the mockup measures the **actually
rendered** height against the printable box rather than estimating from a table
of heights — the sheets render at true mm, so the number is real.

The one-pager degrades before it fails, in this order:

1. Ranked lists (attendance, minutes) elide their middle, keeping the top 3 and
   bottom 4 with a "… 7 players between 75% and 94% …" row.
2. The attention list drops to the two most urgent cards.
3. Only then does the panel say *"does not fit — drop a block, or switch to the
   three-page pack."*

That ordering is a product decision, not a technical one: the extremes of a
ranked list are what a meeting discusses, so the middle is what can be spent.

### Where the panel lives

On the report view itself, above the report, sticky — not behind a separate
"generate" step. Picking blocks and seeing the paper reflow is the whole point;
a pre-generation form asks a coach to predict what they are about to get.

Open question against CLAUDE.md §3: a settings panel with more than five fields
*should* ship as a wizard. This panel has fifteen controls, so the letter of the
rule points at a wizard — but it is a live preview surface, not a multi-step
flow, and stepping it would destroy the feedback loop that makes it work. **Read
as an exemption; needs a `Wizard plan: exemption` line in the spec.**

---

## Proposed contents

Ordered so the first thing on the page is the thing the meeting is about.

1. **Letterhead** — crest, team, month, head coach, meeting date, generated
   timestamp, squad size, activity count. *Always on.*
2. **Coverage banner** — *"based on 16 of 17 completed activities"*. See below.
3. **KPI strip (6)** — activities, attendance %, median minutes share, evaluated
   n/N, squad rating, players needing attention. Each with a delta against the
   previous month; a monthly report with no previous month is a snapshot.
4. **Squad status band** — `StatusVerdict` colours as one stacked bar, with last
   month's split underneath.
5. **Attendance per player** — ranked bars. Amber below 70%, red below 60%.
6. **Minutes share per player** — ranked bars against the academy target (dashed
   line at 50%). Denominator is the minutes available in the matches whose
   minutes were actually recorded.
7. **Needs a conversation** — amber and red players, each with *what the data
   says* and *what they need next*. The agenda; everything above is evidence.
8. **What changed** — journey events: injuries, position changes, team moves,
   PDP verdicts, signings, releases.
9. **Tests** — the session held, who was tested, movers in both directions.
10. **Player by player** — one row per player, every measure plus a note.
11. **Decisions and actions** — ruled blank lines. A meeting document that
    cannot be written on is a handout.
12. **Data quality** — what is missing and needs fixing before next month.

### Deliberately absent

- **Per-player evaluation detail** — that is `PlayerOnePagerPdfExporter` and the
  evaluation report. This report points at players; it does not reproduce them.
- **Match results and league position** — the meeting is about development, not
  results (CLAUDE.md §1). Match *count* and minutes are in; scores are not.
- **Coach and staff statistics** — `coach-evaluation-quality` and the learning
  reports already answer a different question for a different meeting.

### Why the coverage banner is load-bearing

This report multiplies its inputs. An activity that completed with no attendance
recorded does not appear as a gap — it silently lowers the denominator and every
figure shifts. That is the failure #3442 documents, which is why the banner sits
*above* the KPI strip rather than in a footnote.

It is also the incentive loop: "1 of 17 activities has no register" read out in
front of the whole staff gets that register filled in a way an alert badge never
does. `ActivityRegisterProgress` (#3446 / #3447) is its data source, so the two
pieces of work want each other.

It is selectable, but it should stay on: a report that quietly drops its own
confidence statement is worse than one without the banner at all. The panel
marks it accordingly.

---

## The three layouts

### A · One-pager (A4 portrait, 1 page)
Everything on one sheet, degrading as described above. Roster available in
compressed form. **For** a squad of ~14 and a 30-minute meeting where everyone
gets a copy.

### B · Three-page pack (A4 portrait, up to 3 pages)
Page 1 dashboard, page 2 roster, page 3 attention + changes + tests + notes +
data quality. Deselecting `roster` removes page 2 entirely and the pack becomes
two pages. **For** the default staff meeting: every player appears twice, once
as a bar and once as a row, which is how a discussion moves.

### C · Landscape matrix (A4 landscape, 1 page)
One wide row per player carrying both bars inline plus every number and a note
column, with changes / tests / notes in a footer strip. **For** the densest
single sheet; the whole squad comparable in one read. Full at 14 players — a
squad of 20 will overflow, and the meter will say so.

---

## Port acceptance

- [ ] Reachable at `?tt_view=standard-report&slug=team-monthly&team_id=N&month=YYYY-MM`,
      registered in the launcher beside the other team reports, gated the same
      way (`tt_view_reports` + per-report `FeatureRegistry` toggle + team scope).
- [ ] Composition round-trips through the URL (`&layout=B&blocks=kpi,status,…`)
      so a generated report is a shareable link, and the PDF export reuses the
      same parameters rather than a second code path.
- [ ] Preset stored via `SavedViewsRepository` under `report-team-monthly`,
      registered in `SavedViewsRegistry`; `findDefault()` drives the initial
      state so a returning coach gets their usual config.
- [ ] **A schedule stores its own copy of the composition**, never a preset id.
      Test: delete the preset, the schedule still renders the same document.
- [ ] All composition in a `TeamMonthlyReport` domain service — the view, the
      PDF exporter and the scheduled runner all call it and none of them
      computes anything (CLAUDE.md §4).
- [ ] Block selection is applied in the **composer**, not by hiding rendered
      HTML. A deselected block must not be queried at all, or a coach who only
      wants the KPI strip still pays for the roster query.
- [ ] Exposed on REST as `GET /teams/{id}/monthly-report?month=YYYY-MM&layout=&blocks=`.
- [ ] PDF exporter registers as `team_monthly_report_pdf` alongside the other
      four PDFs, reusing the `PdfRenderer` pipeline.
- [ ] **Paper layouts use tables / floats only.** No `display:grid`, no
      `display:flex` in the PDF HTML. Verify by rendering a real PDF, not by
      looking at the browser.
- [ ] Overflow is handled server-side too: DomPDF will happily push content onto
      a fourth page. The composer applies the same degradation ladder the panel
      previews, and the panel's page count must match what the PDF produces.
- [ ] Every figure links to its source when read online — attendance to the
      attendance report, minutes to the minutes report, a name to the player.
- [ ] Deltas versus the previous month; no predecessor shows "—", not "0%".
- [ ] Coverage banner renders in a green "complete" state rather than vanishing
      when nothing is missing — absence of a warning must be positive evidence.
- [ ] Empty states: a team with no completed activities renders the letterhead
      and one sentence, not a page of zeroes.
- [ ] Confidentiality footer on every page. These are minors (CLAUDE.md §1) and
      this document leaves the building.
- [ ] Composition panel meets the mobile contract: 48px targets, keyboard
      reachable, renders at 360px. The paper preview may scroll horizontally
      inside its own container; the panel may not.
- [ ] Dutch throughout. Month names via `wp_date()`, never hardcoded.

## Open questions

1. **Who may generate it, and for which teams?** Head coach for their own teams
   is the floor. HoD across all teams implies an "all squads" variant, which is
   a different document.
2. **Does it go to parents?** Nothing here is safe to send home unedited — the
   attention list names children and says why. Assume staff-only unless
   explicitly decided.
3. **Fixed calendar month, or a rolling window?** The mockup assumes a calendar
   month because meetings are monthly. A four-week window aligns better with
   training blocks and the VCT cycles (#3354).
4. **Does the attention list write back?** The "next step" lines are the real
   output of the meeting. If they were editable and stored, next month's report
   could open with *what we agreed last month, and whether it happened* — a
   considerably more valuable document, and a considerably larger build.
5. **Can the operator set an academy-wide default composition?** A club that
   wants every squad reported the same way needs one, and it is the natural home
   for "the coverage banner is mandatory here".
