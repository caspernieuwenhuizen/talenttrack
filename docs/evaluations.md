---
title: Evaluations
group: performance
summary: Record player ratings with scores, notes, and categories.
audience: [user]
views: [evaluations]
module: TT\Modules\Evaluations\EvaluationsModule
capability: tt_view_evaluations
order: 10
---

# Evaluations

An **evaluation** is your rating of a player on a specific date, across the categories the academy uses (typically Technical, Tactical, Physical, Mental).

## Recording an evaluation

1. Open the **Evaluations** tile.
2. Pick the player.
3. Pick an evaluation type (e.g. Training, Match). When you pick **Training**, the **Mental** category moves to the top of the rating list and opens its detailed subcategories automatically — a prompt to look at mindset first. It is only a default: you can still rate every category and you are never required to enter a Mental rating to save.
4. Pick the date.
5. Give each main category a **star rating** from one to five. The stars carry qualitative labels — ★ Insufficient, ★★ Poor, ★★★ Average, ★★★★ Good, ★★★★★ Excellent — and are stored on the academy's 5–9 scale.
6. If you want to be more precise, drill into the subcategories — your main rating becomes the rounded average of the subcategory stars you set.
7. Add a note about what you saw. This **Internal notes (staff only)** field is for staff only — the player never sees it.
8. Optionally, add **Feedback for the player**. Unlike Notes, this field *is* shown to the player (and their parents) on their own evaluations screen — use it to tell them what they did well and what to work on next. Leave it blank if you have nothing to share.
9. If the type is a match, fill in opponent, competition, result, home/away and minutes played.
10. Save.

## Editing an evaluation saves itself

Recording a new evaluation ends with **Save**, as above. **Editing** an
existing one does not: it saves as you write, and a status line where the
Save button used to be says where it got to — *Unsaved changes…*, *Saving…*,
*All changes saved*.

Beside it are the two ways back that come with every autosaving screen in
TalentTrack: **Undo** takes back the last saved change, and **Revert
changes** puts the form back to how it was when you opened it, after asking
first. Both are described in full under *Save behaviour* on the
[match preparation](match-prep.md) page.

There is no Cancel on the edit form any more, because there is nothing
uncommitted to cancel. Creating still needs Save, deliberately — nothing
should be able to leave an empty evaluation on a player's file just because
you opened the form and thought better of it.

**One thing worth knowing about the feedback field.** Because the edit form
saves as you write, a **Feedback for the player** message becomes visible to
the player and their parents from the moment it settles — not when you press
a button. If you want to draft the wording before anyone reads it, write it
somewhere else first, or write it in the **Internal notes** field and move it
across when you are happy with it.

## What the player sees

Players (and their parents) only ever see the scores and the **Feedback for the player** message — never your internal Notes. If you leave the feedback field blank, the player just sees the ratings.

## Reading an evaluation

Click any row in the evaluations list to see the full breakdown — a radar chart of your ratings, the notes you wrote, and the date.

## Removing or hiding old evaluations

You can **archive** an evaluation to hide it from lists while keeping it in the player's history, or **delete** it if it was a mistake. Archived evaluations don't count toward player rate cards. The list opens on active evaluations; the **⋯** button at the end of the filter row switches to Archived, and a chip beside it says so while you are there.
