<!-- audience: user, admin -->

# New Evaluation wizard

> Operator + coach reference for the activity-first new-evaluation wizard shipped in v3.75.0 (#0072).

## What it does

A single wizard with an **explicit two-way choice** at the start. The
first step asks *what* you're evaluating, with two big buttons:

- **Evaluate an activity** — *"I just finished training with U14, let me rate the players who were there."* Pick a recent rateable activity, the wizard surfaces present + late players from attendance, you mark attendance, optionally rate, and one Submit records it all.
- **Evaluate 1 player** — *"I noticed something in a tournament I want to capture."* Pick a player, fill date + setting + reason + ratings (and an optional behaviour rating), one Submit creates one evaluation with no activity link.

There is no hidden auto-pick anymore. You choose the path; **Previous**
on the next step returns you to the two buttons so switching is one tap.

Every "activity" door in the app now lands in the same flow: the
dashboard **Mark attendance** hero, the activity **Complete activity**
buttons, and this wizard's *Evaluate an activity* button all reach the
identical `mode=activity` path. (The old `mark-attendance` link still
works — it resolves to this wizard seeded with the activity branch.)

## Path 1 — Evaluate an activity (the daily flow)

`Activity picker → Attendance → Rate now? → Rate players → Review`

### Step 1 · Activity Picker

Lists rateable activities from the last 90 days, on teams you're assigned to via Functional Roles (or all teams if you're HoD / Academy Admin), where the activity type is marked **rateable** in the lookups admin (default: yes; off by default for clinics, methodology lectures, and team meetings).

Click an activity to select it, then **Continue**. If the list is empty, the step tells you so — it never silently jumps to the player path; go **Back** and choose *Evaluate 1 player* to rate a player without an activity. (This step is skipped when a door already preselected an activity, e.g. the dashboard hero or a Complete-activity button.)

### Step 2 · Attendance

Skipped silently when attendance is already recorded for the activity. If shown: tick each player's status (present / late / absent / excused). Default is **present**. The step writes real attendance rows, so the activity itself reflects them afterwards.

For the common "everyone was here" case there's a one-tap shortcut at the top — **Everyone was here - continue** marks the whole roster present and moves straight on to rating in a single tap. Mark any absences on the cards below first if you need to, then use it (or the normal **Next**).

Only **present** + **late** players flow into the rating step. Absent and excused players are recorded for reports but skipped from rating.

### Step 3 · Rate now?

Attendance is saved at this point, so the wizard asks whether you want to rate the present players now. **Rate the present players** continues to the rating step. **Skip rating — I'll rate later** finishes here (the activity stays available for rating later). **Skip rating — no rating needed** finishes and closes the activity for rating (reversible from the activity detail). Either skip marks the activity **completed**.

### Step 4 · Rate players

For each present/late player, you get a row per **quick-rate** category (Technical, Tactical, Physical, Mental by default — clubs can flip individual categories on or off via Configuration → Evaluation Categories). Type a number 1-5 (or whatever your rating-scale max is configured to).

Each player has a **Skip** checkbox if you genuinely don't want to evaluate them this round — skipping writes no evaluation row, but the player still shows up in attendance.

Add per-player notes inline. The deep-rate panel for a single player is a follow-up — for v1, the quick-rate row + the notes textarea are the surface.

**Finding a player on a big roster:** above the list, a **search box** filters the players by name as you type, and an **Only not-yet-rated** toggle hides everyone you've already rated or skipped so you can see who's left at a glance. The toggle works off the same live per-player status as the *"N of M players rated"* progress line, so a player drops out of the not-yet-rated view the moment you rate them. Both are instant, on-device filters — they never change what gets submitted.

**Training default:** when the activity is a training session, the **Mental** category is surfaced first and pre-expanded (its detailed sub-categories shown). This is a presentation default only — you can still rate every other category and you are never required to enter a Mental rating to submit.

The activity path uses **quick rating** — the main categories only. Behaviour ratings live in the *Evaluate 1 player* deep path, not here.

### Step 5 · Review

Lists how many evaluations will be created. If any present player is unrated and not skipped, you get a soft warn at the top: *"X players were present but not rated. Submit anyway, or go back?"* Both buttons available.

Click **Submit**. The wizard writes one `tt_evaluations` row per rated player with `activity_id` set, plus the per-category rating rows, and marks the activity **completed**.

## Path 2 — Evaluate 1 player (ad-hoc, deep)

`Player picker → Deep rate → Behaviour → Review`

### Step 1 · Player Picker

Team-scoped player dropdown. Pick a team, then choose the player from the list — no typing required. When you coach exactly one team it's pre-selected, so the player list is populated the moment the step opens. Head of Development / Academy Admin can switch the team filter (or "All teams") to reach players across the academy.

### Step 2 · Hybrid deep-rate

Date picker (defaults to today), Type dropdown (driven by the `eval_type` lookup), free-text context (max 500 chars), then the rating fields.

Each main category is a **collapsible block, collapsed by default**. The summary line shows the category name, a read-only star mirror, and the average word, so you can scan what's already rated without expanding anything. Tap a category to expand it: rate the category directly, or rate its sub-skills — rating sub-skills sets the category to the rounded average of the non-zero subs, and the summary reflects it live. Collapsing keeps every value.

When you set the Type to **Training**, the **Mental** category jumps to the top of the list and opens automatically. Pick any other type and it returns to its place. It stays a default only — no Mental rating is required to save.

### Step 3 · Behaviour (optional)

Behaviour is tracked separately from performance. This optional pass records conduct, not football: give the player a behaviour rating and an optional one-line note, or leave it blank and tap **Next**. The step is skipped entirely if you don't hold the behaviour-rating permission. This is the only place behaviour is captured — the quick activity path doesn't ask for it.

### Step 4 · Review + Submit

Single evaluation row. Submit creates one `tt_evaluations` row with `activity_id = NULL`, plus a behaviour row when you filled one in.

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
