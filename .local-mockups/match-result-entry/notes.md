# Match result entry — notes

Open `index.html`. Deep-links: `?v=A&s=manual&w=phone`, `?v=B`, `?v=C`, `?v=base`.

The question this answers: **a match activity that never went through the live match
sheet has no way to record its result at all — and no way at all to record the
opponent's goals.** Should that be a simplified match-execution surface, or woven
into the minutes grid?

**Answer: neither, exactly.** The result belongs to the match record. A **Result
card** on the match's own page is the primary surface; the minutes grid gains two
score rows for bulk catch-up; a second match-execution surface is rejected.

---

## Located causes

| # | Finding | Where |
| --- | --- | --- |
| 1 | `tt_activities.home_score` / `away_score` has **exactly one writer** in the plugin — the end-of-match copy off the execution row. No form, no REST field, no wizard step. | `src/Modules/MatchExecution/Rest/MatchExecutionRestController.php` ~874 (`route_finish`) |
| 2 | The activity create/update payload carries no result or fixture fields. `opponent`, `home_away`, `home_score`, `away_score`, `formation` are simply absent from it. | `src/Infrastructure/REST/ActivitiesRestController.php` 1434–1460 |
| 3 | The activity form's field contract lists five slugs: `title`, `session_date`, `location`, `team_id`, `notes`. Nothing match-specific. | `src/Modules/Configuration/Admin/FormSlugContract.php` 100–108 |
| 4 | `opponent` / `home_away` / `formation` are **read in eight places and written by nothing**: the detail hero + facts strip, match-prep header, match-prep print, team-sheet PDF, week-plan print, the live sheet's score labels, `FrontendMyTeamView`'s fixture line. | grep `home_away` across `src` |
| 5 | The codebase already admits this out loud: *"Form-UI to populate the new columns is a deferred follow-up — for v1 the operator can edit `opponent` / `home_away` / `kickoff_time` / `formation` / `lineup_role` / `position_played` via direct DB write or REST PATCH."* | `src/Modules/Export/Exporters/MatchDayTeamSheetPdfExporter.php` 30–36 |
| 6 | The demo generator does not set `home_away` either, so **no surface has ever been seen with it populated** — not even on a seeded install. | `src/Modules/DemoData/Generators/MatchDayGenerator.php` |
| 7 | The minutes grid's reconciliation footer already wants to print `attributed / score` per match, and degrades to a bare `N attributed` whenever the scoreline is `NULL` — i.e. it is comparing against a number the product gives nobody any way to enter. | `src/Modules/Activities/Frontend/FrontendMinutesGridView.php` 425–480 |
| 8 | Manual **own** goals and **assists** already work without an execution (#3094 / migration 0246). Only the scoreline and the opponent's goals do not. | `MatchExecutionRepository::setContributions()` 364–433 |

### Consequence for work already queued

Everything that consumes a scoreline is empty for a club that does not run the
live sheet: the player's My-team form line (`recentResultsForTeam` filters on
`home_score IS NOT NULL`), the match-analysis header, the grid footer, **#3516**'s
monthly-report match-results block, and **#3520 / #3522**'s team record, form,
GF/GA and clean sheets. #3519 explicitly rules that *"a match with no score
recorded must be listed as played-without-a-score and excluded from W/D/L"* —
which for such a club means the team record is permanently empty. This is a
**prerequisite** for that epic, not a parallel nice-to-have.

---

## The semantic that governs the design

`tt_activities.home_score` is **what we scored**, `away_score` is **what they
scored**, regardless of venue. That is what `route_finish` copies off the
execution row, whose "home" side is `ClubIdentity::shortCode()` and whose "away"
side is `$activity->opponent` (`FrontendMatchExecutionView` 370–381). Migration
0235's docblock states the same convention for the goal-event rows: `team='home'`
means the goal counted **for us**.

So the card is labelled with the club abbreviation and the opponent's name.
**Never "Home" and "Away" against the two boxes** — venue is a separate field.

The column names are therefore misnomers, in the same way migration 0246 flagged
`tt_match_execution_goal_events` as one. Renaming to `team_score` /
`opponent_score` is a data migration plus ~20 readers and is **deliberately out of
scope**; stated here so the next person to notice knows it was seen.

### The latent bug a Home/Away control activates

