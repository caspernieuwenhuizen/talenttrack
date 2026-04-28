<!-- audience: user -->

# Player journey

Every player has a story at the academy: when they joined, the trial that got them in, evaluations along the way, the team they're on, the injury they came back from, and where they're going next. The **journey** brings that story into one place.

## What you see

Open **My journey** (players) or open a player and pick **Journey** (coaches and head of academy) to see a chronological list of everything that's happened to that player.

Each entry has:
- A short title — *Evaluation on 12 March*, *Promoted to U15*, *Injury: Ankle*.
- A coloured tag for the type — milestones in green/orange, warnings in red, info in grey.
- The date.

The newest entries are at the top by default. Use the date and type filters to narrow down — for example, only events from this season, or only milestones.

## Two views

- **Timeline** — every event, newest first. Use this for the whole story.
- **Transitions** — only the big moments (joined, signed, promoted, released, graduated, PDP verdict). Useful for parent meetings and when a new coach picks up a team.

## What lands on the journey automatically

Most entries appear without anyone typing them in. The journey watches the rest of the system and turns key actions into entries:

- A **new evaluation** on a player → *"Evaluation on 12 March"*.
- A **goal set** for a player → *"Goal set: Improve weak foot"*.
- A **PDP verdict signed off** → *"PDP verdict: promote"*.
- A **player joins a team or moves to a new age group** → *"Team: U13 → U14"* or *"Age group: U13 → U14"*.
- A **trial case is opened** → *"Trial started"*.
- A **trial decision** → *"Trial ended: admit"* (and *"Signed"* on admit, *"Released"* on a final no).
- A **player's status changes** to active, released, or graduated → matching journey entries.

Everything is **idempotent**: re-saving an evaluation does not duplicate the journey entry. Events live alongside their source — the original evaluation, goal, or PDP file is still where you go to edit; the journey just gives you the view.

## Injuries

Injuries get their own page on the player. Open the player → **Injuries** to log a new one with:
- Body part (ankle, knee, hamstring, ...)
- Severity (minor, moderate, serious, season-ending)
- When it started
- When you expect them back
- Notes

When you log an injury, two things happen:
1. An *Injury started* entry lands on the player's journey (visible to medical / head-of-academy roles by default — see Privacy below).
2. A reminder task is scheduled for the player's head coach so they confirm the player is on track or update the expected return date as it approaches.

When the player returns, set **Actual return** on the injury and an *Injury ended* entry lands on the journey.

## Privacy

Not every entry is visible to everyone. Each entry has a **visibility level**:

- **Public** — everyone with access to the player can see it. Most entries default here.
- **Coaching staff** — coaches and admins only. Used for things like *Released*.
- **Medical** — only roles with the medical-view permission see it. Injuries default here.
- **Safeguarding** — only head-of-academy + administrator. Reserved for sensitive entries.

When the journey contains an entry you can't see, you'll see *"1 entry hidden — visible to other roles only."* at the top so the chronology stays honest. The detail itself stays out of sight.

## Cohort transitions (head of academy)

Want to know everyone who got promoted to U15 this year? Or every long-term injury last season? Open **Cohort transitions** under Analytics:

1. Pick an event type (e.g. *Promoted to next age group*).
2. Pick a date range.
3. Optionally narrow to one team.
4. Hit **Run query**.

The result lists every matching player + date + summary. Click **Open journey** on any row to dive into that player's full story.

## Correcting a mistake

If a journey entry is wrong (an evaluation logged for the wrong player, a duplicate trial decision), don't delete it. Open the entry's source — the evaluation, goal, or trial decision — and fix it there. The journey records keep audit trail; the corrected entry replaces the original on the timeline by default. Toggle **Show retracted** on the timeline to see the originals.

## See also

- [Evaluations](evaluations.md) — the source of *Evaluation completed* entries.
- [Goals](goals.md) — the source of *Goal set* entries.
- [Player Development Plan (PDP)](pdp-cycle.md) — the source of *PDP verdict recorded*.
- [Trial cases](trials.md) — the source of *Trial started* and *Trial ended*.
