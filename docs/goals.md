<!-- audience: user -->

# Player goals

A **goal** is something a player is working on — for example "improve weak-foot accuracy" or "be on time for every training". Goals sit alongside the numerical ratings as the narrative side of player development.

## What's on a goal

- The **player** the goal is for.
- A short **title**.
- A **description** with detail, drills or coaching notes.
- A **status** (Not started, In progress, Achieved, Abandoned).
- A **priority** (Low, Medium, High).
- An optional **target date**.

## Adding a goal

1. Open the **Goals** tile.
2. Pick the player.
3. Fill in title, description, status, priority and optionally a target date.
4. Save.

## Tracking progress

Update the status and description over time as the player makes progress. The **Status** filter on the Goals list groups goals into **Active**, **Achieved** and **Missed**, and defaults to Active so the list opens on what's still being worked on. The separate **Archive** filter (Active / Archived, default Active) lets you find archived goals again.

## Who sees what

- Players see their own goals.
- Coaches see goals for the players on the teams they coach.
- Admins see all goals.

## Methodology linkage (v3.79.0)

Goals can now be linked to a methodology principle and/or a single football action from both the public goal form and the wp-admin form. The link is optional but unlocks per-principle reporting on the persona dashboard (the new Goals-by-principle widget shows active and completed goal counts per principle, and a Goals-tagged-to-principle KPI tracks rolling-90-day coverage).

## Player-created goals with approval (v3.79.0)

If your installation grants players the goal-edit cap, a goal created by a player lands with status **Pending Approval**. The player's head coach can approve (status flips to Pending) or reject (Cancelled) via the existing status dropdown. Other coaches cannot approve — only the player's head coach, matching the PDP signoff trust pattern.

## Progress and evidence (#1717)

Each goal can carry a **progress percentage** and **evidence**. On the goal
edit form:

- **Progress (%)** — a 0–100 value the coach sets; it drives the progress bar
  on the player's POP card. Leave it blank to hide the bar.
- **Evidence (evaluations)** — tick the player's evaluations that evidence the
  goal. Each linked evaluation shows on the POP card as a scored chip
  (*Assessment 12 Mar · 6.5*), drawing on the evaluation's date and its
  overall (average-rating) score.

Evidence is stored independently of the goal's methodology links, so the two
don't interfere.

## On the goal detail page (#2218)

Opening a goal shows, alongside the status, priority, target date, owner and
description, three fields that were previously only editable but never
displayed:

- **Progress** — the progress percentage as a bar. A goal with no progress set
  shows a dash (—) rather than a fabricated 0%.
- **Connected principle** — the linked methodology principle, when one is set.
- **Connected football action** — the linked football action, when one is set.

Both the coach view and the player's own goal view show these fields, so a
coach and player see the same picture of where the goal stands and what it
develops.
