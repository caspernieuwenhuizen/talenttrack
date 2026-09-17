# The Metingen tab is one register per category (#3526)

Bump: minor

The measurements tab was reported as cluttered and confusing, and it was worst
exactly when a player is new — which is when a coach opens it most.

Each category is now a single table with one row shape for every test: the name
and how often it runs, the latest reading, when it was measured, the standing,
the target, and the trend. Height and weight are ordinary rows in it rather
than a second table with its own two-line caption, and BMI-for-age is a row in
the category of the measurements it comes from rather than a separate card with
different corners sitting above the tab.

**The target is finally on screen.** A colour told you a reading was amber and
nothing on the page said what it was amber against. Every row now shows the
threshold the verdict is computed from, and the verdict itself is written out —
*on target*, *just under target*, *well over target* — so the colour restates it
instead of carrying it alone. That also makes the tab readable for a
colour-blind coach, which it was not.

**The apologies stopped repeating.** "One reading so far" was printed under
every single-reading test, three times over on a new player. It is counted once
in the card footer now, beside two other things the tab never said: which tests
have never been measured, and which are past their own frequency. A test that
has never been measured is listed as missing rather than overdue — a trialist's
blank profile is a different problem from a stale reading.

A never-measured test used to render a bare dash that read like a fault. It now
says *not measured yet* and stays in the table where it belongs.

On a phone each row is two lines, three when there is a trend to show, with no
sideways scrolling.
