<!-- audience: user -->

# Onboarding pipeline

The **Onboarding pipeline** is the recruitment funnel — every player who arrives at the academy passes through it. Open it from the dashboard tile (Academy group) or directly via `?tt_view=onboarding-pipeline`.

## What it shows

Six columns laid out left to right, one per stage of the journey from "scout spotted them" to "playing for the academy":

| Column | What's in it |
|---|---|
| **Prospects** | Drafted but not yet handed off to the Head of Development. New entries created via the wizard skip this column — they go straight to *Invited*. Anything in here is either a legacy `log_prospect` task draft or a chain that abandoned mid-flow. |
| **Invited** | The HoD is composing or has sent the test-training invitation, or the parent's confirmation is pending. |
| **Test training** | The test training has been scheduled or has happened — the HoD is recording the outcome. |
| **Trial group** | The prospect was admitted to the trial group and is being assessed there. |
| **Team offer** | A coach has offered the prospect a team slot; awaiting decision (parent + player). |
| **Joined** | The prospect was promoted to a player record within the last 90 days. |

Each column shows a count and a stack of cards — one card per prospect. Cards show the player's name, age (or DOB), current club, and a context line per stage. Click a card to open whatever's actionable for that prospect right now (the open task form for the active stage; the player profile for promoted ones).

A pale-orange card with a *stale* badge means the open task on that prospect is more than 30 days past its due date.

## Adding a new prospect

Click **+ New prospect** at the top. The wizard walks through:

1. **Identity** — first / last name, date of birth, current club. Duplicate detection runs here — if a prospect with the same name already exists, you have to tick the "this is a new entry" override before continuing.
2. **Discovery** — where you spotted them (event / match), short scouting notes.
3. **Parent contact** — name, email, phone. At least an email or a phone is required so the HoD can reach the parent. Tick the consent box (required for the academy to hold parent contact data).
4. **Review** — confirm the answers and create.

On submit:

- The prospect record is created.
- A task is dispatched to the Head of Development to invite the prospect to a test training.
- You're returned to the pipeline view, where the new card appears in the **Invited** column.

The wizard is the canonical "+ New prospect" entry point — clicking the button no longer creates a workflow task as a side-effect (a regression resolved in v3.110.48; previously the click POSTed to `/prospects/log` and dropped you into a `Log prospect` task in My Tasks, which surprised users and double-counted the entry).

## Permissions

- **`tt_view_prospects`** — required to open the pipeline. Granted by default to Academy Admin, Head of Development, and Scout.
- **`tt_edit_prospects`** — required to launch the New prospect wizard. Same default grants.
- **`tt_invite_prospects`** — required to complete the *Invite to test training* task (HoD path).

Scouts see only their own prospects (filtered by `discovered_by_user_id`); HoD and Academy Admin see every prospect across the academy.

## Stage rules

Each prospect belongs to **exactly one** column. The classifier runs in this order:

1. Promoted to a player within the last 90 days → **Joined**.
2. Has an open *Await team offer decision* task → **Team offer**.
3. Has been admitted to a trial group → **Trial group**.
4. Has an open *Record test training outcome* task → **Test training**.
5. Has an open *Invite to test training* or *Confirm test training* task → **Invited**.
6. Otherwise (no open task, not promoted, not archived) → **Prospects**.

The dashboard widget uses the same classifier for its compact count strip, so the numbers on the dashboard match the columns on the standalone page. Before v3.110.48 the widget summed task rows across templates, so a single prospect with both an Invite and a Confirm task open at once showed as 2 in the Invited column — fixed.

## What the wizard skips

The legacy chain dispatched a `LogProspectTemplate` task as the first step, which then handed off to `InviteToTestTrainingTemplate`. The wizard *is* the form that LogProspect's task wrapped, so creating that task to capture data the wizard already collected was a redundant step. The wizard goes straight to `InviteToTestTrainingTemplate`.

`LogProspectTemplate` and the `/prospects/log` REST endpoint stay in place for backward compat — external integrations (e.g. the parent self-confirmation token endpoint) and any custom workflow trigger that calls them keep working.
