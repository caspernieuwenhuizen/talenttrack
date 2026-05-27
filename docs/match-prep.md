<!-- audience: user -->

# Match preparation

The **Match preparation** surface lets a head coach plan a match in one
spreadsheet-style screen: who is available, who starts each half on
which pitch position, what the tactical goals are per phase, what each
player should pay attention to, and who takes the captain band plus
each set piece.

Open it from a match-type activity's detail page; the URL is
`?tt_view=match-prep&activity_id=<id>`. The first time you open it for
a given match the **Availability wizard** runs first so the roster gets
the Present / Absent / Injured chips it needs.

## Layout

The page is laid out as three columns matching the pilot's working
spreadsheet:

- **Left** — the available roster with three minute counters per row
  (`min 1e`, `min 2e`, `tot`) and totals at the foot. Minutes auto-
  derive from `half_length × (on pitch ? 1 : 0)`; editing the
  **Half length** input at the top updates every row live.
- **Middle** — two half-pitches side by side with a `→` copy button
  between. Below them sits the **Match goals** panel — one full-width
  *General* box on top, then a 2×2 grid of *Attacking / Defending* and
  *Set pieces (attack) / Set pieces (defend)*. Each box has four short
  single-line inputs (bullet-style) — written for the pilot's habit of
  short rules rather than long paragraphs.
- **Right** — two stacked panels. **Player focus** carries a per-player
  attention text field, a `!` flag for "this is a specific goal for
  this player", and a camera icon for "I've appointed a video analyst
  for this player." **Roles & set pieces** carries six rows — Captain,
  Corner left / right, Free kick left / right (cross), Penalty.

## Assigning players to slots

A slot on a pitch is a position circle. Click any slot — empty or
filled — to open a **player picker** anchored next to the slot. Type
to filter; tap a name to assign. Picking a player who is already on
this half displaces them automatically (one player per half).

You can also **drag a player from the left roster** directly onto a
slot. The drag is the desktop enhancement; the click-to-pick is the
primary path and works the same on every device.

The `→` button between the two pitches **copies the first half's
lineup to the second half** in one click, no confirmation. Adjust the
second half from there (typically one or two subs).

## Captain and set-piece takers

The right-side **Roles & set pieces** pane works the same as a pitch
slot. Click any row — Captain, Corner left, Corner right, Free kick
left, Free kick right, Penalty — to open the same player picker. The
× pill on a filled row clears the assignment. Marking a player Absent
in the drawer pulls them out of role assignments AND lineup slots
automatically, so the role pane never points at an unavailable player.

## Availability drawer

Click **Availability** in the toolbar to slide in the drawer with
three chips per player: **Present**, **Absent (excused)**, **Injured**.
Add an optional reason for absences. **Mark all present** is the
shortcut for "the whole roster is here today." Closing the drawer
saves; marking anyone Absent pulls them out of every lineup slot and
role row.

## Formation

The **Formation** dropdown lists every entry from
`tt_formation_templates`. The default is **4-2-3-1** — the pilot's
most common shape. Changing the formation reshapes the slot positions
on the pitches; player assignments transfer to the slots that survive
the rename, the rest fall back to the bench.

## Save behaviour

Every edit live-saves over REST — there is no Save button to press.
The toolbar's right side shows the current save state ("All changes
saved.", "Unsaved changes…", "Saving…", "Save failed. Try again.").
If a save fails, retry the edit; the network may have hiccuped.

## Print to PDF

The **PDF (landscape A4)** button opens a printer-ready PDF that
ships the lineup + per-player attention + tactical goals to the
sideline whiteboard. The role pane is on the screen surface only for
now — a follow-up release will add it to the PDF too.

## What you can't do here

- Edit the roster (add / remove players from the team) — that's the
  **Teams** page.
- Run the match itself — that's **Match Execution**, the live phone
  app for the assistant coach.
- Capture analyst feedback — the camera flag only marks who's been
  appointed; capturing their feedback is a separate workflow.
