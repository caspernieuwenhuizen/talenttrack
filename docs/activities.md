<!-- audience: user -->

# Activities and attendance

An **activity** is anything on the calendar — a training, a game, or any other event (team-building day, club meeting, …). Each activity records who attended.

## The activities list (v4.7.0)

The **Activities** tile opens a date-bucketed card list. The buckets read top to bottom:

- **⚠ Past — still open** — past activities that are still marked Planned. They never got flipped to Completed or Cancelled, so the coach lost track of them. This section renders **by default** at the very top of the list — above the collapsed Past toggle — in its own tinted, orange-accented block so past-but-unclosed activities can't be missed.
- **Today** — what's on for today.
- **This week** — the rest of this calendar week (up to and including Sunday).
- **Next week** — Monday → Sunday of the upcoming week.
- **Later this month** — anything beyond next week, up to the end of the current month.
- **Later** — anything beyond the end of the month.

Empty buckets don't render their header at all — if there's nothing on for next week, the "Next week" header simply doesn't appear.

Each row is a card: a date badge on the left (month + day, painted blue for today and orange for past-still-open rows), the activity title in the middle with a colour-coded type pill (Training blue, Match red, Friendly yellow, Other grey), and a chevron on the right. Tap anywhere on the card to open the activity detail page.

### Past activities

Past activities (Completed or Cancelled) are pinned to the **top** of the list as a single button — `N past activities hidden · Show ▼`. Tap to expand; tap again to collapse. The state is preserved in the URL as `?include_past=1`, so a shared link reflects the same view the sender saw.

Past **planned** activities (not closed off) are NOT in this collapsed bucket — they appear in the **Past — still open** section at the top of the list (above the Past toggle and Today), shown by default, since they are signals that the coach still needs to act on.

### Filters

A single **filter bar** sits above the list. On a desktop screen it shows everything on one line; on a phone or tablet it collapses to a **Filters** button (with a badge counting how many filters are active) and a row of summary chips — tap **Filters** to open a bottom sheet holding the same controls, then **Apply** or **Clear**.

The bar holds five controls, each under its own label:

- **Team** — narrow to one team. Defaults to all teams the coach has access to.
- **Type** — narrow to one activity type (Training / Game / Friendly / Other / any custom type your academy added).
- **Period** — a date window: **All · This week · Next week · This month · Next month · This season**. Picking one scopes the list without typing dates — weeks run Monday–Sunday, months are calendar months, and **This season** uses your configured current season.
- **Status** — an **Active · Archived · All** control. **Active** is the default — the timeline you normally see. **Archived** replaces the timeline with a flat list of the activities you've archived, each with a **Restore** button and (for admins) a **Delete permanently** button. **All** shows the active timeline with the archived list appended below it.
- **Cancelled** — a **Show** switch, off by default. Cancelled activities are hidden so the schedule stays clean; flip it on to bring them back, dimmed and struck through with a Cancelled pill in whichever date bucket they fall.

Every choice survives in the URL (`?team_id=N&activity_type_key=match&period=this_week&archived=archived&show_cancelled=1`), so deep-links from the dashboard land on the same scoped view, and the controls combine freely.

## The activity detail page

Tapping a card opens the activity's detail page, laid out as a set of cards so every registered detail is visible at a glance. It adapts between a **training** and a **match day**:

- **Hero** — a type-coloured icon chip, the title, and a sub-line reading `date · time · team · location`. For a match day with both teams known the title reads `Your team vs Opponent` and the sub-line shows the kick-off time and whether it's home or away. Pills below the title show the type (plus the game subtype or the Other label) and the status. Edit, Mark attendance and the other actions stay in the page header above.
- **Facts strip** — four quick facts. A training shows Date · Time · Type · Status; a match day shows Opponent · Home/Away · Kick-off · Formation. Facts with no value are left out.
- **Cards** — only the cards that have something to show appear, so the page stays uncluttered:
  - **Linked principles** — the practiced principles as colour-coded O/A/V pills, each linking into the methodology browser.
  - **Notes** — the activity's free-text notes.
  - **Line-up** (match day) — the Starting XI and the Bench, each player shown with jersey number and the position played (falling back to their preferred position).
  - **Expected attendance** — the planned roster (see below).
  - **Attendance** (completed activities) — a breakdown bar and legend across Present / Absent / Late / Excused / Injured (plus any custom statuses), with the headline `X / Y present (Z%)` linking to the attendance edit form. A note warns when roster players still have no attendance row.
  - **Tournament** — for tournament-typed activities, the linked tournament with its dates and match count.
