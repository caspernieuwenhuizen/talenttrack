# TalentTrack v3.106.0 — Communication module foundation (#0066 Foundation)

First ship of the #0066 Communication module: the central authority for outbound messages (push / email / SMS / WhatsApp deep-link / in-app). Foundation only — channel registry + Email adapter + template registry + opt-out + quiet-hours + rate-limit + audit. The 15 use cases (training cancelled, selection letter, PDP ready, etc.) register their own templates from their owning modules in subsequent ships.

## All 8 open spec Qs locked

Per user direction, the in-spec leans become locked decisions:

- **Q1 Email transport** — pluggable with `wp_mail` default. The `EmailChannelAdapter` exposes `tt_comms_email_send` filter; `null` return → wp_mail fallback.
- **Q2 SMS transport** — abstract from day one. SmsAdapter ships when the first SMS use case ships; the contract is provider-agnostic.
- **Q3 WhatsApp** — deep-link only in v1. Business API is real money + onboarding cost; v1 ships `https://wa.me/?phone=…&text=…` URLs.
- **Q4 Push** — extend the existing Push module in place. `PushChannelAdapter` lands as a thin wrapper registering the existing send path with `ChannelAdapterRegistry`.
- **Q5 Opt-out granularity** — per-message-type. `OptOutPolicy::isOptedOut($user, $messageType)` keyed by `wp_user_meta['tt_comms_optout_<message_type>']`.
- **Q6 Audit retention** — 18 months default, configurable per club. Retention cron lands as a follow-up.
- **Q7 Template authoring** — top 5 editable per club, rest fixed. `TemplateInterface::isEditable()` declares the boundary; editable templates consult `tt_config['comms_template_<key>_<locale>_subject|body']`.
- **Q8 Inbound** — drop with polite auto-reply. v1 ships no inbound; auto-reply ships when the first transport that supports it (email, primarily) wires up bounce handling.

## Architecture

### Migration 0075 — `tt_comms_log`

One row per Comms send attempt. Captures who sent what to whom on which channel with what status. The body itself is NEVER stored — only its SHA-256 hash. Operators answering "did the parents actually get the cancellation message?" have enough to confirm delivery + dedup; they don't have the message content for retention compliance.

### Domain value objects

`CommsRequest` — what to send (template_key, message_type, club_id, sender, recipients, payload, force_channel, urgent, attached_export_id, locale_override). Immutable.

`CommsResult` — outcome of one send (uuid, status, channel_used, recipient, error_code, note). One per recipient.

`Recipient` — one resolved addressee with kind (`self` / `parent` / `coach` / `system`), `subject_player_id` (the player this message is *about*), email, phone, preferred locale. Factory methods `Recipient::self()` / `parent()` / `coach()`.

`MessageType` — 15 spec use-case constants (`TRAINING_CANCELLED`, `SELECTION_LETTER`, etc.) plus 2 operational types with `_OPERATIONAL` suffix (`SAFEGUARDING_BROADCAST`, `ACCOUNT_RECOVERY`). Operational types bypass opt-out + quiet-hours unconditionally.

### Channel registry + Email adapter

`Channel\ChannelAdapterInterface` declares `key()`, `canReach()`, `send( CommsRequest, Recipient, $uuid, $subject, $body ): CommsResult`. `Channel\ChannelAdapterRegistry` keys on the channel string ('email' / 'push' / 'sms' / 'whatsapp_link' / 'inapp'). Same registration pattern as `WidgetRegistry`.

`Channel\Adapters\EmailChannelAdapter` — wp_mail-default with the pluggable `tt_comms_email_send` filter:

```php
add_filter( 'tt_comms_email_send', function ( $accepted, $args ) {
    // $args = [ 'uuid', 'to', 'subject', 'body', 'headers', 'recipient', 'request' ]
    // … call your transport, return true on accept, false on reject …
    return $accepted;
}, 10, 2 );
```

Returning `null` (the default when no filter handles the call) makes the adapter fall back to native `wp_mail`. Adds `X-TT-Uuid` + `X-TT-Template` headers so the operator can correlate.

### Template registry

`Template\TemplateInterface` declares `key()`, `label()`, `supportedChannels()`, `isEditable()`, and `render( $channel, $request, $recipient, $locale ): [ subject, body ]`. Each use case registers its template from its owning module's `boot()`.

Editable templates (top 5 per Q7) consult `tt_config['comms_template_<key>_<locale>_subject|body']` overrides; fixed templates render their hardcoded copy.

