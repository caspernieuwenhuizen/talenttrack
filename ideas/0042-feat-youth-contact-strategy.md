<!-- type: feat -->

# Youth-aware contact strategy — phone-as-alternative-to-email + PWA push + KB articles for mobile setup

Origin: 27 April 2026 conversation. Many youth players — especially under-12 — have neither a personal email address nor a phone account. Today every TalentTrack feature that assumes "player has email" silently breaks for the U8-U12 cohort. Parents have email/phone (handled via `tt_parent` since #0032), but the player themselves often only has a phone (U11-U12) or neither (U8-U10). A grassroots club running U8-U12 teams — which is the bulk of TalentTrack's target market — cannot meaningfully invite or notify players directly under the current model.

## Age-tier expectations

| Age tier | Email | Phone | Default contact strategy |
| - | - | - | - |
| U8-U10 | Rare | Rare | Parent (`tt_parent`) is the only contact; player has no direct surface |
| U11-U12 | Rare | Common | Player phone (PWA push) + parent email as fallback |
| U12+ | Common | Common | Player email + PWA push on top |

Player birthdate already lives on `tt_players` (used by methodology + #0014 player profile). Age tier is derived, not stored — derivation belongs in a small `AgeTier` resolver alongside the `PersonaResolver` introduced by #0033.

## Why this is interesting

- **Closes the largest adoption gap left.** Clubs running U8-U12 cohorts cannot route messages to the player today; the fallback "parent receives everything" works for invitations and printed reports but breaks for player-facing surfaces like "view your evaluation", "respond to a goal", "self-rate before training".
- **PWA push is the right answer for the phone-only cohort.** Most U11-U12 players have a phone (often a hand-me-down) with WhatsApp installed but no email account. PWA push notifications are zero-cost to deliver, work cross-platform (iOS Safari + Android Chrome), and slot cleanly next to the existing `EmailDispatcher` in the workflow engine (#0022 Phase 1).
- **No external SMS bill in the steady state.** Push doesn't cost anything per-message. Phone-number *verification at signup* is the one place we'd need a paid service — and there's a free workaround (verify-on-first-push-subscription) that defers the cost decision.
- **Knowledge Base content has a free home for v1.** The `docs/` tree from #0029 docs-split already supports audience markers + role-filtered TOC. New `audience:player` and `audience:parent` markers cover the readership without any new infrastructure. (A heavyweight KB platform with search + nav lives in #0043 as a separate idea, parked until article count crosses ~30.)

## Working assumption (needs verification during shaping)

1. **Player schema** gains optional `phone_number` (E.164) and `phone_verified_at` on `tt_users` via WP user meta — same field shape we'd want for staff anyway, so no player-specific table.
2. **PushDispatcher** lands inside the workflow engine (#0022) alongside `EmailDispatcher`. Workflow templates pick the dispatcher (or the chain "push first, fall back to parent email").
3. **PWA push subscription** captured per-device when the user visits TalentTrack on their phone and accepts the browser prompt. New `tt_push_subscriptions` table: `(user_id, endpoint, p256dh, auth, last_seen_at)`. One user, many devices.
4. **Knowledge Base v1** = markdown articles in the existing `docs/` tree, surfaced via two new audience markers (`audience:player`, `audience:parent`) added to #0029's role-filtered TOC. No CMS, no new post type. Promotion to a real KB platform is #0043.
5. **Phone validation, v1**: trust the typed number, mark `phone_verified_at` on the first successful push subscription from a device that uses it. v1.1 can layer a real OTP if clubs report bad numbers in the field.

## What needs a shaping conversation

1. **Scope split — one idea or three?** This idea bundles (A) youth-aware contact tiering, (B) PWA PushDispatcher, (C) KB articles for mobile install. Each is shippable independently. **Recommendation: ship as a single epic — they only deliver value together. (A) is useless without (B); (B) is useless without (C) since users will not discover the install flow on their own. Heavyweight KB platform is parked separately as #0043.**
2. **Player phone field placement** — `tt_users` user meta, or a column on `tt_players`? Players are WP users since #0011. **Recommendation: user meta on `tt_users` — staff and coaches will want a phone field for the same reasons, and the meta path doesn't fork the schema.** *(User flagged: revisit during refinement to make sure the implication is fully understood.)*
3. **Phone validation method, v1** — paid SMS OTP (Twilio ~€0.05/msg, MessageBird similar), free WhatsApp Cloud API (1000 free/mo then ~€0.04), free Telegram bot (zero cost, but recipients need Telegram), or "trust + verify-via-push"? **Recommendation: trust + verify-via-push for v1. Layer Telegram-bot OTP in v1.1 if needed (zero ongoing cost, 30min plumbing). Only escalate to paid SMS if a club explicitly reports bad numbers.** *(User flagged: revisit during refinement — needs clearer understanding of the implications before locking.)*
4. **PushDispatcher fallback chain** — if push fails (no subscription, expired endpoint, denied permission), fall back to parent email or just drop? **Recommendation: configurable per workflow template, default = "fall back to parent email when player has a `tt_parent` linked, else fall back to coach as audit signal".**
5. **Push subscription lifecycle** — when do we prune? **Recommendation: 90-day `last_seen_at` inactivity prune (cron job alongside existing #0022 cron diagnostic).**
6. **KB infrastructure** — markdown in `docs/` or a new WP post type with search + nav? **Recommendation: markdown in `docs/` for v1. #0029's role-filtered TOC already does audience filtering; just add `audience:player` + `audience:parent` markers. Heavyweight CMS variant is tracked separately as #0043 — promote when article count grows past ~30.**
7. **Mobile platforms covered by KB v1** — iOS Safari (Add to Home Screen + push permission), Android Chrome (PWA install + push permission), Android Samsung Internet (different install flow), iPadOS variants. **Recommendation: iOS Safari + Android Chrome only for v1. Samsung Internet + iPadOS in v1.1 based on field feedback.**
8. **Onboarding nudge** — when does the player/parent first see "install TalentTrack on your phone for notifications"? **Recommendation: post-login banner on first dashboard visit, links to the KB article matching the user-agent. Dismissable, but reappears on every new device.**
9. **Privacy posture for phone numbers** — phone is PII; backup (#0013) already encrypts at rest. **Recommendation: same encrypted-at-rest posture as the Spond URL credential will use (#0031). Hash for indexed lookup if we ever need "find user by phone".**
10. **Multilingual** — KB articles get NL parallels via #0029's existing convention; push notification *content* gets `__()` + `nl_NL.po` like everything else. **Confirm.**
11. **Audience marker prerequisite** — `audience:player` / `audience:parent` need to land in #0029's TOC filter before the KB articles have a place to render. **Recommendation: do that as the first sprint of this epic, not as a separate prereq.**

## Scope estimate

| Slice | Estimate |
| - | - |
| Player phone field + verify-on-push UX | ~4-6h |
| PushDispatcher in workflow engine (next to EmailDispatcher) | ~6-10h |
| PWA service-worker push registration + permission UX | ~6-8h |
| KB articles (iOS Safari + Android Chrome, NL+EN) | ~4-6h authoring + scaffolding |
| `audience:player` / `audience:parent` markers in #0029 TOC | ~1-2h |
| Per-template push posture in workflow config UI | ~3-4h |
| **Total v1** | **~24-36h** as a single epic, single PR per the compression pattern |

Authoring time is small because mobile-install articles are short ("tap Share → Add to Home Screen → tap Allow"). Engineering time is dominated by service-worker plumbing + dispatcher integration with #0022.

## Out of scope (v1)

- Native iOS / Android apps — PWA push is sufficient for this cohort, and the maintenance cost of two app-store listings is wildly disproportionate to the benefit.
- Paid SMS as a verification alternative — defer to v1.1 if "trust + verify-via-push" proves insufficient.
- Voice-call contact — irrelevant for this audience.
- In-app messaging between players, coaches, parents — adjacent but separate (#0028 conversational goals is the closest neighbour).
- Heavyweight Knowledge Base platform (search + custom post type + dedicated nav) — parked as #0043.

## Acceptance criteria

- A U10 player whose parent has a `tt_parent` account can be invited via the parent's email; the player invitation flow displays "no email needed for the player" and routes everything to the parent.
- A U11 player with a phone but no email can install TalentTrack as a PWA on their phone, accept the push prompt, and receive a workflow-engine push (e.g. "Your coach left a comment on your evaluation") — without ever entering an email address.
- A coach composing a workflow message can pick "push if available, fall back to parent email" via the existing workflow config UI without writing custom code.
- KB articles in `docs/` cover iOS Safari + Android Chrome install + push-permission flows in NL and EN, surfaced through #0029's role-filtered TOC for `audience:player` and `audience:parent`.
- Player who declines the push permission still gets parent-email fallback when the workflow template specifies it.

## Cross-references

- **#0011** — players are WP users; phone meta lands on `tt_users` consistently with that decision.
- **#0022** — workflow engine dispatcher pattern; PushDispatcher is a sibling of EmailDispatcher.
- **#0024** — Setup Wizard is where the post-login install nudge slots in for staff; players see it on first dashboard load.
- **#0029** — docs split + role-filtered TOC; KB articles reuse this surface.
- **#0032** — `tt_parent` role + parent-as-contact wiring; this idea inherits all of it for the U8-U10 case.
- **#0033** — `PersonaResolver`; `AgeTier` resolver lives next to it.
- **#0013** — encryption-at-rest pattern for phone PII.
- **#0043** — heavyweight KB platform (parked); promotion path when article count outgrows the markdown-in-`docs/` approach.
