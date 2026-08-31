---
title: Setup (first run)
group: basics
summary: The flow that walks a new install through its first configuration.
audience: [user]
module: TT\Modules\Onboarding\OnboardingModule
order: 15
---

# Setup (first-time onboarding)

When you install TalentTrack, the **Setup** flow walks you through the
essentials: naming your academy, creating your first team, registering
your admin profile, and creating the frontend dashboard page. You can run
it as the very first thing you do, or re-run it later to add the bits you
skipped.

Open it from **Configuration → Setup**. The tile opens the frontend Setup
view at `?tt_view=setup` — there is no wp-admin bounce. You
need the **Edit settings** capability (`tt_edit_settings`) to see the tile
and run the flow.

## The steps

A stepper at the top shows where you are. Each step saves as you go, so you
can stop and pick up later from the same place.

1. **Welcome** — a short intro, then **Set up my academy** to begin.
2. **Academy basics** — academy name, primary colour, season label, and the
 date format used across the plugin. These appear in the dashboard header,
 on player cards, and in printed reports. You can change them later under
 Configuration.
3. **How much product** — pick an install profile: **Basics**, which keeps
 the development loop and switches off match day, training plans, the
 knowledge library, the integrations and the developer surfaces; or **Full
 academy**, which is everything. Each card lists what it includes, grouped
 the way the Modules page groups them. **Skipping gives you Full academy**,
 which is what an install gets when no profile is chosen. Choosing one
 shows you what was switched before you continue, and nothing is deleted —
 a module switched off keeps its records. It is asked here, before the
 import, so you are not importing into a shape that is about to change. If
 the install has already been configured by hand, this step does not apply
 anything: it sends you to Modules → Install profile, where you see the
 full list of changes before any of them happen.
 Both screens show this step; the in-app one adds a **What would change**
 list per profile, so you can read the whole diff before choosing rather
 than after.
4. **Import your squad** — if you already keep your teams, players and staff
 in a spreadsheet, bring them in here rather than typing them again.
 Download the three-sheet template, fill it in, upload it. **Nothing is
 saved until you confirm**: the first upload only reports what the file
 contains and anything that needs fixing, and a file with problems leaves
 the wizard exactly where it was. **Skip** if you have no spreadsheet.
5. **First team** — name your first team and pick its age group. Players,
 evaluations, activities, and goals all attach to a team, so you need at
 least one. If the import step already brought teams in, this step says so
 and you can simply continue. You can **Skip this step** if you would
 rather add teams later under Teams.
6. **First admin** — creates a TalentTrack staff record for the signed-in
 account and links it to your WordPress user, so evaluations, activities,
 and notifications reference the right person. Tick **Grant me the Club
 Admin role** (recommended) to give yourself full management access.
7. **Add your staff** — add the coaches and staff who will use TalentTrack.
 Give someone an email address and an invitation is prepared for them.
 **Nobody is emailed yet**: invitations are held until you send them, so
 you can finish setting up and look around before anyone is let in. When
 you are ready, **Send N invitations and continue** releases them all. You
 can also continue without sending — the invitations stay ready under
 Configuration → Invitations, and nothing is lost.
8. **What we send** — tick the messages you want TalentTrack to send on
 your behalf. A new academy starts with **everything switched off**, so
 until you choose here, nobody is told anything — not even that a training
 was cancelled. Nothing is pre-ticked, because the choice is yours to make
 rather than ours to make for you; the group people are most annoyed not
 to get is marked **Recommended**. You can **Skip — send nothing for now**
 and set it up later under Configuration → Messages. Invitations to staff
 are not affected either way: they are account plumbing, not one of these
 messages, so people you invited on the previous step still get let in.
9. **Dashboard page** — creates the frontend page that hosts the
 `[talenttrack_dashboard]` shortcode and sets it as the site homepage, so
 everyone lands on the dashboard when they sign in. If a page with the
 shortcode already exists it is reused, not duplicated. You can **Skip**
 this and set the homepage yourself later under Settings → Reading.
10. **Done** — a summary of what was set up, with **Go to dashboard** and a
 **Run again** button.

## Two screens, the same progress

Setup exists in two places and they share one saved progress, so you can
move between them freely and never repeat a step:

- **Configuration → Setup** (`?tt_view=setup`) — inside TalentTrack.
- **TalentTrack → Welcome** in the WordPress admin — where a brand-new
 install lands on its first run.

One step is only available in the WordPress admin for now: **Add your
staff**. It carries something the in-app screen does not have yet — the
held-invitation flow. If your saved progress is on one of
those, the in-app screen says so, tells you your progress is kept, and
offers to carry on in the WordPress admin. Come back afterwards and the
flow picks up where the admin left it.

Everything else — Welcome, Academy basics, How much product, Import your
squad, First team, First admin, What we send, Dashboard page and Done —
works on both screens.

## Stop and resume

Your progress is saved automatically. Close the tab and come back to
**Configuration → Setup** any time — you land on the step you left off on.

## Run again

Once setup is complete, opening **Configuration → Setup** shows the
completion summary. Click **Run again** to start over from the welcome step.
Re-running does **not** delete the data you already created — your teams,
staff records, and pages stay; you just walk the flow again. The same
**Start over** affordance is available mid-flow if you want to reset your
progress without finishing.

## Cancel

Every step offers **Cancel** alongside the continue/save action. Cancel
returns you to Configuration without losing anything you have already
saved.

## For developers — REST surface

The flow is a plain frontend view (not the record-creation wizard
framework); it reuses the existing onboarding domain layer
(`OnboardingState` + `OnboardingHandlers`) through
`OnboardingRestController`. Every endpoint gates its `permission_callback`
on `tt_edit_settings`. See `docs/rest-api.md` for the route table.
