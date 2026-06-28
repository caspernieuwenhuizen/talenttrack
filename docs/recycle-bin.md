<!-- audience: admin -->

# Recycle bin — retention, permanent deletion, and GDPR

TalentTrack records are never deleted in one click. They move through three
tiers, each more final than the last:

1. **Active** — the row is live and shows up everywhere.
2. **Archived** — soft-hidden from day-to-day lists but fully recoverable.
   A player who leaves the academy, a finished tournament, a closed trial
   case: archive keeps the history without cluttering the active view.
3. **Trashed (recycle bin)** — staged for permanent deletion. Still
   recoverable, but on a clock: a trashed row is purged automatically after
   the retention window, or immediately if an admin empties the bin.

This page covers the **recycle bin** tier — what it is for, who can touch it,
how long things live there, and how it satisfies the academy's GDPR
obligations for minors' data.

> The bin is **academy-admin only**. See [Who can manage the bin](#who-can-manage-the-bin).

## What can go in the bin

Every entity that can be archived can also be trashed — 20 record types,
from players and teams to evaluations, goals, trial cases, injuries,
measurements, and scheduled reports. Lookup and configuration tables
(rating scales, age groups, vocabularies) are **not** binnable: they are
shared settings, not player records, and there is nothing to recover.

The list is anchored to the same entity map the archive uses, so the bin and
the archive always agree on which records they cover.

## Who can manage the bin

Managing the recycle bin — viewing trashed rows, restoring them, and
permanently purging them — requires the **`tt_manage_recycle_bin`**
capability. By default only two actors hold it:

- the **WordPress administrator** (the person running the install), and
- the **Academy Admin** role (`tt_club_admin`).

Coaches, Heads of Development, scouts, staff, and read-only observers do
**not** hold it and cannot reach the bin. This is deliberate: purging is the
single most destructive action in the product, so it is gated more tightly
than ordinary editing. In particular, holding `tt_edit_settings` (which any
settings-tab editor has) does **not** grant bin access.

In the [authorization matrix](authorization-matrix.md) the capability maps to
the `recycle_bin` entity, granted `rcd` at global scope to the Academy Admin
persona only.

## The retention window

A trashed row is kept for a **retention window** before it is purged
automatically. The default is **30 days**, stored per club in
`tt_config` under the key `tt_recycle_bin_retention_days`. There is no
settings screen for it yet; an operator who needs a different window can set
the config value directly, and the purge process reads it with a 30-day
fallback.

The window is an explicit **retention / recovery buffer**, not an accident:

- It gives staff a grace period to undo a mistaken deletion — the most
  common reason data comes back out of the bin.
- It bounds how long staged-for-deletion data lingers, so the academy is not
  silently hoarding records it has decided to remove.

## GDPR — retention basis and the right to erasure

These are minors' records, so the retention basis is explicit.

- **Lawful retention buffer.** The 30-day window is the documented retention
  basis for trashed records: data that has been marked for deletion but is
  held briefly so an accidental deletion can be reversed. After the window,
  the purge removes it for good.
- **Article 17 — immediate erasure.** When a parent or guardian exercises
  the right to be forgotten and erasure must happen *now*, an admin empties
  the bin (or purges the specific row) rather than waiting out the 30 days.
  "Purge now" is the immediate-erasure path; the retention window is the
  default, not a floor.
- **Scope agreement with erasure.** Every player-PII entity that the bin can
  hold is registered in `PlayerDataMap`, the central manifest the
  subject-access and erasure tooling walks. So a concurrent erasure run and
  the bin operate over the same set of tables — the bin can never strand PII
  the erasure path would otherwise remove, and vice versa. (Note: a handful
  of binnable entities — teams, tournaments, custom widgets, scheduled
  reports, measurement *definitions* — are academy configuration, not
  player PII, and are correctly absent from `PlayerDataMap`.)

For the full GDPR how-to (subject-access requests, the erasure lifecycle of a
player joining and leaving), see the Privacy operator guide in the in-product
docs.

## One owner for permanent deletion

There must be exactly one trust level for destroying data. Before the bin,
the legacy per-entity "delete permanently" endpoints (for example
`DELETE /players/{id}/permanent`) gated on `tt_edit_settings` — a weaker bar
than the bin's own purge. **No purge path may be gated more weakly than the
bin.**

The decision: the legacy `/permanent` endpoints are **re-gated onto
`tt_manage_recycle_bin`**, so every permanent-deletion path — the bin's purge
and the legacy per-entity endpoints — requires the same capability. This
re-gating ships in the bin's REST work (issue #2024): every
`DELETE …/permanent` route (players, teams, evaluations, goals, activities,
tournaments, holidays, trial cases, trial tracks, injuries, test trainings,
custom widgets, training exercises) now requires `tt_manage_recycle_bin`.
Holding `tt_edit_settings` alone no longer permits a permanent delete from any
surface.

## The centralized recycle bin

The bin has its own screen. Open **Configuration → System → Recycle bin**, or
go straight to `?tt_view=recycle-bin`. It is not a dashboard tile — it lives in
the settings area, and only academy admins (`tt_manage_recycle_bin`) can reach
it. Everyone else sees a "no permission" notice.

The screen lists every trashed record across all binnable entity types,
**grouped by type** with a count per group (Players, Teams, Evaluations, …).
Each row shows:

- the record's **identity** (its name or title, or `Record #<id>` as a
  fallback),
