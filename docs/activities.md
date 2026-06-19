<!-- audience: user -->

# Activities and attendance

An **activity** is anything on the calendar — a training, a game, or any other event (team-building day, club meeting, …). Each activity records who attended.

## The activities list (v4.7.0)

The **Activities** tile opens a date-bucketed card list. The buckets read top to bottom:

- **⚠ Needs attention** — past activities that are still marked Planned. They never got flipped to Completed or Cancelled, so the coach lost track of them. Surfaced in orange so they stand out.
- **Today** — what's on for today.
- **This week** — the rest of this calendar week (up to and including Sunday).
- **Next week** — Monday → Sunday of the upcoming week.
- **Later this month** — anything beyond next week, up to the end of the current month.
- **Later** — anything beyond the end of the month.

Empty buckets don't render their header at all — if there's nothing on for next week, the "Next week" header simply doesn't appear.

Each row is a card: a date badge on the left (month + day, painted blue for today and orange for Needs-attention rows), the activity title in the middle with a colour-coded type pill (Training blue, Match red, Friendly yellow, Other grey), and a chevron on the right. Tap anywhere on the card to open the activity detail page.

### Past activities

Past activities (Completed or Cancelled) are pinned to the **top** of the list as a single button — `N past activities hidden · Show ▼`. Tap to expand; tap again to collapse. The state is preserved in the URL as `?include_past=1`, so a shared link reflects the same view the sender saw.

Past **planned** activities (not closed off) are NOT in this collapsed bucket — they appear in the **Needs attention** bucket above Today, since they are signals that the coach still needs to act on.

### Filters

Two filters sit above the list:

- **Team** — narrow to one team. Defaults to all teams the coach has access to.
- **Type** — narrow to one activity type (Training / Game / Friendly / Other / any custom type your academy added).

Both filters survive in the URL (`?team_id=N&activity_type_key=match`), so deep-links from the dashboard land on the same scoped view.

## Creating an activity

1. Open the **Activities** tile.
2. Pick the **type** from the dropdown. Five types ship by default — Training, Game, Tournament, Meeting and Other — and your academy can rename or add new ones.
3. Pick the **status** — Planned, Completed or Cancelled. New activities default to Planned; flip to Completed once the activity has happened, or to Cancelled if it didn't go ahead.
4. If you picked **Game**, optionally pick the subtype (Friendly, Cup, League).
5. If you picked **Other**, give it a short label.
6. Pick the team, set the date, and optionally add a location and notes.
7. Save. The player list fills in automatically from the team roster.
8. Mark each player as Present, Absent, Late or Excused. Add a note next to a row when useful.

The activity list shows the type as a colour-coded pill so trainings, games, tournaments, meetings and other activities are easy to scan at a glance.

## Expected attendance

When you create an activity you pick which players are expected — the roster step defaults to the whole team, and you untick anyone you already know is away. Those picks are the activity's **planned roster**.

Open an activity's detail page and you'll see an **Expected attendance** panel listing those players (guests are tagged), with the count in the heading, so you know who to expect before the session. It shows nothing if you chose "Set attendance later" at creation. Marking who actually turned up still happens on the edit form (or the Mark attendance wizard) — the planned roster is what you expected, the marked attendance is what happened.

## Why the type matters

Each activity type can be linked to a workflow template that fires when you save an activity of that type. By default:

- **Game** spawns a post-game evaluation task per player on the team.
- **Training** and **Other** don't spawn anything.

Your academy admin can change which template fires for each type — or add a new type and pick its workflow template — under **Configuration → Activity Types**. The seeded types can't be deleted because the post-game evaluation rule depends on the **Game** type existing.

## Status and source

Every activity carries two extra fields beyond the headline type:

- **Status** — where the activity is in its lifecycle. **Planned** is the default for newly-created activities; flip to **Completed** when the activity has happened so reports and KPIs treat it as historical, or to **Cancelled** if it didn't go ahead. Status values are admin-extensible under **Configuration → Lookups** (lookup type `activity_status`).
- **Source** — who or what created the activity. **Manual** for activities created in the app, **Generated** for ones produced by the demo-data generator, and **Spond** for activities synced from a Spond calendar (when the integration is enabled). Source is set automatically; you don't pick it on the form. Like status, the list of sources is admin-extensible.

The Head of Development's 90-day quarterly rollup also uses these types: it shows one row per type in use, so renaming or adding types reflects there automatically.

## Who created and changed it

The activity detail page shows a small line at the bottom of the detail panel: **Created by** whoever added the activity, on the date they did, and **Last changed by** whoever most recently edited it. This is recorded automatically from now on — activities created before this was added show nothing there (there's no history to fill in), and the line only appears once an author is known.

## Guests

You can add players from outside the squad to an activity — for example a player borrowed from another team for a friendly, or a trial player.

There are two kinds of guest:

- **Linked guest** — an existing player from another team. Search for their name and pick them. Any evaluation you write attaches to their profile.
- **Anonymous guest** — name only, no record yet. Useful for one-off trial players. You can promote them to a real player later via **Add as player**.

To add a guest, open the activity, scroll to the **Guests** section, click **+ Add guest**, fill in the fields and click **Add**.

Guests don't count toward team statistics — attendance percentages and the team podium use the squad only.

## Cleaning up

You can archive an activity to clean up old seasons without losing its history.

## Principles practiced (v3.79.0)

Each activity can be tagged with one or more methodology principles so reports can ask "how often did we work on principle X this period?" The Principles practiced multiselect appears on both the public Activity edit page and the wp-admin form — pick from the principles configured under Methodology. The link is optional.

## Admin guest panel (v3.79.0)

The wp-admin Activity edit page now shows a read-only list of guest attendees recorded against the activity. Add or remove guests from the public Activity page; the admin panel stays in sync.
