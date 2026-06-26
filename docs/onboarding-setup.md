<!-- audience: user -->

# Setup (first-time onboarding)

When you install TalentTrack, the **Setup** flow walks you through the
essentials: naming your academy, creating your first team, registering
your admin profile, and creating the frontend dashboard page. You can run
it as the very first thing you do, or re-run it later to add the bits you
skipped.

Open it from **Configuration → Setup**. The tile opens the frontend Setup
view at `?tt_view=setup` — there is no wp-admin bounce (since #1938). You
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
3. **First team** — name your first team and pick its age group. Players,
   evaluations, activities, and goals all attach to a team, so you need at
   least one. You can **Skip this step** if you would rather add teams
   later under Teams.
4. **First admin** — creates a TalentTrack staff record for the signed-in
   account and links it to your WordPress user, so evaluations, activities,
   and notifications reference the right person. Tick **Grant me the Club
   Admin role** (recommended) to give yourself full management access.
5. **Dashboard page** — creates the frontend page that hosts the
   `[talenttrack_dashboard]` shortcode and sets it as the site homepage, so
   everyone lands on the dashboard when they sign in. If a page with the
   shortcode already exists it is reused, not duplicated. You can **Skip**
   this and set the homepage yourself later under Settings → Reading.
6. **Done** — a summary of what was set up, with **Go to dashboard** and a
   **Run again** button.

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
