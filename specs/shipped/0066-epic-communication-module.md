<!-- type: epic -->

# #0066 — Communication module

> Originally drafted as #0065 in the user's intake batch (alongside the Spond JSON-API, Custom CSS, Export, Admin Center ideas). Renumbered on intake — the cascade #0061 → #0062 (Spond), #0062 → #0063 (Export), #0063 → #0064 (Custom CSS), #0064 → #0065 (Admin Center) shifted everything down by one to keep ID order consistent with the shipped polish-bundle #0061.

## Problem

A single module that owns every outbound message TalentTrack sends to a human — push, email, SMS, WhatsApp deep link, in-app notice. Today these are scattered: push lives in `src/Modules/Push`, email goes through ad-hoc `wp_mail` calls inside whichever module needed one, SMS doesn't exist, "send the report" lives inside Reports. There's no shared template registry, no shared audit trail, no shared opt-out, no shared rate limit, and the youth-contact rules from #0042 are enforced (or not) per-feature.

## Proposal

Pulling messaging concerns into one module gives us:

- One place where the youth-contact rules from #0042 are enforced — a player under 16 is addressed via parent everywhere, period.
- One audit trail to answer "did the parents actually get the cancellation message?" without trawling logs.
- One template registry so a coach who edits "training cancelled" wording changes it everywhere it's used, in every locale (#0010 / #0025).
- One rate-limit / quiet-hours engine so we don't accidentally send 8 messages to every parent at 23:00.
- A clear seam for the Export module (#0063, sibling) to call when an exported file needs to be delivered to someone — Export renders, Comms sends.

### Shape

Probably an epic with two child specs:

- **`feat-comms-foundation`** — module shell, audit table, template registry, channel adapter interface, opt-out registry, quiet-hours + rate-limit engine. No use cases shipped, but every existing send site (Push, Reports email) ports onto it.
- **`feat-comms-use-cases-v1`** — the 5 highest-priority use cases from the list below, fully wired through the foundation.

A combined "do everything at once" feat is too big and forces premature decisions. Splitting Foundation from Use Cases also means Export (#0063) can adopt Foundation as soon as it lands without waiting on use-case shaping.

## Scope — 15 use cases

Numbering is for shaping discussion, not priority. Each line is one sentence — enough to spec from, not enough to spec yet.

1. **Training cancelled** — coach taps "cancel", parents of affected players get a push + SMS fallback within 60s. Sender: coach. Recipients: parents (youth) / player (adult).
2. **Selection decision letter** — "selected for U13" / "not this round, here's why" — wording from #0017; this module owns delivery, not copy. Sender: HoD or coach. Recipients: player + parent.
3. **PDP / evaluation ready to read** — "your development plan is ready, tap to view" — links into the player profile, not the doc itself. Sender: system on coach action. Recipients: player + parent.
4. **Parent-meeting invite** — calendar invite + reminder for the periodic parent meeting (#0017 sprint 5). Sender: coach. Recipients: parent.
5. **Trial player welcome** — when a trial player is created (#0017 trial-module), they get a welcome message with what to bring and where to be. Sender: system. Recipients: trial player + parent.
6. **Guest-player invite** — short-lived invite for a guest to join a single session (#0026). Sender: coach. Recipients: guest's parent.
7. **Goal nudge** — "you set a goal 4 weeks ago; tap to update progress" (#0028 conversational goals). Sender: system on schedule. Recipients: player.
8. **Attendance flag** — "Player X has missed 3 sessions in a row" — internal coach-to-coach / coach-to-HoD escalation. Sender: system. Recipients: coach + HoD.
9. **Schedule change from Spond** — when a Spond-imported session changes time/location, alert the team. Depends on #0062 / #0031 shipping first. Sender: system. Recipients: parents (youth) / players (adult).
10. **Methodology / session-plan delivered** — "this week's session plan is published, here's the focus" (#0027). Sender: HoD. Recipients: coaches.
11. **Onboarding nudge for inactive accounts** — "we noticed you haven't logged in for 30 days; here's what's new on your child" — adoption tool, not spam. Frequency-capped. Sender: system. Recipients: parents.
12. **Staff-development reminder** — "your CPD review is due next week" (#0039). Sender: system. Recipients: coach + their HoD.
13. **Letter delivery** — formal letters that have to be printable and signable (selection / non-selection / contract). Comms attaches the rendered PDF; Export (#0063) renders it. Sender: HoD. Recipients: parent.
14. **Mass announcement** — "training cancelled this weekend due to weather" — coach picks an audience scope (team / age group / whole club) and writes one message. Sender: coach or HoD. Recipients: scoped audience.
15. **Audit / safeguarding broadcast** — high-priority "club-wide message from the safeguarding lead" with delivery confirmation. Sender: club admin. Recipients: every parent + every adult player.

## Cross-cutting concerns

- **Channel preference per recipient** — push first if the app is installed; fall back to email; SMS only for time-critical (cancellations, safeguarding broadcasts).
- **Youth contact rules (#0042)** — players under 16 are addressed via parent. Players 16-17 may be cc'd to parent depending on club policy. The Comms module enforces what #0042 specifies.
- **Quiet hours** — never send a non-emergency message between 21:00 and 07:00 local; emergencies (safeguarding, cancellation within 12h) bypass.
- **Rate limit per sender** — a coach can't accidentally email the whole club 8 times in a row.
- **Opt-out / opt-in per channel and (probably) per message-type** — required for GDPR; Comms owns the registry.
- **One audit row per send**, with payload hash, resolved recipient list, channel, status, error if any. Writes through to #0021.
- **Templating** — one registry, locale variants from #0010 / #0025; preview-before-send mandatory for any template a human composes.
- **Sender identity** — when a parent gets a message about their child, the "from" should show the coach's name, not "TalentTrack." Email reply-to behaviour needs a decision.
- **Reuses Export (#0063) for attachments** — when a use case wants to attach a PDF (selection letter, evaluation report), Comms calls Export's render API and attaches the result. Comms never renders documents itself.

## Wizard plan

**Existing wizard extended** — the mass-announcement use case (#14) ships as a multi-step wizard (audience scope → recipients preview → message → confirm + send), gated on `tt_send_announcements`. The other 14 use cases are either system-triggered or single-form sends and don't need a wizard.

## Open shaping questions

| # | Question | Why it matters |
|---|----------|----------------|
| Q1 | Email transport — `wp_mail` only, or pluggable (Mailgun / SES / Postmark)? | `wp_mail` is fine for low volume; pluggable matters at scale + deliverability. Probably pluggable with `wp_mail` default. |
| Q2 | SMS transport — pick one provider, or abstract from day one? | Abstracting from day one is cheap; locking is cheaper but riskier. Lean abstract. |
| Q3 | WhatsApp — Business API integration, or "open WhatsApp with a prefilled message" deep link? | Business API is real cost + onboarding; deep link is free + works today. Strong lean toward deep link in v1. |
| Q4 | Push — extend existing Push module, or absorb it into Comms? | Probably extend in place + register Push as a Comms channel adapter. The 30-minute spike is in "things to verify." |
| Q5 | Opt-out granularity — global per channel, or per-message-type? | Per-type is friendlier (parent can mute "training schedule" without losing safeguarding). More work to build. |
| Q6 | Audit retention — forever / 18 months / configurable per club? | GDPR pressure says shorter; safeguarding pressure says longer. Probably configurable, default 18 months. |
| Q7 | Template authoring UI — admin-only fixed set, or editable per club? | Editable means localization + brand control; fixed is simpler. Likely editable for the top 5 templates, fixed for the rest in v1. |
| Q8 | Inbound replies — silently drop, bounce-friendly auto-reply, or threaded into Threads? | v1 says drop with a polite auto-reply. Two-way is a separate epic. |

## Out of scope (provisional)

- Inbound messaging — replies from parents back into TalentTrack. Comms is one-way in v1.
- Marketing automation — drip campaigns, A/B subjects, newsletter management.
- Real-time chat — `Threads/` already exists for in-app discussion; Comms doesn't replace it.
- AI-generated message bodies — if a coach wants drafting help, that belongs in #0028.
- Document rendering — PDFs, CSVs, etc. are Export's job (#0063). Comms attaches results, never renders.

## Cross-references

- **#0042** Youth contact strategy — the rule engine for who-can-be-messaged-how. Comms is the enforcer.
- **#0010 / #0025** Multi-language — every template renders in the recipient's locale.
- **#0021** Audit log — every send writes one row.
- **#0017** Trial-player + selection-letter flow — letter delivery is a Comms use case.
- **#0027** Methodology — session-plan delivered is a Comms use case.
- **#0028** Conversational goals — goal nudges are a Comms use case.
- **#0031 / #0062** Spond integration — schedule-change alerts depend on this shipping first.
- **#0039** Staff development — CPD reminders are a Comms use case.
- **#0044** PDP cycle — PDP-ready notification is a Comms use case.
- **#0052** SaaS-readiness REST + tenancy — every Comms endpoint conforms.
- **#0063** Export module (sibling) — Comms attaches Export-rendered files; never renders itself.

## Things to verify before shaping

- 30-minute spike on the existing Push module: what's its sender interface today, and how invasive would registering it as a Comms channel adapter be?
- Inventory every existing `wp_mail` call and `Reports::email(…)` call site across modules; that list is the migration plan into Comms Foundation.
- For SMS: what's the cheapest credible provider for a NL/DE/UK club at ~50 messages/day? Determines whether the abstraction in Q2 is theoretical or earns its keep on day one.
- Confirm with a real club: do parents actually want SMS, or is push-with-email-fallback enough for everything except cancellations?

## Estimated effort once shaped

| Phase | Focus | Effort |
|-------|-------|--------|
| Foundation | Module shell, audit table, template registry, channel adapters (push + email), opt-out + quiet-hours engine | ~25-35h |
| Migration | Port existing Push + Reports email senders onto Foundation | ~8-12h |
| Use cases v1 | 5 highest-priority use cases from the list above | ~20-30h |
| SMS + WhatsApp deep-link channels | Adapter implementations + per-channel cost guards | ~12-18h |
| Use cases v2 | Remaining 10 use cases | ~25-35h |

**Total: ~90-130 hours**, sequenced across several sprints. Foundation is the gate; everything else can run in parallel once it lands. Use case #9 (Spond schedule change) is itself gated on Spond JSON-API integration shipping first.
