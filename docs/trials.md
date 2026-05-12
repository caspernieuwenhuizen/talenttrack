<!-- audience: user -->

# Trial cases

A **trial case** is a structured way to run a 2–6 week look at a prospective player and end the period with a clear, well-communicated decision. It bundles the parts that used to live in spreadsheets and emails: who is trialing, on which track, who is seeing them, what the inputs are, what the decision is, and the letter that goes to parents.

## Who sees what

- **Head of development / Club admin** — full management. Open / extend / decide / archive cases. Edit tracks and letter templates. Release staff inputs.
- **Coaches assigned to a case** — see the case overview + execution view, submit their own input on the **Staff inputs** tab. They see other coaches' inputs only after the head of development releases.
- **Other coaches** — do not see the case at all unless they are assigned to it.

## The flow

### 1. Open a case

From the **Trial cases** tile, pick *New trial case*. Choose the player (or create a new one first), pick a track (Standard / Scout / Goalkeeper, or any custom track the club added), set start and end dates, and assign initial staff. The player's status flips to **Trial** automatically.

### 2. Watch the case run

The **Execution** tab on the case page aggregates everything that happens during the trial window — sessions the player attended, evaluations written, goals created or updated, plus a small synthesis (rolling rating, evaluation count). Nothing is duplicated; the data sits in the normal places, the Execution tab just filters to the trial window.

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

If the wording isn't quite right, **Letter templates** (under the Trials tile group) lets the club customize each template per locale. The HTML editor has a side panel listing every variable that can be substituted (`{player_first_name}`, `{trial_end_date}`, `{strengths_summary}`, …). Unknown variables are left as literal `{foo}` so missing pieces are visible in the preview.

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

- Flips the player's status (Admit → Active, Decline → Archived).
- Generates the matching letter automatically.
- Stamps `decision_made_at` + `decision_made_by` for the audit trail.

Use this whenever you have a substantive answer for the family. The Parent meeting tab carries the rest of the conversation.

### Archive (the "no answer needed" path)

When you don't owe the family a formal decision — the family ghosted you, the player moved cities, the case was opened by mistake — the **Archive case** action closes the case without writing a decision row or generating a letter. It's available from the header action bar on the case page (manager / head-of-development cap required). The case stays in the database (you can still find it by searching archived cases); it just stops counting as open work.

If you archive a case that should have had a decision and then realise the family is willing to talk after all, an admin can un-archive from the wp-admin trial-cases list.

## Retention

Letters are persisted with a 2-year expiry. Archive is the default — denial letters are not deleted automatically because the club may need them as evidence for reconsiderations or appeals. A separate GDPR deletion flow handles permanent erasure on parent request.

## Linear case page + trial roster on team page (v3.79.0)

The trial case detail page used to split into six tabs (Overview / Execution / Staff inputs / Decision / Letter / Parent meeting). It now renders as a single linear page with a sticky anchor strip at the top — every section is visible without route changes, which makes a coach's at-a-glance review faster. Links to legacy `?tab=` URLs scroll into the matching anchor automatically.

Trial players also surface on the team detail page now, under their own **Trial players** subsection. Previously they were hidden behind the active-status filter on the team roster.
