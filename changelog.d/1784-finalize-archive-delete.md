# Finalize the safe-delete rollout — archive columns, holiday lifecycle UI + scheduled reports (#1784, #1808)

Bump: minor

Completes the referential-integrity delete epic (#1782).

- **Migration 0172** gives every archivable entity the uniform
  `archived_at` + `archived_by` columns: adds the missing `archived_by` to
  trial tracks, test trainings, holidays, player injuries, custom widgets
  and VCT exercises, and adds both columns to scheduled reports (backfilling
  `archived_at` from the legacy `status='archived'`).
- **Scheduled reports** join the framework: an Active/Paused schedule can be
  archived, and an archived one can now be **permanently deleted** from the
  management screen (fail-closed, `tt_edit_settings`).
- **Holidays** gain the full archive lifecycle in their list — an
  Active / Archived tab with Restore and Delete-permanently actions on
  archived rows (matching the tournaments list).

With this, every record type that has an archive lifecycle has a
fail-closed, referential-integrity-checked permanent delete. Team and
activity remain block-only by design (their full player-touching cascades
wait on the PHPUnit floor, #1388).