`ActivitiesRepository::recentResultsForTeam()` (1633–1636) frames the result by
venue:

```php
$is_home       = ( (string) ( $r->home_away ?? '' ) ) !== 'away';
$r->team_score = (int) ( $is_home ? $r->home_score : $r->away_score );
```

The writer never swaps. `home_score` is always ours. Because `home_away` is
currently never populated by anything (finding 4/6), `$is_home` is always true and
the branch never fires — the bug is real but invisible.

**Ship a Home/Away control without fixing this and every away result on the
player's My-team page inverts:** a 1–3 defeat reads as a 3–1 win, on a
player-facing surface. Fix it in the same PR (drop the swap; `team_score` is
`home_score`, full stop) or do not ship the field.

---

## A · Result card on the match page — chosen

`?tt_view=activities&id=N`, a `.tt-act-card-d--span2` in the detail cards grid,
rendered only for game-typed activities. Three states, all in the mockup.

**Typed up afterwards** (no `tt_match_execution` row) — an editable form:

- two score boxes, `type="number"` + `inputmode="numeric"`, 56px tall, `1.75rem`
  digits, labelled with `ClubIdentity::shortCode()` and the opponent abbreviation,
  full team names underneath;
- **Tegenstander** text field and a **Thuis / uit / Neutraal** segmented radio
  group (real radios, 48px labels, keyboard-operable before any script);
- a reconciliation line — *"2 van de 3 doelpunten hebben een naam"* — linking to
  the grid. Information, never a save gate;
- `FormSaveButton::render()` with a `cancel_url`, Cancel-then-Save in DOM order.

**Ran on the live sheet** — a readout, never a second form: the derived score, an
`● uit het wedstrijdformulier` chip, the goal log with minutes, and a line saying
corrections go through the post-match review. #2857's rule holds: *there is no
second place to record a goal.*

