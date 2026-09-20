---
title: Trial cases
group: performance
summary: 'Run a structured trial period: track templates, staff input, decision and the letter that goes to parents.'
audience: [user]
views: [trials, trial-case, trial-tracks-editor, trial-letter-templates-editor, trial-parent-meeting, test-trainings]
module: TT\Modules\Trials\TrialsModule
tier: pro
order: 130
---

# Trial cases

A **trial case** is a structured way to run a 2–6 week look at a prospective player and end the period with a clear, well-communicated decision. It bundles the parts that used to live in spreadsheets and emails: who is trialing, on which track, who is seeing them, what the inputs are, what the decision is, and the letter that goes to parents.

## Who sees what

- **Head of development / Club admin** — full management. Open / extend / decide / archive cases. Edit tracks and letter templates. Release staff inputs.
- **Head coaches** — can open the **Trial cases** tile and browse the list of cases for players on their own teams. They cannot create or delete cases (no *New trial case* button), but they keep the read view their role grants. Creating, deciding and archiving stay with the head of development.
- **Coaches assigned to a case** — see the case overview and submit their own input on the **Staff inputs** tab. They see other coaches' inputs only after the head of development releases them. Whether they also get the **Execution** tab depends on their role: it aggregates what the other coaches have said, so it needs the same permission as reading the released inputs. An assistant coach assigned to a case gets Overview and Staff inputs.
- **Scouts on a case's panel** — the scout who found a player can sit on their panel and write their assessment. They see the case they are assigned to and their **own** input; they do not get the Execution tab, so other panellists' views stay private until the head of development releases them. Their case list shows only the panels they are actually on, never the club's cases. A scout who is unassigned from a panel loses the case immediately.
- **Other coaches and scouts** — do not see the case at all.

Two of those work differently underneath, and it matters when you are wondering why somebody cannot see something:

- **Whether a role can browse trial cases at all is a permission on the role**, set in the authorization matrix — not something being assigned to a case grants. Assigning an assistant coach to a case lets them write an input on it; it does not turn a role that has no trials permission into one that has.
- **Assignment then narrows it further.** Even a role that can read trial cases only reaches the individual case's input and synthesis tabs when they are actually on the case.

So "assign them and they will see it" is only half true, and if a colleague cannot open a case the first place to look is the matrix, not the case's staff list.

## The flow

### 1. Open a case

From the **Trial cases** tile, pick *Add case*. That opens a short guided flow in three steps:

1. **Player** — search for somebody already on the books, or fill in a new player's first name, last name and date of birth.
2. **Trial** — pick a track (Standard / Scout / Goalkeeper, or any custom track the club added). The track proposes an end date from its usual length; change it if the trial has been agreed for a different period. Add notes if there is anything the next person should know.
3. **Staff** — who is watching. Optional, and more can be added later, but only assigned staff can submit input on the case. This step shows a summary of what is about to be created before you finish.

Nothing is written until you finish the last step. Backing out halfway leaves no half-made player and no empty case.

The player's status flips to **Trial** automatically when the case opens.

A player has **one open trial at a time**. If they already have a case that is open or extended, a new one is refused, and the message names the case that is open, with a link to it. To make a trial longer, extend the open case. Once it's decided or archived, a new case can be opened.

If your academy has the guided flows switched off, *Add case* opens the older single-page form instead. It asks for the same things and behaves identically — same checks, same journey entries.

Opening the case writes **Trial started** to the player's journey, so the trial shows on their timeline from day one. If you created the player inline on this form — first name, last name and date of birth — that also writes **Joined the academy**, the same as adding them from the Players screen. The trial is where a trial player came from, and the timeline should say so without anyone having to add a note.

Because the inline fields go through the normal player create, an academy that has made a custom player field **required** cannot use the shortcut: the form will say which field is missing. Add the player from the Players screen first, then pick them here.

### 2. Watch the case run

The **Execution** tab on the case page aggregates everything that happens during the trial window — activities the player attended, evaluations written, goals created or updated, plus a small synthesis (rolling rating, evaluation count). Nothing is duplicated; the data sits in the normal places, the Execution tab just filters to the trial window.

If the period needs to be extended, the **Extend trial** button on Overview asks for a new end date and a mandatory justification note. Each extension is logged with who, when, and why.

### 3. Collect staff input

Each assigned coach has their own input form on the **Staff inputs** tab. They enter an overall rating and notes, save as draft, and submit when ready. A coach sees only their own draft until the head of development clicks **Release submitted inputs to assigned staff** — that prevents groupthink during the period and lets everyone see the picture once everyone has submitted.

The system also sends gentle reminders to staff who haven't submitted as the trial ends approaches (7 days out, 3 days out, on the end date).

#### When an input stops being editable

**At the decision, not at submit.** While the case is **Open** or **Extended** an assigned coach can keep correcting their own input — including after they have submitted it. Re-reading your own wording an hour later and fixing a sentence is normal practice and should not need a manager.

