<!-- type: feat -->

# #0042 — Youth-aware contact strategy: phone field + PWA push + KB articles

## Problem

Many youth players — especially U8-U12 — have neither a personal email address nor a phone account. Today every TalentTrack feature that assumes "player has email" silently breaks for that cohort. Parents have email/phone (handled via `tt_parent` since #0032), but the player themselves often only has a phone (U11-U12) or neither (U8-U10). A grassroots club running U8-U12 teams — which is the bulk of the target market — cannot meaningfully invite or notify players directly under the current model.

| Age tier | Email | Phone | Default contact strategy |
| - | - | - | - |
| U8-U10 | Rare | Rare | Parent (`tt_parent`) is the only contact; player has no direct surface |
| U11-U12 | Rare | Common | Player phone (PWA push) + parent email as fallback |
| U12+ | Common | Common | Player email + PWA push on top |

Player birthdate already lives on `tt_players`; age tier is derived, not stored.

## Proposal

A single epic that delivers three slices that only make sense together:

- **(A) Youth-aware contact tiering** — an `AgeTier` resolver, a `tt_phone` user-meta field (encrypted at rest), and the workflow engine learning to ask "which dispatcher should I use for this user?".
- **(B) PushDispatcher** — a sibling of `EmailDispatcher` inside the workflow engine (#0022), backed by a service worker, a `tt_push_subscriptions` table, and the standard Web Push protocol.
- **(C) KB articles for mobile install** — markdown articles under `docs/`, surfaced via #0029's role-filtered TOC with two new audience markers (`audience:player`, `audience:parent`), covering iOS Safari + Android Chrome install flows in NL + EN.

(A) is useless without (B); (B) is useless without (C) since users will not discover the install flow on their own. Heavyweight KB platform (search, custom post type, dedicated nav) is parked separately as #0043.

## Scope

### Sprint 1 — Audience marker prerequisite (~1-2h)

Add `audience:player` and `audience:parent` to #0029's role-filtered TOC. Two-line change in the audience whitelist + filter logic; without this the new KB articles have nowhere to render.

### Sprint 2 — Phone field + AgeTier resolver (~4-6h)

**Schema**: no migration required. Phone lives in `wp_usermeta` under key `tt_phone` (E.164 format, never empty string — absence = NULL). Same approach `tt_phone_verified_at` (timestamp, NULL = never verified).

**Why user-meta, not a `tt_players` column**: phone is desirable for staff and coaches as well — locking it on `tt_players` would fork the schema. WP user meta is the canonical "extra fact about a user" surface; encryption + privacy posture is identical to how Backup (#0013) treats SMTP creds.

**Encryption**: phone numbers are PII. Stored encrypted using the same helper #0031 will use for the Spond URL. Hash (SHA-256) maintained in `tt_phone_hash` user-meta for indexed lookup ("find user by phone") if we ever need it; the plain value is decrypted on read only.

**`AgeTier` resolver** — `src/Infrastructure/Identity/AgeTier.php` — takes a player record, returns `'u8_u10' | 'u11_u12' | 'u12_plus' | 'unknown'`. Lives next to `PersonaResolver` from #0033. Used by the dispatcher selection logic + UI hints ("This player has no email — invitation will go to the linked parent").

**Player + Profile edit form** — adds a phone input (`type=tel`, `inputmode=numeric`, `autocomplete=tel`) to the player edit form and to the user's own profile page. Format hint: "Use international format, e.g. +31612345678". Validation is light: matches `/^\+?[1-9]\d{6,14}$/`.

**Invitation flow integration (#0032)** — the invite form gains an "Invite by phone instead of email" branch. For U8-U10 (resolved age tier), the form short-circuits to "no contact for the player; routing through linked parent" with no phone/email field shown for the player at all.

### Sprint 3 — PushDispatcher + service worker (~12-18h)

**Schema**: migration `0038_push_subscriptions.php`:

```sql
CREATE TABLE {prefix}tt_push_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id INT UNSIGNED NOT NULL DEFAULT 1,
    user_id BIGINT UNSIGNED NOT NULL,
    endpoint VARCHAR(500) NOT NULL,
    p256dh VARCHAR(255) NOT NULL,
    auth VARCHAR(255) NOT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_endpoint (endpoint(191)),
    KEY idx_user (user_id)
);
```

One row per (user, device). `endpoint` is the unique push-service URL the browser provides; rotation = new row.

**`src/Modules/Push/PushDispatcher.php`** — implements the existing dispatcher contract from #0022 next to `EmailDispatcher`. Accepts a workflow event + a target user, looks up active subscriptions for that user, sends a Web Push payload to each via the standard VAPID-signed HTTP request.

**VAPID keys** — generated once at first install, stored encrypted in `wp_options` (`tt_vapid_public`, `tt_vapid_private`). Public key is embedded in the JS bundle for `pushManager.subscribe()`. Private key signs each outbound push.

**Web Push library** — `minishlink/web-push` via Composer (or a tiny in-house implementation if we prefer to stay zero-dependency; the protocol is small enough). Signed JWT + AES-128-GCM payload encryption per RFC 8291.

**Service worker** — `assets/js/tt-sw.js` registered on first dashboard visit. Handles three events:

1. `push` — receive + decrypt + display notification.
2. `notificationclick` — open the relevant TalentTrack page (URL embedded in the payload).
3. `pushsubscriptionchange` — re-subscribe + POST the new endpoint to `/wp-json/talenttrack/v1/push-subscriptions`.

**REST endpoints**:

- `POST /push-subscriptions` — register a subscription. Body: `{ endpoint, keys: { p256dh, auth }, user_agent? }`. Cap-gated on `read` (any logged-in user). Returns 200 / 409 (already exists).
- `DELETE /push-subscriptions/{id}` — own-only or admin.
- `GET /push-subscriptions` — list own subscriptions (so the user can revoke from a settings page).

**Subscription lifecycle**:

- A subscription is "active" when `last_seen_at` is within the last 90 days.
- A daily cron prunes subscriptions older than 90 days (alongside the existing #0022 diagnostic cron).
- A push-send that returns HTTP 410 (gone) deletes the subscription immediately.

**Per-template fallback chain** — workflow templates declare their dispatcher posture in their existing `dispatchers()` method:

- `[ Push::class, ParentEmail::class ]` — push first, parent email if no active subscription.
- `[ Push::class, Email::class ]` — push first, own email if any.
- `[ Email::class ]` — email only (existing behaviour, unchanged).

The chain is iterated in order; the first dispatcher to confirm delivery wins. Failure mode "no subscription, no parent, no email" writes an audit-log row tagged `dispatch_dropped` and goes to the coach as a notice.

**Trust + verify-on-push validation**:

- v1 ships with no OTP. The user types their phone, it's stored.
- When that user installs the PWA on a device + accepts push permission, the new subscription's `user_id` is treated as confirmation that the typed phone is "claimable" by this person; `tt_phone_verified_at` is set to the subscription's `created_at`.
- If the verification path proves insufficient in field use, **v1.1 layers WhatsApp Cloud API OTP** (NOT Telegram — wrong demographic for NL). Cost ~€0.04-0.05 per auth-conversation in NL; ~€10/year per club at expected volumes; per-club setup overhead is 2-4h Meta Business verification + template approval + sender registration.

### Sprint 4 — KB articles (~4-6h authoring + scaffolding)

**Markdown structure** — under `docs/`:

```
docs/
  install-on-iphone.md          (audience:player + audience:parent)
  install-on-android.md         (audience:player + audience:parent)
  notifications-setup.md        (audience:player + audience:parent)
  parent-handles-everything.md  (audience:parent)        # for U8-U10 cohort
  
docs/nl_NL/
  install-on-iphone.md
  install-on-android.md
  notifications-setup.md
  parent-handles-everything.md
```

Each article is short (50-150 words) and image-heavy — screenshots of the iOS share sheet, Android install banner, browser permission dialog. Images live in `docs/img/` with the same audience marker.

**HelpTopics registration** — entries in `src/Modules/Documentation/HelpTopics.php` for each new article, grouped under a new "Mobile install" group.

**Onboarding nudge** — post-login banner on first dashboard visit when:

1. The user is a player or parent (resolved via `PersonaResolver`).
2. They have no active push subscription on this user-agent.
3. They haven't dismissed the banner on this device (cookie + per-user-meta tracking — dismissable but reappears on every new device).

The banner links to the article matching the user-agent (iOS Safari → install-on-iphone.md; Android Chrome → install-on-android.md; everything else → a generic "open this on your phone" article).

### Sprint 5 — Per-template push posture in workflow config (~3-4h)

The existing workflow config admin (#0022 Phase 2+3 shipped this) gains a "Notification channel" select per template:

- "Email only" (current default; no change).
- "Push if available, fall back to parent email" (new).
- "Push if available, fall back to own email" (new).
- "Push only" (new; for templates where email noise is unwanted).

Stored as a column on `tt_workflow_templates` (`dispatcher_chain VARCHAR(64) DEFAULT NULL`). The chain is parsed in code, not free-text — a small enum.

## Out of scope (v1)

- **Native iOS / Android apps** — PWA push is sufficient for this cohort; the maintenance cost of two app-store listings is wildly disproportionate.
- **Paid SMS** as a verification alternative — defer to v1.1 if "trust + verify-via-push" proves insufficient. WhatsApp Cloud API is the v1.1 path, not Telegram.
- **Voice-call contact** — irrelevant for this audience.
- **In-app messaging between players, coaches, parents** — adjacent but separate; #0028 conversational goals is the closest neighbour.
- **Heavyweight Knowledge Base platform** (search + custom post type + dedicated nav) — parked as #0043.
- **Samsung Internet + iPadOS** install articles — v1.1 if field feedback asks.
- **Real OTP at signup time** — trust-on-push is enough for v1.

## Acceptance criteria

### U8-U10 path

- [ ] A U10 player whose parent has a `tt_parent` account can be invited via the parent's email.
- [ ] The invitation flow displays "no email needed for the player" and routes everything to the parent.
- [ ] The player record can be created without a phone or email; the player edit form shows an `AgeTier`-aware notice.

### U11-U12 path

- [ ] A U11 player with a phone but no email can install TalentTrack as a PWA on their phone.
- [ ] Accepting the push prompt creates a `tt_push_subscriptions` row.
- [ ] On creation of that row, the player's `tt_phone_verified_at` is set.
- [ ] A workflow event configured "push if available, fall back to parent email" delivers a push to the player's phone, not the parent's email.
- [ ] If the player declines the prompt, the same workflow falls back to the parent's email.

### U12+ path

- [ ] Existing email-based flows are unchanged.
- [ ] If the user installs the PWA and accepts push, the workflow can additionally send push without breaking email.

### Phone field

- [ ] Stored encrypted; never visible in REST responses or audit-log payload.
- [ ] Validates as E.164.
- [ ] Editable on the player edit form + the user's own profile.
- [ ] Hash maintained for future indexed lookup; not exposed.

### Push infrastructure

- [ ] VAPID keys generated once at install; private key never exposed in JS.
- [ ] Service worker registers on first dashboard load.
- [ ] `pushsubscriptionchange` re-subscribes silently.
- [ ] HTTP 410 from the push service deletes the subscription.
- [ ] 90-day inactivity prune runs daily.

### KB articles

- [ ] iOS Safari + Android Chrome articles exist in EN and NL.
- [ ] Articles render via #0029's role-filtered TOC for `audience:player` and `audience:parent`.
- [ ] Onboarding banner shows on first dashboard load and links to the user-agent-matched article.
- [ ] Banner is dismissable per device.

### Workflow config

- [ ] Admin can pick a dispatcher chain per workflow template via the existing config UI.
- [ ] Chain is enforced server-side; client-side selector is a convenience.
- [ ] Default for existing templates remains "email only" (no behaviour change for installed clubs).

### No regression

- [ ] Workflows that don't opt into push behave exactly as before.
- [ ] Coaches without a player record + admins are unaffected.
- [ ] Existing `tt_parent` invitation flow (#0032) still routes parent-only.

## Notes

### Sizing

| Slice | Estimate |
| - | - |
| Sprint 1 — audience markers in #0029 TOC | ~1-2h |
| Sprint 2 — phone field + AgeTier resolver + invitation integration | ~4-6h |
| Sprint 3 — PushDispatcher + service worker + REST + lifecycle | ~12-18h |
| Sprint 4 — KB articles (NL+EN, iOS + Android) + onboarding banner | ~4-6h |
| Sprint 5 — per-template push posture in workflow config UI | ~3-4h |
| **Total v1** | **~24-36h** as a single epic, single PR per the compression pattern |

### Hard decisions locked during shaping

1. **Bundle as one epic** — all five sprints in one PR. (A) is useless without (B); (B) is useless without (C).
2. **Phone in `tt_usermeta`, not `tt_players` column** — single schema covers staff + coaches + players consistently.
3. **Trust + verify-on-push for v1** — zero ongoing cost, zero per-club setup. WhatsApp Cloud API as the v1.1 fallback (not Telegram). Auth-conversation pricing in NL ~€0.04-0.05 / msg; ~€10/year per club at expected volumes; per-club Meta Business verification + template approval is 2-4h admin overhead.
4. **iOS Safari + Android Chrome only for v1** — Samsung Internet + iPadOS articles in v1.1 if field feedback asks.
5. **Markdown KB in `docs/`, not a heavyweight platform** — promote to #0043 when article count exceeds ~30 or a customer asks for search.
6. **Per-template fallback chain configurable via admin UI** — default for existing templates stays "email only" so installed clubs see no behaviour change.
7. **Push subscription lifecycle = 90-day inactivity prune** — daily cron, plus immediate delete on HTTP 410.

### Cross-references

- **#0011** — players are WP users; phone meta lands on `tt_users` consistently with that decision.
- **#0013** — encryption-at-rest pattern for phone PII (and Spond URLs in #0031).
- **#0022** — workflow engine dispatcher pattern; PushDispatcher is a sibling of EmailDispatcher.
- **#0024** — Setup Wizard onboarding nudge for staff; players see the install banner on first dashboard load (re-using the same component).
- **#0029** — docs split + role-filtered TOC; KB articles reuse this surface, two new audience markers added.
- **#0031** — Spond URL credential storage shares the same encryption helper.
- **#0032** — `tt_parent` role + parent-as-contact wiring; this idea inherits all of it for the U8-U10 case.
- **#0033** — `PersonaResolver`; `AgeTier` resolver lives next to it.
- **#0043** — heavyweight KB platform (parked); promotion path when article count outgrows the markdown-in-`docs/` approach.

### Things to verify in the first 30 minutes of build

- VAPID-keyed Web Push works end-to-end against both iOS Safari (16.4+) and Android Chrome on a real device — emulators lie.
- Service worker registration doesn't conflict with any cache-busting plugin a club might run.
- The "trust + verify-on-push" model produces a sensible audit story when a phone number is later changed; old subscriptions don't silently re-validate the new number.
- Permission denial on iOS Safari is not auto-dismissable — confirm the user-experience when a player declines and we fall back to parent email.
