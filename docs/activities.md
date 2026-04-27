<!-- audience: user -->

# Activities

An **activity** is anything you book on the calendar — a **game** (friendly / cup / league), a **training**, or **other** (team-building day, club meeting, anything that doesn't fit). Each activity has:

- A type — `Game`, `Training`, or `Other` (extensible via Configuration → Lookups → activity_type).
- A subtype when type is `Game` — `Friendly`, `Cup`, or `League` (extensible via Configuration → Lookups → game_subtype).
- A free-text label when type is `Other` — e.g. "Team-building day", "Club meeting".
- A team it belongs to.
- A date and time.
- Optional location + notes.
- A list of attending players with an attendance status (Present, Absent, Late, Excused — configurable via [Configuration](?page=tt-docs&topic=configuration-branding)).

## Creating an activity

From the **Activities** admin page or the coach frontend:

1. Pick the **Type** — Game / Training / Other (Training is the default).
2. If Game: pick a **Subtype** (Friendly / Cup / League). Optional.
3. If Other: type a free-text **Other label** (required).
4. Pick the team.
5. Set date + time.
6. Optionally add location + notes.
7. Save — the player list auto-populates from the team roster.
8. Mark each player's attendance.

## Why typing matters

Two downstream features use `activity_type_key`:

- **Post-game evaluation workflow** — saving an activity with `Type = Game` automatically spawns a post-game coach evaluation task per active player on the team (any subtype — friendly / cup / league all spawn). Trainings + other activities never spawn this task. Cadence is configurable in Configuration → Workflow templates.
- **HoD quarterly review** — the live-data form splits its 90-day rollup into "Games / Trainings / Other" so the Head of Development sees activity volume per type at a glance.

## Attendance tracking

Attendance status values are lookups — add new ones in Configuration → Attendance Status. Each status is just a label; no special business logic beyond the label itself and the count.

## Archiving

Like other entities, activities can be archived to clean up old seasons without losing history.

## Guest attendance

An activity can record players who attended without being on the team's roster — a U13 promoted to U14 for an injury cover, a player from another club doing a trial day, an off-roster guest at a friendly. Guests live alongside the regular roster on the attendance form but never inflate team-level statistics.

### Two flavours

- **Linked guest** — a real `tt_players` record (typically from another team). Pick the player from the cross-team picker; the row links to their profile, and any evaluation you write attaches to that player normally.
- **Anonymous guest** — name + optional age + optional position, no `tt_players` record. Coach-authored free-text notes are the only structured observation. You can promote an anonymous guest to a real player later via "Add as player" without losing the attendance history.

### Adding a guest

1. Open the activity edit page.
2. Save the activity if it's new (guests need an activity id to attach to).
3. Scroll to the **Guests** section under the regular attendance table.
4. Click **+ Add guest**, pick the **Linked** or **Anonymous** tab, fill in the fields, click **Add**.

The new row appears immediately in the Guests table.

### Promoting an anonymous guest

Anonymous guest rows have an **Add as player** action. It opens the player-create form pre-filled with the guest's name, derived date of birth (current year minus age), and position. After save, the original attendance row is updated to reference the new player; the anonymous fields are cleared but the row's `is_guest` flag stays at 1 (the historical fact that they joined as a guest is preserved).

### Stats isolation

Team-level aggregates (attendance %, podium, rolling stats, team chemistry when it ships) exclude guest rows automatically — they're filtered by `is_guest = 0`. The "Att. %" column on the activities list shows roster turnout only. Guests still surface on:

- The host coach's activity edit page (Guests section).
- The linked guest's own player profile (Attendance tab — flagged "(as guest)").
- The host club's evaluation list when the host coach evaluates a linked guest.

Anonymous guests have no profile of their own until promoted.

## Migration from "Sessions"

In v3.x the entity was called "Session". v3.24.0 (#0035) renames it to "Activity" with the typing layer described above. Existing sessions auto-migrated to `activity_type_key = 'training'` — a one-time admin notice flags the migration so historical games can be reclassified via the edit form.
