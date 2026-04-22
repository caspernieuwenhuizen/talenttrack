<!-- type: epic -->

# Team planning module — epic

Raw idea:

Think about a Team planning module, n weeks plan; visual calendar based. drag and drop activity types into a preconfigured template with placeholders for the activity, times, connecting to principles. There should also be a roll-up possible across teams for head of development to see a calendar per day, week, month, year to see which activities have been planned and executed.

## Why this is an epic

New module. Needs schema, UI, drag-and-drop library, cross-team aggregation queries, template system. Minimum 3-4 sprints.

## Decomposition (rough)

1. Schema + data model — activity types (lookup), plan templates, scheduled activities per team/date, connections to evaluation categories ("principles"?)
2. Per-team calendar view — week grid, drag-drop activities from sidebar onto slots
3. Templates — create a reusable N-week structure with placeholders, instantiate for a team
4. Executed vs planned — mark an activity as completed, optionally link to a session
5. Roll-up view for Head of Development — all teams on one calendar, filter by day/week/month/year

## Open design questions

- What is a "principle"? The idea mentions "connecting to principles" — this probably means evaluation categories (Technical / Tactical / Physical / Mental) or subcategories. Needs confirming.
- How does this relate to the existing Sessions table? A session is also an activity (training session). Is a session a type of planned activity, or do we keep them separate?
- Drag-drop lib: FullCalendar is the obvious candidate but is ~200KB. Lighter alternatives exist.

## Touches

New module: src/Modules/Planning/
Possibly: src/Modules/Sessions/ (if sessions become a type of planned activity)
