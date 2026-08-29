---
title: Goals
group: performance
summary: Development goals per player with status and priority.
audience: [user]
views: [goals]
module: TT\Modules\Goals\GoalsModule
capability: tt_view_goals
order: 50
---

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

Update the status and description over time as the player makes progress. The **Status** filter on the Goals list groups goals into **Active**, **Achieved** and **Missed**, and defaults to Active so the list opens on what's still being worked on. Archived goals are separate from that: the list opens on unarchived goals, and the **⋯** button at the end of the filter row switches to the archived ones.

## Who sees what

- Players see their own goals.
- Coaches see goals for the players on the teams they coach.
- Admins see all goals.

## On the player's file

The **Goals** tab of a player's file opens on the goals the player is still
working on, in urgency order — nearest target date first, undated goals last.
Goals that have been achieved or abandoned are no longer mixed into that list;
they sit below it under **Completed goals**, closed by default. Open it to see
the player's goal history without leaving the profile.

The number on the tab, the **Active goals** heading and the goals figure in the
player's at-a-glance panel all count the same thing: goals that are neither
archived, achieved nor abandoned. If a goal disappears from the tab's list after
you mark it achieved, it has moved into the collapsed section, not out of the
player's record.

## Methodology linkage

Every screen where a goal is written — the goal form, the quick-add box on the coach dashboard, the new-goal wizard and the wp-admin form — asks **What does this goal develop?** and offers the principles of the club's active methodology. Tick as many as apply: a goal can serve more than one principle, and forcing a single pick would make a coach choose arbitrarily.

The picker is **optional**. A goal without a principle ("be a better team-mate") is still a good goal, and nothing blocks you from saving one. But tagging is what lets the rest of the system aim at the goal: training plans rank exercises by how many of a squad's open development targets they touch, and the per-principle reporting on the persona dashboard (the Goals-by-principle widget and the rolling-90-day Goals-tagged-to-principle KPI) counts only tagged goals.

A goal can also be linked to a single football action, from the goal form and the wp-admin form.

Goals written before the picker existed keep whatever single principle they carried; nothing is guessed or backfilled, so a thin coverage panel is telling you the truth about how many goals have been tagged so far. It fills up as goals are re-authored.

## Player-created goals with approval

If your installation grants players the goal-edit cap, a goal created by a player lands with status **Pending Approval**. The player's head coach can approve (status flips to Pending) or reject (Cancelled) via the existing status dropdown. Other coaches cannot approve — only the player's head coach, matching the PDP signoff trust pattern.

## Progress and evidence

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

## On the goal detail page

Opening a goal shows the status, priority, target date, owner and description,
and three more fields:

- **Progress** — the progress percentage as a bar. A goal with no progress set
 shows a dash (—) rather than a fabricated 0%.
- **Connected principle** — the linked methodology principle, when one is set.
- **Connected football action** — the linked football action, when one is set.

Both the coach view and the player's own goal view show these fields, so a
coach and player see the same picture of where the goal stands and what it
develops.
