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

When the match activity has a **planned roster** (the expected players
you picked when creating it), the availability step starts from that
plan instead of marking everyone Present: planned players default to
Present, and team players you left out of the plan are pre-marked
Absent with the reason "not in planned roster." Adjust any chip — the
defaults are just a head start. Activities created without a planned
roster still default everyone to Present.

## Formation

The **Formation** dropdown lists every entry from
`tt_formation_templates`. The default is **4-2-3-1** — the pilot's
most common shape. Changing the formation reshapes the slot positions
on the pitches; player assignments transfer to the slots that survive
the rename, the rest fall back to the bench.

The descriptive name reads in your language (the shape numbers stay the
same in every language) — e.g. **Neutraal 4-3-3**, **Balbezit 4-3-3**.
An **Aanvallend 3-4-3 (ruit)** option (a 3-4-3 with a midfield diamond:
DM, two central mids and an attacking mid behind a front three) ships
alongside the standard 3-4-3, and draws its true diamond on the pitch —
a template carries its own slot positions, so two formations that share a
shape number (the flat 3-4-3 and the diamond) no longer collapse onto the
same layout.

## Player names — short form

Every player label on the match-prep surface — roster column, pitch
slot labels, Doen per speler column, Rollen pane, availability drawer
— renders as the player's **first name only** (`Daan`, `Senna`,
`Javi`). The full name is reserved for the player's own profile and
the team roster pages.

When two players on the same team share a first name, both render
as `<firstName> <lastInitial>` (`Daan P`, `Daan A`) so the coach can
tell them apart at a glance. The disambiguation triggers per team —
a third `Daan` on a different team has no effect on this team's
labels. The same player's short form is identical across every
sub-surface of the match-prep page.

If a player has no first name on file, the surface falls back to the
last name; if neither is on file, it shows `—` until the record is
fixed.

## Save behaviour

Every edit live-saves over REST — there is no Save button to press.
The toolbar's right side shows the current save state ("All changes
saved.", "Unsaved changes…", "Saving…", "Save failed. Try again.").
If a save fails, retry the edit; the network may have hiccuped.

## Print to paper (or PDF)

The **Export as PDF (A4)** button in the toolbar takes a picture of the
match-prep grid exactly as it appears on screen — both formation
pitches, the **Selection · minutes** table (per-half minutes + totals),
the **Wedstrijddoelen**, **Doen per speler**, and the **Roles & set
pieces** — and lays it out on **portrait A4**, scaled to the page width.
The slot numbers, player names, the `!` icon (red) and the camera icon
(green) all keep their on-screen colours. If the content is taller than
one page it spreads across multiple pages automatically. The PDF
downloads straight to your device. Because it captures the page as an
image, the result is pixel-faithful to what you see — the trade-off is
that the text in the PDF is not selectable.

The capture engine loads on demand the first time you click Export (it
isn't downloaded until then), so nothing extra weighs on the match-prep
page itself. If the capture can't run on your device a short notice
appears; fall back to your browser's own print dialog (**Ctrl/Cmd+P →
Save as PDF**), which prints the page directly.

The **Print team sheet** button next to it opens the pitch-side team
sheet (Starting XI / Bench / Squad with signature lines) on a clean
print page in a new tab, with its own **Export as PDF (A4 landscape)** /
**Print** choice. The central `?tt_view=exports` page still carries a
server-side match-prep / team-sheet PDF exporter as a fallback for
anyone who prefers to drive the export from there.

## What you can't do here

- Edit the roster (add / remove players from the team) — that's the
  **Teams** page.
- Run the match itself — that's **Match Execution**, the live phone
  app for the assistant coach. The **Start match** button only becomes
  active on match day itself — before then it's shown but disabled, with
  a tooltip naming the date it unlocks ("Available on match day (14
  Jun)"). This keeps a match from being started early by accident.
- Capture analyst feedback — the camera flag only marks who's been
  appointed; capturing their feedback is a separate workflow.
