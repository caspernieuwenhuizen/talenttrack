<?php
namespace TT\Modules\Comms;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\Comms\Channel\Adapters\EmailChannelAdapter;
use TT\Modules\Comms\Channel\Adapters\InappChannelAdapter;
use TT\Modules\Comms\Channel\Adapters\PushChannelAdapter;
use TT\Modules\Comms\Channel\Adapters\SmsChannelAdapter;
use TT\Modules\Comms\Channel\Adapters\WhatsappLinkChannelAdapter;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\Retention\CommsRetentionCron;

/**
 * CommsModule (#0066) — central authority for outbound messages.
 *
 * Foundation ships:
 *   - Migration `0075_comms_log` — `tt_comms_log` audit table.
 *   - `Domain\CommsRequest` / `Domain\CommsResult` / `Domain\Recipient`
 *     / `Domain\MessageType` value objects.
 *   - `Channel\ChannelAdapterInterface` + `Channel\ChannelAdapterRegistry`.
 *   - `Channel\Adapters\EmailChannelAdapter` — `wp_mail`-default with
 *     pluggable `tt_comms_email_send` filter (per spec Q1).
 *   - `Template\TemplateInterface` + `Template\TemplateRegistry`.
 *   - `OptOut\OptOutPolicy` — per-recipient × per-message-type
 *     (per spec Q5).
 *   - `QuietHours\QuietHoursPolicy` — 21:00–07:00 default; emergency
 *     bypass for safeguarding + cancellations.
 *   - `RateLimit\RateLimiter` — 50/sender/hour default; operational
 *     bypass.
 *   - `CommsService` orchestrator: opt-out → quiet-hours → rate-limit
 *     → channel-resolve → template-render → adapter dispatch → audit.
 *   - `CommsAuditLogger` — writes one `tt_comms_log` row per send.
 *
 * Open shaping decisions taken from the spec leans (locked at v3.106.0
 * by user direction): pluggable email with `wp_mail` default (Q1);
 * abstract SMS provider (Q2, lands when SmsAdapter ships); WhatsApp
 * deep-link only in v1 (Q3); extend Push module in place (Q4, lands
 * with PushChannelAdapter); per-message-type opt-out (Q5); 18-month
 * audit retention configurable (Q6, retention cron lands in a
 * follow-up); editable templates for top 5 — fixed for the rest (Q7);
 * polite auto-reply on inbound (Q8, inbound handling deferred — Comms
 * is one-way in v1).
 *
 * Use cases land in subsequent ships, each registering a Template +
 * the calling code that builds a `CommsRequest`.
 */
class CommsModule implements ModuleInterface {

    public function getName(): string { return 'comms'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        // Channel adapters. The original "register from owning module"
        // plan was reversed at v3.110.0 — keeping all five channels in
        // one place is clearer for the dispatcher's channel-resolver to
        // reason about, and the Push / SMS / Inapp adapters thin-wrap
        // their dependencies (Push module, transport filter, inbox
        // table) without coupling Comms to those modules' lifecycles.
        ChannelAdapterRegistry::register( new EmailChannelAdapter() );        // pluggable, wp_mail default (Q1)
        ChannelAdapterRegistry::register( new WhatsappLinkChannelAdapter() ); // deep-link only (Q3) — v3.109.0
        ChannelAdapterRegistry::register( new PushChannelAdapter() );         // wraps Push module (Q4) — v3.110.0
        ChannelAdapterRegistry::register( new SmsChannelAdapter() );          // provider-pluggable filter (Q2) — v3.110.0
        ChannelAdapterRegistry::register( new InappChannelAdapter() );        // tt_comms_inbox-backed — v3.110.0

        // v3.109.0 — daily retention cron. Tombstones rows older than
        // the per-club `comms_audit_retention_months` setting (default
        // 18 per spec Q6 lean) by clearing `address_blob` + `subject`
        // while keeping the row for safeguarding evidence.
        CommsRetentionCron::init();
    }
}
