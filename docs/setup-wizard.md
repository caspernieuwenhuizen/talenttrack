---
title: Setup wizard
group: basics
summary: The first-run guided installer that hands you off into TalentTrack.
audience: [admin]
views: [setup]
module: TT\Modules\Onboarding\OnboardingModule
capability: tt_view_setup_wizard
order: 40
---

# Setup wizard

The setup wizard is the first thing a fresh TalentTrack install shows. It creates the minimum a club needs to start using the plugin: an academy name, a first team, your admin profile, and a frontend dashboard page set as the site homepage.

## Where to find it

The wizard is reachable from four places — pick whichever you find first.

- **First-time install**: a banner appears on the wp-admin TalentTrack dashboard with a "Start setup" button.
- **Returning to it**: while the wizard is still incomplete, a `TalentTrack → Welcome` menu entry sits directly under Dashboard.
- **Configuration tab**: `Configuration → Setup wizard` shows the current wizard state (in-progress / completed) with **Resume** and **Start over** buttons.
- **Account page**: when the wizard isn't completed, `TalentTrack → Account` shows a small "Finish setting up TalentTrack" notice with a Resume button.
- **After completing**: the banner and `Welcome` menu entry disappear, but the Configuration tab and Account-page notice continue to offer "Run wizard again" / "Start over". Restarting the wizard does **not** delete data you already entered — it just walks the form steps again.

## What the steps do

1. **Welcome** — short explanation of the plugin and two buttons: *Set up my academy* (continues into the wizard) or *Try with sample data* (deep-links to the demo data generator under TalentTrack → Demo data so you can explore before committing).
2. **Academy basics** — academy name, primary color, season label, default date format. Saved to `tt_config`.
3. **Import your squad** — bring teams, players and staff in from a spreadsheet rather than typing them again. Uploading previews the file first; nothing is saved until you confirm. You can skip it.
4. **First team** — name + age group. Creates one row in `tt_teams`. You can skip this step and add teams later from the Teams view (players — not teams — support bulk CSV import).
5. **First admin** — confirms your WP account, creates a `tt_people` staff record linked to it, and (optionally) grants you the *Club Admin* role.
6. **Add your staff** — the coaches and staff who will use TalentTrack. An invitation is prepared for each of them and held; nobody is emailed until you send them.
7. **What we send** — which messages TalentTrack sends on your academy's behalf. Nothing is ticked when you arrive, because nothing is being sent: a new install starts silent by design. Tick what you want; the first group is marked *Recommended*. You can skip, and skipping means exactly what it says — see below.
8. **Dashboard page** — creates a WordPress page holding the `[talenttrack_dashboard]` shortcode and sets it as the site homepage, so signing in lands straight on the dashboard. If a page with the shortcode already exists it is reused (and published if it was a draft), never duplicated. You can skip this step, and you can change the homepage later under Settings → Reading.
9. **Done** — summary of what was set up, including how many message types are switched on, and "Recommended next steps" cards (add players, invite first coach, customize branding, set up backups). The **Go to dashboard** button opens the frontend dashboard page created in the previous step (or the wp-admin dashboard if you skipped it).

## The messaging step is the one not to skip

A brand-new academy sends nothing at all. That is deliberate — these are messages to the parents of minors, and TalentTrack does not start mailing them on an academy's behalf before somebody decided it should.

The consequence is worth being blunt about: **if you skip this step, no messages are sent.** Not a cancelled training, not a schedule change, not a safeguarding broadcast. A club that skips it and later cancels a session will find that nobody was told, and will reasonably read that as the product being broken.

Nothing is pre-ticked, because the honest framing is that you are choosing what to switch on rather than what to leave alone. The first group — training cancelled, schedule change, safeguarding broadcast — is marked *Recommended*, which is a recommendation and not a tick made on your behalf.

Whatever you choose here, you can change under **Configuration → Messages**, which is the same setting shown in a fuller form. The step and that screen write the same stored value; there is no second place the decision lives.

**Invitations are not affected.** The invitation email — the one that gets your staff and parents their logins — is account plumbing rather than a message you choose to send, so it is outside this step entirely and outside the Messages screen. Staff you invited on the previous step get their invitations whatever you pick here.

The Done screen shows once, when you finish. Opening the wizard again afterwards shows a short "Setup is complete" line with the reset link, not the summary.

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

Future epics like the monetization trial CTA or the backup setup wizard attach to these hooks rather than modifying the wizard itself.

## State storage

- `tt_onboarding_state` (option) — JSON `{ step, dismissed, payload }`. Per-step form values are kept in `payload` so a page refresh mid-step doesn't lose typing.
- `tt_onboarding_completed_at` (option) — UNIX timestamp set when the dashboard step is completed or skipped.

Resetting the wizard deletes both options.