### Policy layer

- `OptOut\OptOutPolicy::isOptedOut($user_id, $message_type)` — checks `wp_user_meta`. Operational types always return false.
- `QuietHours\QuietHoursPolicy::shouldDefer($request)` — `wp_timezone()`-aware 21:00–07:00 check; per-club override; wraps midnight correctly. Operational types + urgent flag + emergency message types (`TRAINING_CANCELLED`) bypass.
- `RateLimit\RateLimiter::wouldExceed($sender, $message_type)` — 50/sender/hour default; per-club override. Operational types + system sends uncapped. WordPress transient backend keyed on sender + hour-bucket.

### Orchestrator

`CommsService::send( CommsRequest ): CommsResult[]` — one result per recipient. Per-recipient flow:

1. Opt-out check → `STATUS_OPTED_OUT` if blocked.
2. Quiet-hours check → `STATUS_QUIET_HOURS` if deferred.
3. Rate-limit check → `STATUS_RATE_LIMITED` if exceeded.
4. Channel resolution: `forceChannel` wins; otherwise first adapter that's in the template's `supportedChannels()` AND `canReach()` the recipient.
5. Template render via `TemplateRegistry::get($key)->render(...)`.
6. Adapter `send()` returns the `CommsResult`.
7. Audit row written via `CommsAuditLogger::record()`.

Nothing throws — every failure path returns a `CommsResult` with the appropriate status + error code.

### Audit logger

`CommsAuditLogger::record()` writes one `tt_comms_log` row per send attempt regardless of outcome. SHA-256 the body (audit-without-PII per GDPR). Failures here are non-fatal: auditing must never block delivery.

## What's NOT in this PR

- **`PushChannelAdapter`** — lands when first push use case ships (Q4 lean).
- **`SmsChannelAdapter`** — lands with first SMS use case + provider abstraction (Q2 lean).
- **`WhatsappLinkChannelAdapter`** — lands when first WhatsApp use case asks (Q3 lean).
- **`InappChannelAdapter`** — lands with the persona-dashboard inbox surface.
- **`RecipientResolver` enforcing #0042 youth-contact rules** — caller currently builds the `Recipient[]` array directly. The resolver (player_id + message_type → resolved recipient set) lands with the first use case that needs it.
- **The 15 use cases themselves** — each ships from its owning module with a `TemplateInterface` registration + a calling site that builds the `CommsRequest`.
- **Operator-facing opt-out preferences UI on the Account page** — storage layer is ready; UI is a small follow-up.
- **18-month retention cron** — follow-up wp-cron job that tombstones `address_blob` / `subject` while keeping rows for safeguarding evidence.
- **Two-way inbound** — separate epic.

## Migrations

- `0075_comms_log` — new audit table. Idempotent (`CREATE TABLE IF NOT EXISTS`).

## Affected files

- `database/migrations/0075_comms_log.php` — new.
- `src/Modules/Comms/CommsModule.php` — new (module shell, registers Email adapter).
- `src/Modules/Comms/CommsService.php` — new (~150 lines, orchestrator).
- `src/Modules/Comms/CommsAuditLogger.php` — new.
- `src/Modules/Comms/Domain/CommsRequest.php` — new.
- `src/Modules/Comms/Domain/CommsResult.php` — new.
- `src/Modules/Comms/Domain/Recipient.php` — new.
- `src/Modules/Comms/Domain/MessageType.php` — new (15 use-case + 2 operational constants).
- `src/Modules/Comms/Channel/ChannelAdapterInterface.php` — new.
- `src/Modules/Comms/Channel/ChannelAdapterRegistry.php` — new.
- `src/Modules/Comms/Channel/Adapters/EmailChannelAdapter.php` — new (~80 lines, pluggable wp_mail).
- `src/Modules/Comms/Template/TemplateInterface.php` — new.
- `src/Modules/Comms/Template/TemplateRegistry.php` — new.
- `src/Modules/Comms/OptOut/OptOutPolicy.php` — new.
- `src/Modules/Comms/QuietHours/QuietHoursPolicy.php` — new (~70 lines, midnight-wrap aware).
- `src/Modules/Comms/RateLimit/RateLimiter.php` — new (~45 lines, transient-backed).
- `config/modules.php` — registers `CommsModule`.
- `talenttrack.php`, `readme.txt`, `SEQUENCE.md` — version bump + ship metadata.