- **Audit footer** — who created and last changed the activity.

The page reads cleanly on a phone: the cards stack in a single column and widen to two columns on a tablet or desktop.

## Creating an activity

1. Open the **Activities** tile.
2. Pick the **type** from the dropdown. Five types ship by default — Training, Game, Tournament, Meeting and Other — and your academy can rename or add new ones.
3. Pick the **status** — Planned, Completed or Cancelled. New activities default to Planned; flip to Completed once the activity has happened, or to Cancelled if it didn't go ahead.
4. If you picked **Game**, optionally pick the subtype (Friendly, Cup, League).
5. If you picked **Other**, give it a short label.
6. Pick the team, set the date, and optionally add a location, a start/end time, and notes. For a match, entering the kick-off time prefills the end time to 105 minutes later (90' play + 15' half-time); you can still change it.
7. For a **match** type (Game, Tournament, or a custom match/friendly type) an optional **Presence time** field appears — the arrival time families should be there by. It prints on the weekly planner PDF as `Present HH:MM`.
8. Save. The player list fills in automatically from the team roster.
8. Mark each player as Present, Absent, Late or Excused. Add a note next to a row when useful.

### Match minutes (completed matches)

When a **match**-type activity is marked **Completed**, the attendance table gains two extra columns — **Starter** and **Minutes** — so you can log how long each player actually played, even for a past match you never live-tracked. A **Match length (minutes)** field sits above the table; it prefills from the match prep's two halves (or 70 minutes when there's no prep) and you can adjust it. From the starter flags, the minutes and the match length, the form shows a **Subs: N on · N off** summary — substitutes who came on, and starters who were taken off — which refreshes when you save. The minutes you enter feed the minutes report and the player's load picture.

The activity list shows the type as a colour-coded pill so trainings, games, tournaments, meetings and other activities are easy to scan at a glance.

## Expected attendance

When you create an activity you pick which players are expected — the roster step defaults to the whole team, and you untick anyone you already know is away. Those picks are the activity's **planned roster**.

Open an activity's detail page and you'll see an **Expected attendance** panel listing those players (guests are tagged), with the count in the heading, so you know who to expect before the session. It shows nothing if you chose "Set attendance later" at creation. Marking who actually turned up still happens on the edit form (or the Mark attendance wizard) — the planned roster is what you expected, the marked attendance is what happened.

If you create an activity **already marked Completed** (it happened in the past) and don't enter attendance, the full active roster is recorded as **present** automatically so the activity is immediately rateable — adjust any absences on the edit form afterward.

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

A guest appearance **does** count toward the guesting player's own load. On a player's profile — their attendance KPI and the status engine's load input — a session they played as a guest for another team is counted alongside their own-team sessions, so a heavily played-up player's load reads accurately. The split is deliberate: *player load = everything the player did anywhere; team statistics = own-roster only.*

## Cleaning up

You can **archive** an activity to clean up old seasons without losing its history. The **Archive** button on the activity detail page hides the activity from the active timeline but keeps the row — and its attendance — intact.

Archiving is a soft delete, so the **Archive** and **Restore** buttons need the activities *create/delete* capability — the same permission as creating an activity. A coach who can only edit activities (for example an assistant coach) can still change an activity but won't see Archive or Restore.

Archived activities live under the **Archived** status tab on the activities list (see [Filters](#filters)). From there you can:

- **Restore** an activity — it returns to the active timeline exactly as it was. Opening an archived activity's own detail page now shows a **Restore** button in its header too (in place of Archive), so you can bring it back with one click without hunting through the list. An archived activity is read-only until restored — the Edit and match actions stay hidden until it is active again.
- **Delete permanently** — an admin-only action (requires the *edit settings* capability) that removes the activity for good. This cannot be undone. If the activity still has attached records (attendance, exercises, match data), the delete is blocked and the activity stays archived — restore it or clear those records first. Archiving is the safe default; permanent deletion is the rare exception.

## Principles practiced (v3.79.0)

Each activity can be tagged with one or more methodology principles so reports can ask "how often did we work on principle X this period?" The Principles practiced multiselect appears on both the public Activity edit page and the wp-admin form — pick from the principles configured under Methodology. The link is optional.

## Admin guest panel (v3.79.0)

The wp-admin Activity edit page now shows a read-only list of guest attendees recorded against the activity. Add or remove guests from the public Activity page; the admin panel stays in sync.
