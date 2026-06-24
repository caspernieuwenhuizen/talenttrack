# Safe permanent delete for holidays, test trainings + trial tracks (#1784)

Bump: patch

Extends the referential-integrity delete framework (#1783) to three more
record types via new `/permanent` REST routes (gated by `tt_edit_settings`,
fail-closed). **Holidays** are removed directly; **test trainings** clear
any workflow-task link first; **custom trial tracks** block while a trial
case still uses them and built-in (seeded) tracks are refused. No migration.

The remaining archivable entities (custom widget, injury, VCT exercise) and
the list-view affordances stay tracked on #1784.
