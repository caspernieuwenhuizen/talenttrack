<!-- audience: user -->

# Activities and attendance

An **activity** is anything on the calendar — a training, a game, or any other event (team-building day, club meeting, …). Each activity records who attended.

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

## Guests

You can add players from outside the squad to an activity — for example a player borrowed from another team for a friendly, or a trial player.

There are two kinds of guest:

- **Linked guest** — an existing player from another team. Search for their name and pick them. Any evaluation you write attaches to their profile.
- **Anonymous guest** — name only, no record yet. Useful for one-off trial players. You can promote them to a real player later via **Add as player**.

To add a guest, open the activity, scroll to the **Guests** section, click **+ Add guest**, fill in the fields and click **Add**.

Guests don't count toward team statistics — attendance percentages and the team podium use the squad only.

## Cleaning up

You can archive an activity to clean up old seasons without losing its history.
