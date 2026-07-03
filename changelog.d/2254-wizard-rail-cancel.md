# Wizard: cleaner branched progress rail + Cancel always exits (#2254)

Bump: patch

Wizards that branch (like the evaluation wizard's "Evaluate an activity"
vs "Evaluate 1 player" choice) now show a clean progress rail: only the
steps on the path you actually picked appear, instead of listing both
branches with half of them greyed out as "Not applicable". The step
counter reflects the active path too.

Cancel no longer loops. Previously, after moving through a step or two,
the Cancel link could send you back into the same wizard (its own URL had
become the browser referer) — an inescapable loop. Cancel now always
returns you to where you opened the wizard from (the list you came from,
otherwise the dashboard), never back into the wizard. Framework-level, so
every wizard benefits.
