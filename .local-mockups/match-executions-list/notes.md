# Match Executions list — design notes

## Target

Two surfaces in one mockup:

1. **List view** at `?tt_view=match-executions` — the dedicated home for past
   match executions. Coach (own teams) / HoD-Admin (all teams in club).
2. **Hero widget preview** — `MatchesNeedingReviewWidget` rendered as it
   would appear on the coach dashboard (`PersonaLandingRenderer`).

Picker at the top flips between the two. Both share design tokens with the
rest of the mockup family (`match-execution/`, `activity-list/`).

## Why a dedicated surface (not "filter the activity list")

Per #1033: the activity list already serves the "what's happening / what's
next" planning frame. Match executions are a *retrospective* working
surface — coach wants to find the row they need to finalise, scrub the
score / late goals, and lock it. Filtering the activity list by
`type=match & status=finished` would technically work but buries the
review affordance underneath unrelated training-planning chrome.

## List view — anatomy

| Zone | Purpose |
|---|---|
| Breadcrumb | `Dashboard › Match executions` |
| Page head | Title + one-line description |
| Filters | Team select + State select (all / pending review / finalized). State filter mirrors the picker chip so the demo stays in sync. |
| **Needs review bucket** | Orange-accented bucket head, pinned to the top. Rows have a 3px warn-color left border so they stand out in the chronological mix. |
| **Finalized bucket** | Neutral header, chronological. Pagination/limit (cap at ~20 by default per page; "5 of 18 shown" wording). |
| Empty state | "No match executions yet" — surfaces when filters collapse the list to zero. |

Each row card carries: date chip (mute) · opponent + venue + competition
type · team name + final score + outcome (or finish-time hint for
pending) · right-side state pill.

## State pills

- `Pending review`: orange `#fff3d9` background, warn border, leading
  pulse-dot. Reads as "still hot — needs you."
- `Finalized`: grey mute background, neutral border. Reads as "done."

Bucket-head colour echoes the pill so the surface reads top-down as
**warm = needs work / cool = recap**.

## Hero widget — anatomy

Renders only when the coach has at least one `pending_review` execution
on a team they have edit cap for. Default state: orange chrome (matches
the pill in the list). Empty state: grey chrome with a one-liner
("All your match executions are finalised").

Each row: opponent · date · score · age (1d ago / 3 hrs ago) · `Review`
CTA. Footer link `All match executions ›` deeplinks to the list surface.

Widget cap should match the existing dashboard widget rhythm — list 2-3
rows, then collapse to the footer link. Mockup demonstrates 2 rows.

## Open questions for the design pass

- Should the **Needs review** bucket also show finalized matches from
  the last 7 days, so the coach can sanity-check what they locked
  yesterday? (Currently: only pending review here, finalized below.)
- Does the list need an "Open in execution view" affordance separate
  from the row tap? (Tap = open execution view; no second affordance
  needed in v1.)
- Should the widget order be **most recent first** (default in this
  mockup) or **oldest first** (priority: review the OLDEST first so it
  doesn't drift)? Pilot input would help.
- Score column representation: in the list, do we show "2 – 1" with the
  team always on the LHS regardless of home/away, or always home-on-LHS?
  Mockup uses team-on-LHS to keep "our score first" mental model.

## What to test on real device

1. Pill readability at arm's length — does the orange / grey contrast
   read in daylight on a parked-up phone?
2. Tap target depth on the row chips — the whole card is tappable; does
   the visual affordance read?
3. Widget on the coach dashboard — does it feel additive or like noise
   when there's no pending review (use the "All clear" widget demo)?