On the **Staff inputs** tab, an input you have already submitted keeps its form. Above it a line says when you handed it in and that you can still edit it until the trial is decided, and the form carries a single **Save changes** button instead of **Save draft** / **Submit input**. Saving does not un-submit the input: the submission time stays as it was, the head of development's "N of M assigned staff have submitted" count does not move, and neither does the **Release submitted inputs** button.

Once the case is **Decided** or **Archived**, inputs are frozen. Nothing can change them, on any screen or through the API, and an attempt to do so is refused with a message saying why rather than quietly doing nothing.

That line is where it is because a staff input is the evidence behind a decision about a child — whether the academy wanted them, and why. It is also the part of the trial record most likely to be read a season later, when the player comes back or the family asks. A record that can be rewritten after the fact, with no earlier version kept, cannot serve either purpose.

Freezing the input does **not** close the case to the coach who wrote it. They can still open a decided case and read what they said.

### 4. Decide

On the **Decision** tab, the head of development picks one of three outcomes:

- **Admit** — offer a place. Player status → Active.
- **Decline (final)** — no place this season. Player status → Archived.
- **Decline (with encouragement)** — no place this season, but a warm invitation to try again. The decision form asks for a few sentences about strengths and growth areas; those go straight into the encouragement letter.

The decision form requires a justification note (≥ 30 characters) for the internal record.

### 5. Generate the letter

Recording a decision generates the letter automatically. The **Letter** tab shows it inline and offers a print-ready view. **Print view** opens the letter on its own — no navigation, no tabs, no letter history, just the letter and a Print button — so what comes out of the printer is what you hand to the family. Only the head of development and club admin can open it; anyone else following the link gets a refusal. Three templates ship with the plugin:

- **Admittance** — warm welcome, next steps, optional acceptance slip on page 2 if the club has that turned on.
- **Decline (final)** — respectful and definitive.
- **Decline (with encouragement)** — names what stood out and where to keep working, with an explicit invitation to re-apply.

Every letter is headed with the academy's name, and `{club_name}` resolves to it anywhere you use it in a template. That name comes from **Configuration → Academy name**; set it before the first letter goes out, because an academy that has left the field empty gets the WordPress site title on the letterhead instead. Changing the name does not rewrite letters that have already been generated — regenerate a letter if you need the new name on it.

### Record that the family has it

Generating a letter does **not** send it. TalentTrack writes the letter and keeps it; getting it to the family is still a human step — you print and post it, attach it to an email, or hand it over at the end of the meeting.

So that the next person to open the case can tell a family still waiting from one already told, the **Letter** tab has a **Delivery** card under the letter. It reads *Not recorded as delivered yet* until someone picks how the family got it — **Printed and posted**, **Emailed** or **Handed over in person** — and presses **Record delivery**. After that it reads *Delivered on 3 October 2026 by Anne de Vries (Emailed)*, and offers **Clear delivery record** if the wrong letter was ticked.

The letter history table carries a **Delivered** column, so a case that has been reopened and re-decided shows at a glance which of its letters actually reached the family. Letters generated before this existed read as *Not recorded* — there was nothing to backfill from, and a guess would be worse than a blank.

Nothing about this sends mail, sets a reminder, or chases anyone. It is a record.

The shipped Dutch letters use a warm, informal "je/jullie" club voice. If the wording isn't quite right for your club, **Letter templates** (under the Trials tile group) lets you customise each letter per language. The editor opens with a short guidance note, lists each letter under a plain-language name ("Offer of a place", "No place — with encouragement", …), and shows a side panel of every variable you can substitute (`{player_first_name}`, `{trial_end_date}`, `{strengths_summary}`, …) plus a live preview with sample data. Unknown variables are left as literal `{foo}` so missing pieces are visible in the preview.

### 6. Have the conversation with the parents

The **Parent meeting** tab opens a fullscreen, sanitized view designed to be shown on a laptop or tablet during the meeting. It deliberately omits internal data — no individual staff ratings, no attendance percentages, no justification notes. What's shown: photo, player name and age, decision outcome, and the letter ready to print or email.

## Tracks

Tracks are templates that decide the default trial duration. Three ship with the plugin (Standard / Scout / Goalkeeper) and clubs can add their own through **Trial tracks**. Existing cases keep working when a track is archived; new cases just don't see the archived option.

## Acceptance slip (optional)

For admit decisions, the club can include an acceptance slip on page 2 of the letter. **Letter templates → Acceptance slip** turns it on, sets the response deadline (in days from the letter date), and the return address. The address you save there is what the slip asks families to return the page to, and `{club_address}` in a template resolves to it; leave it empty and the slip falls back to "the club office". After the slip comes back signed, mark it received from the Decision tab.

## Closing a trial case

A case stays "open" — visible to the assigned staff, counting against the head of development's active workload — until it is either **decided** or **archived**. Two paths, two different intents:

### Decide (the normal path)

Use the **Decision** tab to record an outcome (`Admit` / `Decline (final)` / `Decline (with encouragement)`) plus the mandatory ≥ 30-character justification note. Recording the decision:

- Moves the player's status, per the table below.
- Writes the matching entry on the player's journey — *Trial ended*, plus *Signed* on an admit or *Released* on a final decline.
- Stamps `decision_made_at` + `decision_made_by` for the audit trail.
- Keeps the justification note itself, readable afterwards by anyone who may read the case's staff inputs — the head of development and the coaches assigned to it. It does **not** go to the family: the letter and the parent-meeting view carry none of it.

The 30-character floor is counted in characters, so a short motivation written with accents or other non-Latin letters is measured the same way as one written without them. The screen and the API apply the same rule — there is one definition of it — so a motivation either surface accepts is accepted by both.

If the motivation is too short, the Decision tab says so under the field, naming the minimum and how many characters you wrote, **and keeps what you typed**. Nothing is recorded, nothing is sent, and the outcome you picked is still selected when the page comes back.

Recording the decision moves the player's status once, through the decision itself — see the table below. No screen writes that status separately, so the status a player ends up with does not depend on whether the decision was recorded on the page or through the API.

| Decision | The player becomes | Archived? |
| --- | --- | --- |
| Admit | **Active** | no |
| Decline (final) | **Released** | yes — the record goes to the recycle bin, where it can be restored |
| Decline (with encouragement) | **Inactive** | **no** |

The third row is the one worth reading twice. *Decline with encouragement* means "not now, come back" — so the player stays on the books, findable, and eligible for a future trial. Archiving that record would tell your own system the opposite of what you just told the family. Only a final decline ends the relationship.

Only a player who is still on **Trial** status moves. If they were already promoted some other way, or the decision is recorded a second time, nothing changes — the decision cannot walk an active player backwards.

The letter is **not** generated automatically. Go to the **Letter** tab and generate it when you are ready; someone should read a letter to a family before it exists. The Parent meeting tab carries the rest of the conversation.

### The six decisions, and which of them close the trial

Three more decisions exist beyond the Decision tab's three. They are written by the trial-group workflow tasks — **Review trial group membership** and **Await team-offer decision** — rather than typed on the Decision tab, and they do not all mean the trial is over:

| Decision | Where it is recorded | On the journey |
| --- | --- | --- |
| Admit | Decision tab, or accepting a team offer | *Trial ended* + *Signed* |
| Decline (final) | Decision tab, or Review trial group membership | *Trial ended* + *Released* |
| Decline (with encouragement) | Decision tab | *Trial ended* |
| Family declined the offered place | Await team-offer decision | *Trial ended* |
| Offered a team place | Review trial group membership | **nothing** |
| Continue in the trial group | Review trial group membership | **nothing** |

The last two write nothing on purpose. *Continue in the trial group* says the trial is **still running**, so a *Trial ended* entry would be actively wrong rather than merely missing; *Offered a team place* is mid-conversation, and the family has not answered yet. The answer lands one task later, on Await team-offer decision, and that is what closes the trial.

Until recently the three workflow decisions reached the timeline not at all, so a trial that ended because a family declined the offered place showed as a trial that started and never finished. Cases decided before that stay as they were unless the journey is rebuilt.

An offer being made is arguably the most significant moment in a trial and still appears nowhere on the journey. That needs an entry type of its own and is not part of this.

### Archive (the "no answer needed" path)

When you don't owe the family a formal decision — the family ghosted you, the player moved cities, the case was opened by mistake — the **Archive case** action closes the case without writing a decision row or generating a letter. It's available from the header action bar on the case page (manager / head-of-development cap required). The case stays in the database (you can still find it by searching archived cases); it just stops counting as open work.

If you archive a case that should have had a decision and then realise the family is willing to talk after all, an admin can un-archive from the wp-admin trial-cases list.

## Retention

Letters are persisted with a 2-year expiry. Archive is the default — denial letters are not deleted automatically because the club may need them as evidence for reconsiderations or appeals. A separate GDPR deletion flow handles permanent erasure on parent request.

## Case page layout

The trial case page follows the same layout as the player and team profiles: a paper hero anchored by the player's photo and name (a trial is a key moment in that player's journey, so the player stays the subject of the page), pills for trial status / decision / track, a key-facts strip (Track · Trial window · Status · Decision), then the content in cards under tab navigation — **Overview · Execution · Staff inputs** for everyone, plus **Decision · Letter · Parent meeting** for the head of development. The hero links back to the full player profile, and the page emits the standard breadcrumb chain (Dashboard → Trials → Trial: <player>).

Close affordances live in the action row under the hero: **Record decision** (while the case is undecided), **Archive case**, and an overflow menu with **Delete permanently** for admins.

Trial players also surface on the team detail page now, under their own **Trial players** subsection. Previously they were hidden behind the active-status filter on the team roster.
