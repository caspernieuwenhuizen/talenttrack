<!-- audience: user -->

# Record creation wizards

The "+ New" buttons on Players, Teams, Evaluations, and Goals can either open the flat single-page form (the original design) or a step-by-step **wizard** that asks one or two focused questions per page. The wizard is mobile-friendly, branches when there's a choice that changes the rest of the flow (trial vs. roster player), and pre-fills sensible defaults.

## Who sees what

- **Anyone with permission to create the record** sees the wizard when it's enabled. The capability gate is the same as the flat form.
- **Site admins** can toggle which wizards are enabled under **Administration → Wizards**, and see completion analytics for each.

## How the wizards behave

Each wizard is a small sequence of pages:

- A progress strip shows where you are.
- The button at the bottom right advances to the next step. The middle button skips the current step. The link button cancels.
- Closing the page mid-wizard preserves the answers for an hour, so you can come back to the same view and pick up where you left off (the URL stays the same).
- If your answers change during the flow — say, you switch from "roster" to "trial" on the new-player wizard — only the steps that still apply are run again.

## What's in each wizard

### New player

1. **Type of player** — roster (joining the team) or trial (coming in for a 2 to 6 week look). The rest of the flow branches on this.
2. **Player details** (roster) — name, date of birth, team, jersey number, preferred foot.
2. **Trial details** (trial) — name, date of birth, team being trialed for, trial track (Standard / Scout / Goalkeeper), start and end dates.
3. **Review** — confirm and create.

The trial branch also opens a real trial case automatically, so the player you just added shows up under **Trial cases** with the dates filled in. (Without the Trials module, the wizard still creates the player with status "Trial" so you can come back to it later.)

### New team

1. **Basics** — team name, age group, optional notes.
2. **Staff** — head coach, assistant coach, team manager, physio. Each slot is independently skippable.
3. **Review** — confirm and create. Each filled staff slot becomes a `tt_team_people` row mapped to the matching functional role; people without a `tt_people` record yet are created automatically from their WP user.

### New evaluation

1. **Player** — pick the player.
2. **Type** — pick the evaluation type and date.

The wizard then opens the existing evaluation form pre-filled with those choices. The full eval categories + sub-ratings + attachments form is the same as before — the wizard just gets you to the right form first time.

### New goal

1. **Player** — pick the player.
2. **Methodology link** — optionally link the goal to a principle, football action, position, or value. (Skip = unlinked goal.)
3. **Details** — title, description, priority, due date.

The wizard creates the goal directly. If you picked a methodology link in step 2, a `tt_goal_links` row is added too.

## Toggling wizards on or off

Site admins go to **Administration → Wizards**. Each registered wizard is shown as a tickbox card with its label and slug; the **Enable all wizards** master toggle at the top ticks or unticks the lot. Save the changes with the button at the bottom — there's nothing to type.

Available slugs: `new-player`, `new-team`, `new-evaluation`, `new-goal`, `new-activity`, `new-person`, `new-team-blueprint`, `new-prospect`.

The `new-activity` wizard adds a fifth flow: pick a team → pick the activity type and status → fill in the date / title / location / notes (and the conditional game-subtype or other-label) → review → create. The "+ New activity" button on the frontend Activities manager and on the player profile both route through `WizardEntryPoint::urlFor()`, so toggling `new-activity` off in the admin tickbox takes the surface back to the legacy single-page form. The wizard also offers a **Save as draft** button alongside Cancel: clicking it writes a `draft`-status activity that hides from the regular dropdowns and can be picked up again from the activities list.

Behind the scenes the page stores the choice as `'all'` when every wizard is ticked, `'off'` when none are, and a comma-separated list of slugs in between — same shape `WizardRegistry::isEnabled()` reads, so the change is purely cosmetic.

## Completion analytics

Same admin page shows, per wizard:

- **Started** — how many times the wizard was opened.
- **Completed** — how many times the final step's submit succeeded.
- **Completion rate** — completed ÷ started.
- **Most-skipped step** — which step in the flow gets skipped most often (often a hint that the step is unnecessary or asking the wrong question).

Counters are kept in `wp_options` and roll forward forever; reset by clearing those options if you need fresh numbers (e.g. after refining a wizard).
