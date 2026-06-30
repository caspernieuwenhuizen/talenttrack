# Measurements: coloured status picker on the Record-measurements roster (#2144)

Bump: patch

Recording a status-type test now offers a custom, accessible status picker per
player instead of a plain native dropdown. Both the closed control and every
option in the open list show the level's colour square next to its label, and
the control sizes to the longest label so level names are no longer clipped to
the numeric column width. The picker is fully keyboard- and touch-operable
(Enter/Space or the arrow keys to open, ↑/↓ to move, type-ahead, Escape to
close) and progressively enhances the native `<select>` — with JavaScript off
the working native dropdown remains. The chosen level still posts and saves
exactly as before. Numeric, scale and pass/fail inputs are unchanged.
