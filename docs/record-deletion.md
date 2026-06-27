<!-- audience: operator, developer -->
# Record deletion — archive, restore, permanent delete

Most records in TalentTrack follow an **archive lifecycle**: you archive
them (soft-delete) and can restore them. A separate, irreversible
**permanent delete** is gated by `tt_edit_settings`.

### Where to find it

- **List views** (players, teams, evaluations, goals, tournaments,
  holidays): the **Delete permanently** action appears on archived rows
  (use the Archived tab/filter), alongside Restore.
- **Detail / editor pages** (trial case, trial track, VCT exercise): a
  **Delete permanently** button sits beside the page's Archive control.

In every case, if the delete is blocked by a still-referencing record, the
screen shows the reason (e.g. *"Cannot delete: still referenced by N …"*).

## Referential-integrity-checked delete (#1783)

Permanent delete is **fail-closed**. Before removing a record it scans for
other records that reference it and then either:

- **cascades** the record's own children (e.g. deleting an evaluation also
  removes its category ratings; deleting a goal removes its links and its
  conversation thread),
- **clears** references on rows that outlive the record. For a nullable
  link the reference is set to empty (e.g. a workflow task that spawned a
  goal keeps existing, with its goal link cleared); for a required link
  that can't be empty, the row is instead **re-homed to "unassigned"**
  (e.g. deleting a team leaves its players in place, no longer assigned to
  any team), or
- **blocks** the delete when some other record still references it that is
  not owned by it. The delete is refused with a message naming what still
  points at it (e.g. *"Cannot delete: still referenced by 18 players,
  6 activities. Archive or remove these first."*).

The worst case is a **refused** delete — a permanent delete never silently
leaves orphaned rows behind.

### Per-entity behaviour today

| Record | On permanent delete |
| --- | --- |
| Player, Person, PDP file | Full cascade (existing dedicated services). |
| Evaluation | Cascades its ratings + evidence links. |
| Goal | Cascades its links + conversation thread; clears spawned-goal links. |
| Tournament | Cascades its matches, squad and per-match assignments; clears a linked activity's tournament link. |
| Trial case | Cascades its staff assignments, staff inputs and extensions; clears workflow-task / prospect links. |
| Holiday | Standalone — removed directly. |
| Test training | Clears any workflow-task link, then removes the session. |
| Trial track | Built-in tracks can't be deleted; a custom track **blocks** while any trial case still uses it. |
| VCT exercise | Cascades its coaching points; clears the exercise link on any session block. |
| Custom widget | Standalone — removed directly. |
| Injury | Removes the injury and its journey-timeline events (a minor's medical record). |
| Scheduled report | Standalone — removed directly (on an already-archived schedule). |
| Team | Cascades pure team-owned config (formations, playing styles, chemistry, blueprints, staff assignments, per-team exercise overrides, the VCT periodization stack). **Preserves player development data**: players, their team-history, the team's activities (with attendance + evaluations), tournaments and measurement sessions all survive, re-homed to "unassigned". Open invitations / workflow tasks / staged ideas pointing at the team have the link cleared. |
| Activity | Cascades execution data that only exists inside the activity (attendance, planned exercises, principles, the match-prep and match-execution trees) plus activity-sourced journey events. Records that outlive the activity keep their row with the link cleared: evaluations, tournament-match bindings, VCT-session bindings, and behaviour ratings. |

Deleting a team or activity never destroys a player's development history —
worst case, a record is left unassigned rather than removed.
