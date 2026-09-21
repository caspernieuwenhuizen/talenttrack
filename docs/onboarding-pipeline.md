---
title: Onboarding pipeline
group: configuration
summary: The recruitment funnel every arriving player passes through.
audience: [user]
views: [onboarding-pipeline, prospects-overview]
module: TT\Modules\Prospects\ProspectsModule
order: 124
---

# Onboarding pipeline

The **Onboarding pipeline** is the recruitment funnel — every player who arrives at the academy passes through it. Open it from the dashboard tile (Academy group) or directly via `?tt_view=onboarding-pipeline`.

## What it shows

Seven columns laid out left to right, one per stage of the journey from "scout spotted them" to "playing for the academy":

| Column | What's in it |
|---|---|
| **Prospects** | Drafted but not yet handed off to the Head of Development. New entries created via the wizard skip this column — they go straight to *Invited*. Anything in here is either a legacy `log_prospect` task draft or a chain that abandoned mid-flow. |
| **Consent requested** | The academy has asked this child's own club to pass a consent request on to the family, and is waiting for an answer. |
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
3. **Parent contact** — name, email, phone. **All of it is optional.** If the family has not been approached yet, leave the whole step empty: a scout who spotted a child at another club has no family details and must not go and collect them. What has *not* relaxed is the consent rule — enter any contact detail and you must tick the consent box. You may hold nothing about a family, or hold their details with their agreement; never their details without it.
4. **Review** — confirm the answers and create.

On submit:

- The prospect record is created.
- A task is dispatched to the Head of Development to invite the prospect to a test training.
- You're returned to the pipeline view, where the new card appears in the **Invited** column.

The wizard is the canonical "+ New prospect" entry point. Clicking the button opens it and nothing else — no workflow task is created as a side effect.

## Permissions

- **`tt_view_prospects`** — required to open the pipeline. Granted by default to Academy Admin, Head of Development, Scout and Head Coach.
- **`tt_edit_prospects`** — required to launch the New prospect wizard. Granted to Academy Admin, Head of Development and Scout — a head coach reads the funnel, they do not add to it.
- **`tt_invite_prospects`** — required to complete the *Invite to test training* task (HoD path).

### Who sees which prospects

The capability opens the board. **What is on it depends on your scope.**

- **Academy Admin, Head of Development and Scouts** see every prospect in the
  academy. Scouts used to be narrowed to their own discoveries; that changed
  when two scouts working the same pool needed to see each other's visits.
- **A head coach** sees the funnel feeding their own squads: prospects logged
  for one of their **age groups**, anyone already promoted into one of their
  teams, and anything they logged themselves.

A prospect record carries an age group, not a team — they have not joined yet,
so there is no squad to belong to. A prospect with **no** age group recorded and
no promotion yet is visible only to the academy-wide roles and to whoever logged
them. If a head coach says a prospect is missing from their board, the age group
on the prospect record is the first thing to check.

The counts on the dashboard tile follow exactly the same rule, so the tile and
the board always agree.

## Stage rules

Each prospect belongs to **exactly one** column. The classifier runs in this order:

1. Promoted to a player within the last 90 days → **Joined**.
2. Has an open *Await team offer decision* task → **Team offer**.
3. Has been admitted to a trial group → **Trial group**.
4. Has an open *Record test training outcome* task → **Test training**.
5. Has an open *Invite to test training* or *Confirm test training* task → **Invited**.
6. Has an open *Request consent from the family* task → **Consent requested**.
7. Otherwise (no open task, not promoted, not archived) → **Prospects**.

Rule 6 sits below the invite rules on purpose: a prospect who has been invited has plainly got past consent, whatever a stale consent task still says.

The dashboard widget uses the same classifier for its compact count strip, so the numbers on the dashboard match the columns on the standalone page. A prospect counts once, in one column, however many tasks are open against them.

## Asking the family, and recording that you asked

Between "I spotted a child at another club" and "the family has said yes" there is a real step: asking the child's own club to pass the request on. It has a place of its own now.

**The task.** *Request consent from the family* is a workflow task, assigned to the scout who found the prospect, due in 21 days. While it is open the prospect sits in the **Consent requested** column. It spawns nothing on completion — a request that came back *declined* must not produce an invitation.

**The log.** Completing the task, or posting to `POST /prospects/{id}/consent-requests`, writes a dated entry: the date you asked, the club or coordinator you asked, the outcome, and optional notes. Every entry for a prospect shows on the focus panel when you click their card, newest first. That trail is the answer to "did the consent requests go out?", which used to live only in a scout's memory.