**Played, no result yet** — one sentence (*"Nog geen uitslag vastgelegd. Dat is
niet hetzelfde als 0–0."*), one button, and the count of goals that do have a name.

### Why this surface

- The result is a fact about *this match*, and the match record already has the
  columns. Nothing new is stored.
- It is the page the coach already lands on afterwards — *Minuten + statistieken*
  and *Aanwezigheid* both launch from it.
- **One writer per match, decided by the match rather than by the coach.** No
  execution → form. Execution → readout. There is no mode to choose and no way to
  choose wrong. `MinutesGridQuery` already carries the `owned_by_execution` flag
  this switches on.
- It works on a phone, which the grid does not.

### Save model

**Model B — explicit Save with a real Cancel** (CLAUDE.md §6). A known set of four
fields, and a half-committed scoreline is worse than a lost one. Not autosave:
nobody *composes* a result.

### Opponent goals are a count, not events

The opponent's squad is not in the system, so there is nobody to attribute a goal
to, and a minute typed on Sunday evening would be invented — precisely what
migration 0246 refused for our own manual goals. `away_score = 1` is the complete
and honest record of *"they scored one"*. Timed opponent goals stay a live-sheet
capability, and that asymmetry is the design, not a shortfall.

---

## B · Two score rows in the minutes grid — also ships

Two `thead` rows between the date row and the `Min｜G｜A` sub-header, one cell per
match (`colspan="3"` over that match's group), one box each: our goals, their
goals. The existing `tfoot` reconciliation row stays exactly as it is.

- **Nearly free.** Same cell geometry, same save bar, same change counter, same
  Cancel, same endpoint. No new route, no new save model, no new surface, no new
  help topic.
- **Right shape for the real job.** A club catching up on a month enters six
  results and ninety minute-cells in one pass; six visits to six match pages is
  the wrong tool.
- **Still one writer.** A and B PATCH the same column — two views of one field,
  the way minutes are already both typed in the grid and derived from the sheet.
  Two *independent stores* would be the #2857 divergence; two views are not.
- **A live column's scoreline is locked** (🔒 beside the gold `live` badge). The
  asymmetry with minutes is deliberate: minutes on a live column stay editable as
  a correction that survives a recount; the scoreline does not, because correcting
  it means correcting a goal.
- **Empty ≠ 0–0.** The `5 okt` column shows an empty box. A silent zero would make
  #3519's team record wrong, which is worse than an obvious gap.
- Desktop-only, like the rest of the grid (#2381) — which is the second reason A
  exists.

---

## C · A simplified match-execution surface — rejected

- **It is not an execution.** Remove the clock, the halves, the substitution log
  and the state machine and what is left is two number boxes.
- **It needs a mode decision before kick-off.** *"Live sheet or lite sheet?"* is a
  question the coach has to answer in advance and will get wrong at least once.
  The Result card asks nothing.
- **Two writers, one field** — the divergence #2857 removed, re-introduced.
- **The scorer list already exists** and is better: the grid's `G`/`A` columns do a
  whole period at once, with reversal semantics and reconciliation.
- **It costs a route, a slug, a help topic, a `FeatureRegistry` entry and a
  capability** for a payload of two integers.

A per-goal scorer picker *on the match page* is a reasonable separate want — a
nicer attribution surface. It must not become a second writer of the scoreline.
Its own issue if pilot feedback asks.

---

## Out of scope, stated deliberately

- **Post-hoc own goals.** An own goal by one of our players counts for the
  opponent and *is* attributable (`team='away'`, `player_id>0`, `is_own_goal=1`).
  The grid's `G` column excludes own goals by design, so there is no manual entry
  for one. The scoreline records the goal either way; only the attribution is
  missing. Own issue.
- **Renaming `home_score` / `away_score`** to `team_score` / `opponent_score`.
- **Timed opponent goals without the live sheet** — refused on principle above.
- **`formation`** — the third fixture fact with no form. It belongs on the
  line-up/prep surface, not on a Result card.

---

## Port acceptance

- [ ] Renders at 360px with no horizontal scroll; the two score boxes stay on one
      row.
- [ ] Both score inputs carry `type="number"` **and** `inputmode="numeric"`, and
      `font-size` ≥ 16px so iOS does not zoom on focus.
- [ ] Segmented Home/Away is real `<input type="radio">` inside `<label>`, 48px
      tall, keyboard-operable with no script.
- [ ] `FormSaveButton::render()` with `cancel_url`; Cancel-then-Save in DOM order;
      `tt_back` overrides the cancel target (`BackLink::resolveBack()`).
- [ ] An execution-owned match renders the readout and offers **no** score input on
      either surface.
- [ ] An empty scoreline persists as `NULL`, never `0`. Test that it does.
- [ ] `PATCH /activities/{id}` accepts a **partial** update — a scoreline write
      must not blank `opponent`, and vice versa. Test that an omitted field is left
      alone (`tests/php/AutosaveWriteContractTest.php` is the pattern).
- [ ] Business logic (which state the card is in, what the reconciliation says) is
      in the domain layer, not the view — the REST payload gives the same answers
      (CLAUDE.md §4).
- [ ] `recentResultsForTeam()`'s venue swap is removed in the same PR.
- [ ] No inline `style="…"` added in `src` PHP (#1389 gate) — the mockup's inline
      styles are mockup chrome only.
- [ ] Styles go in `assets/css/frontend-activities-manage.css` (`.tt-act-result__*`)
      and `assets/css/frontend-minutes-grid.css` (`.tt-agrid-score*`), reading
      `tokens.css`, no raw hex.

## Copy — EN msgid → NL msgstr

| msgid | msgstr |
| --- | --- |
| `Result` | `Uitslag` |
| `Opponent` | `Tegenstander` |
| `Home / Away` | `Thuis / uit` |
| `Home` | `Thuis` |
| `Away` | `Uit` |
| `Neutral ground` | `Neutraal terrein` |
| `Save result` | `Uitslag opslaan` |
| `Record result` | `Uitslag invoeren` |
| `No result recorded yet. That is not the same as 0–0.` | `Nog geen uitslag vastgelegd. Dat is niet hetzelfde als 0–0.` |
| `From the match sheet` | `Uit het wedstrijdformulier` |
| `Open post-match review` | `Nabespreking openen` |
| `Own goal` | `Eigen doelpunt` |
| `Goal against` | `Doelpunt tegen` |

`Result` is short enough to inherit the wrong Dutch sense (cf. `Pass` →
`Geslaagd`) — use `_x( 'Result', 'the score of a match', 'talenttrack' )` and read
the rendered Dutch. Same for `Home` / `Away`, which must not pick up the
`Thuispagina` sense.
