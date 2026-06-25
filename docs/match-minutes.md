<!-- audience: admin -->

# Match minutes per age category

Youth football runs different match durations per age band — U8 might play
2 x 20, U13 2 x 30, U17 2 x 35. TalentTrack lets you set that default **once per
age category** so the recorded minutes stay accurate without re-typing a match
length for every game.

Accurate minutes matter because they feed each player's load and development
picture: the minutes report, the match-execution view, and the direct
match-completion entry all build on them.

## Where to set it

Configuration -> **Match minutes**.

The form lists every age category from your **Age groups** lookup
(Configuration -> Lookups -> Age groups). For each one, enter the **minutes per
half (N)**. The full match length — **2 x N** — is shown beside the input as you
type.

- Leave a row **blank** to inherit the global fallback of **35 minutes per
  half** (70 total).
- Values are whole minutes per half, 0-60.
- The setting is saved as soon as you press **Save match minutes**.

If no age categories exist yet, add them under Age groups first, then return
here.

## Where the default is used

Once set, the per-age-category default is the single source of truth for match
length. It prefills:

- **Match prep** — a new match prep for a team starts at that team's
  age-category half length instead of a hardcoded 35.
- **Match completion** — when a match-type activity is marked Completed, the
  **Match length** field above the attendance table prefills from the same
  source (an explicit value already saved on the activity or its match prep
  still wins).

In every case the prefilled value remains **editable per match** — last write
wins. Changing the central default does not rewrite match lengths already
recorded against past matches.

## Resolution order

For a given match, the half length is resolved most-specific first:

1. an explicit per-match value already stored on the activity or its match prep;
2. the **age-category default** for the team's age group
   (`match_minutes_by_age_group`);
3. the global fallback of **35** minutes per half.

## API

The defaults are stored in `tt_config` under the JSON key
`match_minutes_by_age_group` (a map of age-group name to minutes-per-half) and
are readable and writable through the configuration REST endpoint:

- `GET /wp-json/talenttrack/v1/config`
- `POST /wp-json/talenttrack/v1/config`

So a future front end gets exactly the same defaults the rendered forms use.
