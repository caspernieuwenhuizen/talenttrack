<!-- audience: user, admin -->

# New Evaluation wizard

> Operator + coach reference for the activity-first new-evaluation wizard shipped in v3.75.0 (#0072).

## What it does

A single wizard with two paths through it. Pick the path that matches what you actually did:

- **Activity-first** — *"I just finished training with U14, let me rate the players who were there."* Pick a recent rateable activity, the wizard surfaces present + late players from attendance, you quick-rate or deep-rate each, one Submit creates N evaluations.
- **Player-first (ad-hoc)** — *"I noticed something in a tournament I want to capture."* Pick a player, fill date + setting + reason + ratings, one Submit creates one evaluation with no activity link.

The wizard auto-picks the path. If you have at least one rateable activity in the last 30 days on a team you coach, you land on the activity picker. Otherwise you land directly on the player picker. Both landings have an escape-hatch link to the other path.

## Path 1 — activity-first (the daily flow)

### Step 1 · Activity Picker

Lists rateable activities from the last 30 days, on teams you're assigned to via Functional Roles (or all teams if you're HoD / Academy Admin), where the activity type is marked **rateable** in the lookups admin (default: yes; off by default for clinics, methodology lectures, and team meetings).

Click an activity to select it, then **Continue**. Or click **→ Rate a player directly** to switch to the player-first path.

### Step 2 · Attendance

Skipped silently when attendance is already recorded for the activity. If shown: tick each player's status (present / late / absent / excused). Default is **present**. The step writes real attendance rows, so the activity itself reflects them afterwards.

Only **present** + **late** players flow into the rating step. Absent and excused players are recorded for reports but skipped from rating.

### Step 3 · Rate players

For each present/late player, you get a row per **quick-rate** category (Technical, Tactical, Physical, Mental by default — clubs can flip individual categories on or off via Configuration → Evaluation Categories). Type a number 1-5 (or whatever your rating-scale max is configured to).

Each player has a **Skip** checkbox if you genuinely don't want to evaluate them this round — skipping writes no evaluation row, but the player still shows up in attendance.

Add per-player notes inline. The deep-rate panel for a single player is a follow-up — for v1, the quick-rate row + the notes textarea are the surface.

### Step 4 · Review

Lists how many evaluations will be created. If any present player is unrated and not skipped, you get a soft warn at the top: *"X players were present but not rated. Submit anyway, or go back?"* Both buttons available.

Click **Submit**. The wizard writes one `tt_evaluations` row per rated player with `activity_id` set, plus the per-category rating rows.

## Path 2 — player-first (ad-hoc)

### Step 1 · Player Picker

Search-based picker (autocomplete on player name + team). Select the player you observed.

### Step 2 · Hybrid deep-rate

Date picker (defaults to today), setting dropdown (training / match / tournament / observation / other — driven by the `evaluation_setting` lookup), free-text context (max 500 chars), then the rating fields per category.

### Step 3 · Review + Submit

Single evaluation row. Submit creates one `tt_evaluations` row with `activity_id = NULL`.

## Cross-device drafts

Drafts persist across browsers and devices. If you start rating on your phone and don't finish, opening the wizard later on your desktop resumes where you left off — same activity, same partial ratings, same notes.

The persistent store keeps drafts for **14 days**. Stale drafts are pruned by a daily cron. If a club wants a different TTL, drop a `tt_wizard_draft_ttl_days` filter into a small custom plugin.

## Who can use it

- **Assistant Coach** — RC team on evaluations. Can create + edit ratings on teams they're assigned to.
- **Head Coach** — RCD team. Same plus delete.
- **Head of Development / Academy Admin** — RCD global. Anywhere.
- **Team Manager** — R team only. The wizard is correctly inaccessible.
- **Player / Parent** — no access (the wizard is staff-side only).

## Marking activity types as rateable

In Configuration → Lookups → Activity Types, each row has a **Rateable** checkbox. When unchecked, activities of that type vanish from the new-evaluation wizard's activity picker — they remain visible everywhere else (the activity itself, stats, reports). Useful for clinics, methodology lectures, team meetings.

## Marking categories as quick-rate

In Configuration → Evaluation Categories, top-level categories have a **Quick rate** flag (in `meta.quick_rate`). Quick-rate categories appear as a single-line row in the wizard's rating step. Non-quick categories live in the deep-rate panel (follow-up). Default seed: Technical / Tactical / Physical / Mental.

## Autosave (v3.78.0)

Every wizard step now autosaves. As you type or change a field the wizard waits ~800ms then quietly POSTs your input to `POST /wp-json/talenttrack/v1/wizards/{slug}/draft`, which merges the patch into your `tt_wizard_drafts` row. A small status caption next to the action buttons shows the state — "Autosave ready" → "Saving…" → "Saved · 14:32".

No validation runs on autosave; that's deliberate. Half-typed input is the point. Validation still runs on **Next** via the step's normal submit path. If the network drops, the caption shows "Save failed" and the next typing burst retries automatically.

## Resume banner (v3.78.0)

When you re-enter a wizard with a draft older than ~10 minutes (the cross-session signal), a banner at the top says *"You started this 2 hours ago. Continue where you left off, or start over?"* Click **Continue** to keep going, or **Start over** to wipe the draft and begin fresh. Same-session reloads (faster than 10 minutes) skip the banner because there's nothing to resume from.

## Per-player progress at submit (v3.78.0)

Review-step Submit now drives one POST per evaluation row to `POST /wp-json/talenttrack/v1/wizards/new-evaluation/insert-row`, with a progress bar and "Writing evaluation 3 of 12…" status. Same DB rows as before; the only difference is visible feedback during a 12-player batch. JS-disabled browsers fall back to the v3.75.0 PHP-only one-shot submit.

## What's still on the roadmap

These polish items are queued as follow-ups:

- Locked / Editable badges on the activity picker (24h edit window with countdown, "Edit (post-window)" for HoD/Admin).
- Mobile vs desktop responsive split for the rating step (one-player-at-a-time on mobile vs full vertical list on desktop, with swipe gestures).