- **who binned it and when**, and
- a **days-until-purge badge** counting down to the automatic purge. The badge
  turns **red in the final week** (7 days or fewer) so an imminent permanent
  deletion stands out.

The bin is **action-only** — there is no drill-in to a record from here. Two
inline actions sit on every row:

- **Restore** — moves the record back to the **archive** tier (not straight to
  active). It leaves the bin and reappears in the entity's Archived list.
- **Delete now** — permanently purges the record. Before anything is deleted, a
  confirmation dialog shows the **full cascade preview**: what will be removed,
  what references will be cleared (kept, not deleted), and — if the purge is
  **blocked** because other records still depend on it — the dependency report.
  A blocked purge writes nothing and leaves the record in the bin. "Delete now"
  is the manual immediate-erasure path (GDPR Article 17); it does not wait out
  the retention window.

When the bin is empty, the screen says so rather than showing an empty table.

## Moving a record to the bin from a list

You do not need to open the recycle bin to put something in it. Every
per-entity list (players, teams, evaluations, goals, tournaments, holidays,
and the rest) has two status views:

- **Active** — live records.
- **Archived** — soft-hidden records, recoverable.

A previous third tab, **All**, has been removed: trashed records never appear
in a per-entity list, so "All" was misleading. Archived rows are the only
place the destructive affordance lives.

On an **archived** row you see two actions:

- **Restore** — returns the record to the active list.
- **Move to recycle bin** — stages the record for permanent deletion. This is
  **reversible**: the record drops into the bin and can be restored from
  there until it is purged. It replaces the old "Delete permanently" button,
  which destroyed data immediately from the list; the real permanent purge now
  lives only inside the bin.

Before the record moves, a confirmation dialog shows the **full cascade
preview** — every linked record a later purge would remove, every reference
it would clear, and anything that currently blocks a permanent delete. The
move itself is never blocked (it is reversible); the blockers are shown for
information so you know what an eventual purge would face.

Right after a record moves to the bin, a banner offers **Undo** — one click
restores it straight back out of the bin.

## Opening an archived or trashed record

You can open the detail page of a record that is no longer active. Before, a
direct link to an archived or trashed record showed "does not exist", because
the detail page only ever loaded live rows. Now it falls back to a **compact
read-only summary** of the record instead.

The read-only page shows the record's identity (name and photo where it has
one) and a handful of key fields — enough to recognise which record it is —
plus a status banner. It is deliberately **not** the full profile, and it
carries **no Edit button**: to change a non-active record you restore it first,
then edit.

- An **archived** record shows an amber banner — "This record is archived",
  with who archived it and when — and two actions: **Restore** (back to the
  active list) and **Move to recycle bin**.
- A **trashed** record shows a red banner — "In the recycle bin — deletes in
  N days" — and two actions: **Restore to archive** (out of the bin, back to
  the archived tier) and **Delete permanently now**.

A trashed record is only reachable this way by an admin who can manage the bin.
Anyone else who opens a trashed record's link gets the ordinary "not found"
page — the same answer they would get for a record that never existed — so the
existence of a soft-deleted minor's record is never confirmed to someone who
may not see it.

## Audit trail

Every bin action is recorded in the audit log with a stable action key per
entity:

- `{entity}.trashed` — moved into the bin
- `{entity}.restored` — recovered from the bin
- `{entity}.purged` — permanently deleted

For example, `player.trashed`, `evaluation.restored`, `goal.purged`. These
keys surface in the audit-log viewer's action filter once the matching
actions have occurred, so an admin can review exactly what was binned,
restored, or destroyed, by whom, and when.
