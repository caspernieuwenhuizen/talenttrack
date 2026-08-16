# Ratings grid: rate a whole squad on one screen (#2414)

Bump: minor

A new **Ratings grid** completes the desktop entry grids (epic #2381). Open an
activity, click **Ratings grid**, and you get the squad down the rows and the
categories that activity is rated on across the columns — one score per cell,
typed directly, one Save for the lot.

It's deliberately per-activity rather than per-period like the attendance and
minutes grids. A rating isn't one number but a score per category, so a
players × activities grid would have to collapse several scores into one cell
and show a computed average instead of what you typed. Fixing the activity and
making the categories the columns keeps every cell a real score.

Details that matter in daily use: an empty cell means "not rated" and never
erases a score somebody already recorded; saving twice updates the player's
existing evaluation rather than creating a second one; edited cells stay
highlighted until you save; and arrows plus Enter move around the grid so you
can rate a category straight down the squad without touching the mouse.

The evaluation wizard and the evaluation form are unchanged — the wizard stays
the phone/pitch path, and notes and player feedback still live on the form. The
grid is desktop-only and can be switched off per academy under
*Modules → Activities → Ratings grid*.
