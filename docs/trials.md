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
- **Other coaches** — do not see the case at all.

Two of those work differently underneath, and it matters when you are wondering why somebody cannot see something:

- **Whether a role can browse trial cases at all is a permission on the role**, set in the authorization matrix — not something being assigned to a case grants. Assigning an assistant coach to a case lets them write an input on it; it does not turn a role that has no trials permission into one that has.
- **Assignment then narrows it further.** Even a role that can read trial cases only reaches the individual case's input and synthesis tabs when they are actually on the case.

So "assign them and they will see it" is only half true, and if a colleague cannot open a case the first place to look is the matrix, not the case's staff list.

## The flow

### 1. Open a case

From the **Trial cases** tile, pick *New trial case*. Choose the player (or create a new one first), pick a track (Standard / Scout / Goalkeeper, or any custom track the club added), set start and end dates, and assign initial staff. The player's status flips to **Trial** automatically.

Opening the case writes **Trial started** to the player's journey, so the trial shows on their timeline from day one. If you created the player inline on this form — first name, last name and date of birth — that also writes **Joined the academy**, the same as adding them from the Players screen. The trial is where a trial player came from, and the timeline should say so without anyone having to add a note.

Because the inline fields go through the normal player create, an academy that has made a custom player field **required** cannot use the shortcut: the form will say which field is missing. Add the player from the Players screen first, then pick them here.

### 2. Watch the case run

The **Execution** tab on the case page aggregates everything that happens during the trial window — activities the player attended, evaluations written, goals created or updated, plus a small synthesis (rolling rating, evaluation count). Nothing is duplicated; the data sits in the normal places, the Execution tab just filters to the trial window.

If the period needs to be extended, the **Extend trial** button on Overview asks for a new end date and a mandatory justification note. Each extension is logged with who, when, and why.

### 3. Collect staff input

Each assigned coach has their own input form on the **Staff inputs** tab. They enter an overall rating and notes, save as draft, and submit when ready. A coach sees only their own draft until the head of development clicks **Release submitted inputs to assigned staff** — that prevents groupthink during the period and lets everyone see the picture once everyone has submitted.

The system also sends gentle reminders to staff who haven't submitted as the trial ends approaches (7 days out, 3 days out, on the end date).

### 4. Decide

On the **Decision** tab, the head of development picks one of three outcomes:

- **Admit** — offer a place. Player status → Active.
- **Decline (final)** — no place this season. Player status → Archived.
- **Decline (with encouragement)** — no place this season, but a warm invitation to try again. The decision form asks for a few sentences about strengths and growth areas; those go straight into the encouragement letter.

The decision form requires a justification note (≥ 30 characters) for the internal record.

### 5. Generate the letter

Recording a decision generates the letter automatically. The **Letter** tab shows it inline and offers a print-ready view. Three templates ship with the plugin:

- **Admittance** — warm welcome, next steps, optional acceptance slip on page 2 if the club has that turned on.
- **Decline (final)** — respectful and definitive.
- **Decline (with encouragement)** — names what stood out and where to keep working, with an explicit invitation to re-apply.

The shipped Dutch letters use a warm, informal "je/jullie" club voice. If the wording isn't quite right for your club, **Letter templates** (under the Trials tile group) lets you customise each letter per language. The editor opens with a short guidance note, lists each letter under a plain-language name ("Offer of a place", "No place — with encouragement", …), and shows a side panel of every variable you can substitute (`{player_first_name}`, `{trial_end_date}`, `{strengths_summary}`, …) plus a live preview with sample data. Unknown variables are left as literal `{foo}` so missing pieces are visible in the preview.

### 6. Have the conversation with the parents

The **Parent meeting** tab opens a fullscreen, sanitized view designed to be shown on a laptop or tablet during the meeting. It deliberately omits internal data — no individual staff ratings, no attendance percentages, no justification notes. What's shown: photo, player name and age, decision outcome, and the letter ready to print or email.

## Tracks

Tracks are templates that decide the default trial duration. Three ship with the plugin (Standard / Scout / Goalkeeper) and clubs can add their own through **Trial tracks**. Existing cases keep working when a track is archived; new cases just don't see the archived option.

## Acceptance slip (optional)

For admit decisions, the club can include an acceptance slip on page 2 of the letter. **Letter templates → Acceptance slip** turns it on, sets the response deadline (in days from the letter date), and the return address. After the slip comes back signed, mark it received from the Decision tab.

## Closing a trial case

A case stays "open" — visible to the assigned staff, counting against the head of development's active workload — until it is either **decided** or **archived**. Two paths, two different intents:

### Decide (the normal path)

Use the **Decision** tab to record an outcome (`Admit` / `Decline (final)` / `Decline (with encouragement)`) plus the mandatory ≥ 30-character justification note. Recording the decision:

- Moves the player's status, per the table below.
- Writes the matching entry on the player's journey — *Trial ended*, plus *Signed* on an admit or *Released* on a final decline.
- Stamps `decision_made_at` + `decision_made_by` for the audit trail.

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