Four outcomes: *waiting for an answer*, *the family agreed*, *the family declined*, *no reply*.

**The log holds nothing about the family.** No name, no email, no phone, no address — only the route the academy used. That is the whole point of the step: the academy went through the child's club and did not collect family data before it was allowed to. Family contact lives on the prospect record, behind the consent rule that has always guarded it.

**An open request holds the retention clock.** A prospect with no progress is purged after 90 days. An entry with the outcome *waiting for an answer* counts as progress, so an academy that is genuinely waiting does not lose the child out from under it. The clock runs from the entry, not the prospect, so a request nobody ever chased still ages out on the normal rule. When a prospect is purged its consent entries go with it.

## No invitation without consent

*Invite to test training* refuses to submit unless there is consent on record — either a consent date on the prospect, or a consent request that came back *agreed*.

**There is no override and no exception.** A child whose family has not agreed is not invited to a test training, and there is no button that says otherwise. If consent genuinely arrived by a route the system does not know about — a conversation at the touchline, a reply to a club's own email — the way forward is to **record** it, on the prospect or as a consent-request entry marked agreed, and then send the invitation. Completing the invite task is what moves a prospect to *Invited*, so this is the same seam as the stage transition.

## Putting a stuck prospect forward

A prospect only reaches *Invited* when somebody holds the **Invite to test training** task, and that task used to be created in one place only: the moment a prospect was logged through the wizard. So a prospect whose chain was cancelled, who was logged while the pipeline workflow was switched off, who was imported, or who was seeded by the demo generator stayed in the first column with nothing to click.

Click their card, and the panel that opens above the board now offers a way forward:

- **Propose test training** — for a scout, or anyone else who may add to the funnel but not issue the invitation. It asks the Head of Development to arrange one. The prospect's next action becomes *Invite to test training* straight away, and the card moves to **Invited** once the HoD sends it.
- **Arrange test training** — for the Head of Development and anyone else holding `tt_invite_prospects`. They do not need to ask themselves for permission, so the button takes them to the New test training form instead of creating a task. **The prospect comes with them**: the form opens with that child already picked, and saving records the invitation against them.

### The prospect on the New test training form

**Configuration → Test trainings → New**, or the pipeline's *Arrange test training* button, which is the same form reached at `?tt_view=test-trainings&action=new&prospect_id=…`.

- **Arriving from a prospect's card**, the **Prospect** field opens with that child selected. Save, and they move to **Invited** — exactly as if the *Invite to test training* task had been completed for them, because that is what is recorded underneath.
- **Arriving cold**, the field is an ordinary picker set to *Nobody yet*. Scheduling an open session and attaching children later is a normal thing to do, so nothing is required here.
- **An id that does not resolve** — a mistyped URL, a prospect somebody else logged and you cannot see, one already promoted or archived — opens the field empty and says nothing further. It deliberately does not tell you whether the prospect exists.
- The picker offers only prospects you may invite. Without `tt_invite_prospects` the field is not shown at all, and the form still schedules an unattached session.
- **The consent rule applies here too.** Attaching a child whose family has not agreed is refused with the same message the invite task gives, and nothing is saved — not even the session.

The two routes converge: whether the invitation was arranged from the task or from this form, the record left behind is the same completed *Invite to test training* task, so the board, the stage classifier and the reports all read one shape of the fact.

Proposing twice does nothing the second time, and two scouts proposing the same prospect produce one request between them — the Head of Development is asked about a child once.

The button appears only when there is nothing else to do: a prospect with any open pipeline task, one who has already been invited, one who has been promoted to a player or a trial case, and an archived one all show their own next action instead. With the `onboarding_pipeline_workflow` feature switched off there is no button at all, because there would be no task to create.

## What the wizard skips

The legacy chain dispatched a `LogProspectTemplate` task as the first step, which then handed off to `InviteToTestTrainingTemplate`. The wizard *is* the form that LogProspect's task wrapped, so creating that task to capture data the wizard already collected was a redundant step. The wizard goes straight to `InviteToTestTrainingTemplate`.

`LogProspectTemplate` and the `/prospects/log` REST endpoint stay in place for backward compat — external integrations (e.g. the parent self-confirmation token endpoint) and any custom workflow trigger that calls them keep working.
