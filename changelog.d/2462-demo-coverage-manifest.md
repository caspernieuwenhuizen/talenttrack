# Demo data: coverage manifest, and journey events are wipeable again (#2462)

Bump: minor

The demo-data module now declares its coverage in one place. Every table the
schema creates is classified in `DemoCoverage` as generated, planned, or
exempt with a stated reason, and the wipe, the generate form and the wipe
form all derive from that declaration instead of four hand-maintained lists
that had to agree.

The immediate fix an operator will notice: journey events generated during a
demo run were never tagged, so no wipe could ever reach them — an install
seeded with the `small` preset was carrying 606 orphaned timeline rows that
survived every "wipe demo data". They are tagged now and wipe with their
players. Excel-imported trial cases had the same gap and are also reachable.

Generated output is otherwise unchanged: the same seed and preset produce the
same academy, byte for byte, as before.

Two CI gates keep it that way. A migration that adds a `tt_` table now fails
the build until it is classified, and a self-check proves the delete order is
dependency-safe and that no generator can write rows the wipe cannot reach.
