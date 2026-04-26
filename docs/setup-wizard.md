# Setup wizard

The setup wizard is the first thing a fresh TalentTrack install shows. It creates the minimum a club needs to start using the plugin: an academy name, a first team, and your admin profile.

## Where to find it

The wizard is reachable from four places — pick whichever you find first.

- **First-time install**: a banner appears on the wp-admin TalentTrack dashboard with a "Start setup" button.
- **Returning to it**: while the wizard is still incomplete, a `TalentTrack → Welcome` menu entry sits directly under Dashboard.
- **Configuration tab**: `Configuration → Setup wizard` shows the current wizard state (in-progress / completed) with **Resume** and **Start over** buttons.
- **Account page**: when the wizard isn't completed, `TalentTrack → Account` shows a small "Finish setting up TalentTrack" notice with a Resume button.
- **After completing**: the banner and `Welcome` menu entry disappear, but the Configuration tab and Account-page notice continue to offer "Run wizard again" / "Start over". Restarting the wizard does **not** delete data you already entered — it just walks the form steps again.

## What the five steps do

1. **Welcome** — short explanation of the plugin and two buttons: *Set up my academy* (continues into the wizard) or *Try with sample data* (deep-links to the demo data generator under Tools so you can explore before committing).
2. **Academy basics** — academy name, primary color, season label, default date format. Saved to `tt_config`.
3. **First team** — name + age group. Creates one row in `tt_teams`. You can skip this step if you want to add teams in bulk via CSV later.
4. **First admin** — confirms your WP account, creates a `tt_people` staff record linked to it, and (optionally) grants you the *Club Admin* role.
5. **Done** — summary of what was set up and four "Recommended next steps" cards: add players, invite first coach, customize branding, create a frontend dashboard page.

## Skip vs dismiss

- **Skip for now** (banner): hides the banner but keeps the menu entry. Good if you want to set up later.
- **Try with sample data** (Welcome step): dismisses the wizard entirely and sends you to the demo data generator. The wizard menu entry stays available; clicking it re-enters at step 1.

## Resetting

A small "Reset wizard" link appears under each step (and on the completion screen). It clears state and returns to step 1. Useful for testing the install on a staging site before going live.

## Hooks for extensions

The wizard fires three actions for other modules to attach to:

```php
do_action( 'tt_onboarding_step_completed', string $step, array $payload );
do_action( 'tt_onboarding_completed' );
do_action( 'tt_onboarding_reset' );
```

Future epics like the monetization trial CTA (#0011) or the backup setup wizard (#0013) attach to these hooks rather than modifying the wizard itself.

## State storage

- `tt_onboarding_state` (option) — JSON `{ step, dismissed, payload }`. Per-step form values are kept in `payload` so a page refresh mid-step doesn't lose typing.
- `tt_onboarding_completed_at` (option) — UNIX timestamp set when step 5 is reached.

Resetting the wizard deletes both options.
