<!-- audience: user -->

# Sessions

A **session** is a training event. Each session has:

- A team it belongs to
- A date and time
- Optional location
- A list of attending players with an attendance status (Present, Absent, Late, Excused — configurable via [Configuration](?page=tt-docs&topic=configuration-branding))

## Creating a session

From the **Sessions** admin page or the coach frontend:

1. Pick the team.
2. Set date and time.
3. Optionally add location and notes.
4. Save — the player list auto-populates from the team roster.
5. Mark each player's attendance.

## Attendance tracking

Attendance status values are lookups — add new ones in Configuration → Attendance Status. Each status is just a label; no special business logic beyond the label itself and the count.

## Session reporting

Session counts don't currently feed into the main analytics (Rate Card, Comparison). They show on the player's profile under "Sessions attended" and power the frontend dashboard's attendance view.

## Archiving

Like other entities, sessions can be archived to clean up old seasons without losing history.

## Guest attendance (v3.22.0)

A session can record players who attended without being on the team's roster — a U13 promoted to U14 for an injury cover, a player from another club doing a trial day, an off-roster guest at a friendly. Guests live alongside the regular roster on the attendance form but never inflate team-level statistics.

### Two flavours

- **Linked guest** — a real `tt_players` record (typically from another team). Pick the player from the cross-team picker; the row links to their profile, and any evaluation you write attaches to that player normally.
- **Anonymous guest** — name + optional age + optional position, no `tt_players` record. Coach-authored free-text notes are the only structured observation. You can promote an anonymous guest to a real player later via "Add as player" without losing the attendance history.

### Adding a guest

1. Open the session edit page.
2. Save the session if it's new (guests need a session id to attach to).
3. Scroll to the **Guests** section under the regular attendance table.
4. Click **+ Add guest**, pick the **Linked** or **Anonymous** tab, fill in the fields, click **Toevoegen**.

The new row appears immediately in the Guests table.

### Promoting an anonymous guest

Anonymous guest rows have an **Add as player** action. It opens the player-create form pre-filled with the guest's name, derived date of birth (current year minus age), and position. After save, the original attendance row is updated to reference the new player; the anonymous fields are cleared but the row's `is_guest` flag stays at 1 (the historical fact that they joined as a guest is preserved).

### Stats isolation

Team-level aggregates (attendance %, podium, rolling stats, team chemistry when it ships) exclude guest rows automatically — they're filtered by `is_guest = 0`. The "Att. %" column on the sessions list shows roster turnout only. Guests still surface on:

- The host coach's session edit page (Guests section).
- The linked guest's own player profile (Attendance tab — flagged "(as guest)").
- The host club's evaluation list when the host coach evaluates a linked guest.

Anonymous guests have no profile of their own until promoted.
