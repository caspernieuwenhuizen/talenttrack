# TalentTrack roadmap

What comes next, and what is deliberately waiting. Rebuilt 2026-09-18 at v4.126.0.

**How this file relates to GitHub.** Work in flight lives in GitHub issues and on the [pilot issues board](https://github.com/users/caspernieuwenhuizen/projects/2); this file does not duplicate it. This file holds everything that is *not yet an issue*: candidates worth shaping, small fixes epic audits found and nobody filed, items waiting on a named trigger, and settled non-goals. Every item names the epic it came from, so the reasoning is one click away.

**Keeping it current.**
- When an epic closes, its "out of scope", "deferred" and "reopen when" items move here.
- When an item here is filed as an issue, remove it and let the issue carry it.
- When an item is closed as not planned with a trigger, add it to *Waiting on a trigger* with the trigger quoted.

Shipped history is in `CHANGES.md`. The v3-era status table this file used to carry (to v3.110.40) is in git history at `cb5e948d`.

---

## Now

The open issues. Query: `gh issue list --repo caspernieuwenhuizen/talenttrack --state open`.

| # | Item | State |
| - | - | - |
| 3548 | Match execution: a locked Start button looks enabled and gives no reason on touch | ready-for-dev |
| 3549 | Match execution: show tracked players and bench before kickoff; Start must reveal the live controls | ready-for-dev |
| 3550 | Match execution: confirmation toasts are unreadable (colour token undefined outside the container) | ready-for-dev |
| 3551 | Rebuild this file | in progress |
| 3557 | Tournaments: recording a fixture score erases the fixture's opponent, kickoff time and substitution windows | ready-for-dev, **data loss since v4.126.0** |
| 3558 | Epic: Tournaments tab on the player file. Children #3559 hygiene · #3560 access (migration) · #3561 domain + REST · #3562 tab | shaped, children held |

## Next: candidates to shape

Unblocked, player-facing, and the data already exists. Ordered by how directly they answer *where is this player now, and what do they need next*. Each needs shaping (decisions, mockup, children) before it is queued.

| Order | Candidate | Source | Why now |
| - | - | - | - |
| 1 | **Match analysis per tournament fixture.** | #2704 | Deferred because "it needs per-fixture records first". Those records shipped with #3532. |
| 2 | **From match analysis to evaluations.** "Create match evaluations for the players I flagged." | #2704 | "The obvious next step … file separately." It closes the loop from what the coach saw to what the player's record keeps. |
| 3 | **Reporting on tracked development actions.** | #2292 | The live match sheet records tracked actions; "integration point wired at finalize; dedicated surface later". Nothing reads them yet. |
| 4 | **"Next step" lines carried month to month in the team report.** | #3457 | "Held … needs its own shaping". Makes the monthly report a thread over time rather than a snapshot. |
| 5 | **Media attached to evaluations and PDPs.** | #2589 | "Evaluation/PDP links are v2". Evidence next to the judgement it supports. |
| 6 | **Team statistics for players and parents.** | #3519 | Staff-only by decision; showing a ranked table of named children is "a separate product decision". Decide it before building. |
| 7 | **Historical data import after onboarding** (evaluations, attendance, journey). | #2954 | The REST `/imports` endpoint exists; there is no upload surface outside Setup. A new club's players start with empty journeys. |
| 8 | **Writing `formation`.** Read in eight places, written by nothing. | #3529 | "Belongs on the line-up / prep surface". Small, and it removes the last fixture fact with no form. |
| 9 | **Quick multi-player rating vs deep single-player rating: unify, or state that the split is intentional.** | #2247 | Raised in the evaluation review and never answered. It is a decision, not a build. |

## Hygiene: small, unblocked, never filed

Correctness, privacy and compliance items that epic audits found in passing. Most are one PR each. Privacy items come first because these records belong to minors.

| Item | Source | Where |
| - | - | - |
| **Guest picker loads every active player in the club** into the activity form as JSON. A search-as-you-type endpoint would expose less. | #2009 | `GuestAddModal.php:72` (`cross_team => true`) |
| **Scouts can read guardian contact details** on raw `GET /players/{id}`. | #1723 | "Left as a question, not filed" |
| **No audit event when a parent views sensitive data.** | #1723 | Listed as P2 |
| **Role/scope is not confirmed at invite acceptance** before the auth cookie is set. | #1723 | Listed as P2; #1904 overlaps partly |
| **Views that fetch globally and narrow in PHP.** "One edit away from becoming a leak." | #2009 | `FrontendComparisonView`, `FrontendStandardReportsView` :1768 / :1865, `ReportsRestController` :339 |
| **`club_id` missing on about 13 raw queries**, including `QueryHelpers::get_player()`. No-ops today, cross-tenant leaks the day a second club exists (CLAUDE.md §4). | #2009 | Comparison, TeamsManage `loadTeam`, Blueprints, Chemistry, TrainingRun, ActivitiesManage, ReportDetail, StandardReports |
| Blueprint sibling-team picker checks raw `tt_edit_settings`, so a Head of Development gets an empty list. | #2009 | `FrontendTeamBlueprintsView.php:1256` |
| `tt_team_manager` is mapped by the persona resolver but never installed as a role, so a test using it passes for the wrong reason. | #2589 | `PersonaResolver`, `RoleResolver` vs `RolesService` |
| List rows still carry inline actions (spec 0091): development tracks (delete), ideas board (status select), scheduled reports (pause/resume/archive), custom-CSS snapshot rows (delete). The four exemptions in the spec also have no documented rationale. | `specs/0091` | Re-audited 2026-09-18 |
| Kicking off a tournament fixture creates a type-`match` activity with no `tournament_id`, alongside the optional hand-made `tournament` wrapper. A day can hold both, and the fixtures get the match surfaces #2686 removed from tournaments. | #3558 | `TournamentsRestController` ~780-823 |
| "Player · Minutes played" report sums raw `minutes_played` and ignores `minutes_override`, so it can disagree with `MinutesQuery`. | #3558 | `FrontendStandardReportsView.php:559` |
| `docs/activities.md` still sends coaches to match prep for tournaments, which #2686 removed. | #3558 | `docs/activities.md:245` + Dutch twin |
| Standard reports still reload the page on a filter change. "Its own change, not a flag." | #3335 | 8 sub-reports, 32 early returns |
| The served JS bundle has never been measured against the 50KB gzip budget. | #2453 | `spotlight.js` and the shell bundle |
| Tile components still carry inline `<style>` blocks. | #1695 | Extract into an enqueued sheet (#1389 rule) |
| Design-token pass on the match-executions list stylesheet. | #2292 | `frontend-match-executions.css` |
| Demo-install toggle description still uses the old "training" wording. | #2493 | `FeatureToggleService` |
| Four browser-test flows still missing: activity, evaluation, persona dashboard editor, PDP capture. | `specs/0076` | Ship one per CI-stable batch |
| Process: an epic stays open after its last child merges (#2447, #3519, #3529 all did). | #2447 | Close the tracker in the PR that merges its last child |

## Waiting on a trigger

Deferred on purpose. Each one reopens when its trigger fires, not before.

| Item | Source | Trigger |
| - | - | - |
| Cohort test trends, season over season | #2539 | "A second full season of test rounds is recorded." Reopen as-is. |
| Growth-adjusted test trends; VCT reading the height/weight (PHV) series | #2538, #1854 | Needs sitting height, which nothing records, or years of height readings. |
| Seeded VCT age profiles for U15–U19 | #3109 | "Five numbers per age group" from the methodology owner. Can already be added by hand. |
| VCT defaults sign-off (age-profile defaults, `match_load_multiplier`, 80-exercise catalogue) | #905 | "Becomes a follow-up tweak migration" once signed off. |
| Vision provider shootout (DPIA prerequisite 6) | #2873 | "When handwriting capture is scheduled." Kept deliberately unmet. |
| Import from Tournify | #981 | "If a pilot coach asks for this directly." |
| Own goals by our players, recorded after the match | #3529 | "If pilot feedback asks for it." |
| Ratings grid across activities, one category at a time | #2381 | "Only if pilots ask for it." |
| Per-goal scorer picker on the match page | #3529 | A want, not a gap. It must never become a second writer of the scoreline. |
| Autosave on the attendance, minutes and ratings grids | #2881 | "A decision to reopen deliberately, with the flaky-connection argument answered." |
| Comms: mass announcement (needs `tt_send_announcements`) | #3384 | "Comes back as a new issue with a reason attached." |
| Comms: SMS provider, or document the `tt_comms_sms_send` filter | #3384 | No ruling recorded. Decide it when SMS is asked for. |
| Comms: four templates with no trigger (parent meeting, guest invite, Spond schedule change, letter delivery) | #3384 | Wire each when someone decides the moment it fires, or delete it. |
| Comms: two-way replies, digests, template editor, delivery receipts | #3384 | Separate epic / "No demand" / the per-template switch covers it / needs provider support. |
| Push delivery for alerts (`Surface::PUSH` is declared, nothing uses it) | #2629 | Dropped because it adds a second consent surface and a first-run burst. |
| Alert digest as opt-in rather than on | #2629 | "Only with evidence from the pilot." |
| Strava consent captured on the guardian's side | #2002 | "If legal review later requires it." |
| Hard purge of thread messages | #1784 | "Only if GDPR requires it." |
| Sideline view legibility in sunlight | #2493 | "Needs a real device on a real pitch." |
| Clubs authoring their own courses; course certificates; tool state kept per course (lesson 11's planner renders blank) | #2641 | "A later epic if it is ever wanted." |
| Saved views shared club-wide or per team | #2447 | Demand. "A future `scope` column can be added." |
| Console layout for queue-shaped views (matches to review, trial funnel, evaluation coverage) | #2453 | "Gets its own idea file." None exists yet. |
| Mobile bottom-bar slots chosen from usage data; app shell as the install default | #2453, #2814 | A few weeks of app-shell usage. Default is still `classic`. |
| Bulk restore/purge in the recycle bin; admin UI for the 30-day retention window | #2018 | "Should be filed as its own standalone issue" if wanted. |
| Frontend port of the backup partial-restore scope picker | #1533 | "Warrants its own issue." |
| Tests with more than one value; per-test visibility | #1854 | v1 is one value per test and uniform visibility. |
| Tactical scene animation | #2316 | "Animation is a later increment." |
| Tournament group-stage (poule) standings | #1695 | No data model exists. |
| Scout media grant (resolves to nothing, fails safe) | #2589 | Needs a scout→player scope path (#0017). |
| `s3_backup` Pro gate | #3017 | An object-storage backup destination. A test fails the day one appears. |

## Parked

| Item | Where | Trigger |
| - | - | - |
| Commercialization: provisioning, fleet ops, isolation review, legal pack, billing, demo and pilot path, pitch rewrite, price points | `marketing/commercialization-backlog.md` | Step 1 is choosing a pilot vehicle ("pilot has lost its vehicle" since Free was removed): time-boxed Standard, paid pilot, or a first-season discount. |
| Public knowledge base site | `specs/parked/0043` | More than 30 docs, repeated customer asks for KB search, or marketing wants it for SEO. |
| Per-academy sandbox instance | `specs/parked/0087` | An academy asks for a safe place to train staff. |
| GDPR erasure (right to be forgotten) | Split from #0086 | A customer erasure request. |

## Decided not to build

Settled non-goals, listed so nobody re-proposes them without new information.

| Non-goal | Source | Reason |
| - | - | - |
| Timed opponent goals without the live sheet | #3529 | Their squad is not in the system; a minute typed on Sunday would be invented. |
| Renaming `home_score` / `away_score` to `team_score` / `opponent_score` | #3529 | A data migration plus about 20 readers. The misnomer is documented instead. |
| A report builder with freely rearranged blocks | #3513 | "A different product." |
| Undo across devices, version history, two-editor merging | #2881 | Autosave undo is per session; last write wins. |
| Players and scouts reading training exposure | #2493 | Decision D16. |
| SCORM/xAPI; public or parent-facing courses | #2641 | Courses are for coach development inside the club. |
| Media transcoding, public share links, face detection | #2589 | Photographs of minors. |
| Match analysis shared with parents and players | #2704 | "Decided against for now." |
| Marketing automation; AI-drafted message bodies | #2600 | Comms is the club's own voice. |
| Season-over-season alert history | #2629 | Alerts purge at 90 days; reports own history. |
| Porting the 12 diagnostic and recovery pages out of wp-admin | #2874 | They must work when the frontend is what is broken. |

---

## Shipped since v3.110.40

Epics and trackers closed between 2026-05-09 and v4.126.0. Detail is in `CHANGES.md` and on each issue.

**September 2026:** match result without the live sheet #3529 · team statistics tab #3519 · monthly report v2 #3513 · monthly team report #3457 · attendance completeness #3442 · potential and behaviour: read anywhere? #3385 · comms phase 2 #3384 · setup steps ported to the frontend #3211 · potential and behaviour rating process #3051 · trial module review #3050 · comms as the chokepoint #2600 · PDP conversation #3301 · VCT repeating cycles #3354 · one filtering behaviour #3335

**August 2026:** message templates ship enabled #3049 · LicenseGate call sites #3017 · one save model #2881 · wp-admin pages with no frontend route #2874 · mobile surface review #2814 · coach selector scoping audit #2009 · saved views in the FilterBar #2447 · install-wizard Excel import #2954 · commercialization (parked) #2920 · support documentation #2543 · match analysis #2704 · knowledge library #2641 · alerts #2629 · button labels #2614 · training module #2493 · media library #2589 · DemoData full coverage #2461 · selectable app shell #2453 · desktop grid entry #2381 · reports quality #2342 · selectable methodologies #2316 · evaluation process review #2247 · match execution rebuild #2292 · CrossViewLink gated links #2304

**July 2026:** methodology authoring on the frontend #2206 · archive, recycle bin, purge #2018 · shared filter bars #2017

**May–June 2026:** test and measurement module #2116 · Strava #2002 · performance audit #1649 · authorization matrix migration #1757 · FeatureRegistry sub-features #1538 · configuration port #1533 · chemistry rework #1017 · measurements and testing #1854 · player and parent development hub #1846 · player↔account mapping #1770 · player and parent go-live #1723 · frontend restyle #1695 · player evaluation overhaul #1640 · hard-delete rollout #1784 · safe hard-delete #1782 · report interfaces #1760 · frontend parity with the 2026 mockups #1680 · VCT module #905
