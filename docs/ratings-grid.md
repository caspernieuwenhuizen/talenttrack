---
title: Ratings grid
group: match-day
summary: Rate a whole squad from one grid instead of opening each player.
audience: [user]
module: TT\Modules\Activities\ActivitiesModule
feature: ratings_grid
order: 50
---

# Ratings grid

*Audience: coaches, heads of development, academy admins.*

The **ratings grid** is the desktop, spreadsheet-style way to rate a squad after a session: the players run down the rows, the categories that activity is rated on run across the columns, and every cell is one score you type directly. One **Save** commits the lot.

It is the third of the entry grids (after [attendance](attendance-grid.md) and minutes) and the counterpart to the step-by-step evaluation wizard, which stays exactly as it was — on a phone, at the pitch, the wizard is still the right tool.

## Why this one is per-activity

The attendance and minutes grids show a whole *period*: players against many activities. Ratings can't work that way without losing detail. A rating isn't one number — it's a score per evaluation category — so a players × activities grid would have to squeeze several scores into one cell and show you a computed average instead of what you typed.

So the ratings grid fixes the activity and turns the *categories* into columns. Every cell stays a real score, nothing is derived, and there is no pop-up editor. The trade-off is deliberate: you rate one session at a time, which is when coaches rate anyway.

## Getting there

Open the activity and click **Ratings grid**. The button appears when you can edit activities, the activity has a team, and the grid is switched on for your academy — and it stays visible whether or not the wizards are enabled, so an academy running without wizards can still rate.

## Using it

- **Columns** are the categories the activity's evaluation type declares. If the type declares none, every active category is shown instead. Category names appear in your own language, the same as they do on the evaluation form; a category nobody has translated yet keeps its original name.
- **Main categories head their own block of columns.** The header has two rows: the main category on top, spanning everything beneath it, and its sub-categories underneath. A main category you rate directly keeps its own column, labelled *Main score*, next to its subs — so you can score at main level, at sub level, or both.
- **Sub-categories start collapsed.** Click a main category's header to fold its subs open, and click again to fold them away; each main opens independently, so you can go into detail on the one that matters and leave the rest compact. A main with no sub-categories is just a single column with nothing to open. A main you don't score directly holds an empty column while it's folded away — that's the header's place to sit, and there's nothing to type in it.
- **Nothing pending is ever hidden.** If you collapse a main while scores under it are still unsaved, the header shows how many, and the line under the grid says so. Those scores are still saved when you press Save. A score outside the scale forces its main back open, because you can't save until it's fixed.
- **Already-detailed ratings open detailed.** If somebody has scored a sub-category for this activity before, that main starts expanded so you can see the existing scores rather than a collapsed grid hiding them.
- **Rows** are the team's active players.
- **Scores** follow your academy's configured scale (by default 5 to 10 in half-point steps). A score outside the scale, or one that doesn't land on a step, is flagged the moment you type it: the cell turns red and the line under the grid tells you what's allowed. Save stays disabled until you've fixed it, so a score can't quietly fail to save.
- **Your typing is never silently corrected.** A 12 stays a 12 on screen until you change it — the grid won't clamp it to 10 or round a 7.3 to 7.5 behind your back. If something is refused, you're told, and the cell stays marked as unsaved.
- **An empty cell means "not rated".** It writes nothing, and it never clears a score somebody already recorded — clearing a score is a job for the evaluation form.
- **Edited cells are highlighted** until you press Save, so you can see what's pending.
- **Keyboard**: arrows move between cells, Enter moves down a row (the way you'd rate one category down the squad), Tab reaches Cancel then Save.
- **Save is explicit.** Nothing is written until you press it, and Cancel returns you to the activity without saving.

Saving twice is safe: the grid updates the player's existing evaluation for that activity rather than creating a second one, so scores never pile up.

## What it doesn't do

- **It doesn't replace the evaluation form.** Notes, player feedback and anything beyond the category scores still live there.
- **It doesn't compute overalls.** The weighted overall rating is derived when it's displayed, exactly as before — the grid never writes one.
- **It's desktop-only.** Below 1024px it points you at the wizard rather than shipping an unusable table to a phone.

## Turning it off

*Modules → Activities → Ratings grid.* With it off, the button disappears, the URL is refused, and the wizard plus the evaluation form carry on unchanged.
