<?php
namespace TT\Modules\Comms;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Comms\Channel\Adapters\EmailChannelAdapter;
use TT\Modules\Comms\Channel\Adapters\InappChannelAdapter;
use TT\Modules\Comms\Channel\Adapters\PushChannelAdapter;
use TT\Modules\Comms\Channel\Adapters\SmsChannelAdapter;
use TT\Modules\Comms\Channel\Adapters\WhatsappLinkChannelAdapter;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\Cron\CommsScheduledCron;
use TT\Modules\Comms\Dispatch\CommsDispatcher;
use TT\Modules\Comms\Rest\CommsRestController;
use TT\Modules\Comms\Retention\CommsRetentionCron;
use TT\Modules\Comms\Send\PdpReadySend;
use TT\Modules\Comms\Send\TrainingCancelledSend;
use TT\Modules\Comms\Template\TemplateCatalog;
use TT\Modules\Comms\Template\TemplateRegistry;

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
        // #2157 — academy-managed email sender identity. The plugin used
        // to inherit WordPress's "WordPress <wordpress@…>" default From
        // header on every send (account-creation notifications + all Comms
        // channels go through wp_mail()). These two filters let an operator
        // override the From name + address from Configuration. Stored in
        // tt_config (per-club, SaaS-tenant-scopable) rather than wp_options.
        // Empty / invalid values fall through to the WordPress default, so a
        // blank setting never produces a broken From header.
        add_filter( 'wp_mail_from', static function ( $email ) {
            $v = (string) QueryHelpers::get_config( 'comms_email_from_address', '' );
            return ( $v !== '' && is_email( $v ) ) ? $v : $email;
        } );
        add_filter( 'wp_mail_from_name', static function ( $name ) {
            $v = (string) QueryHelpers::get_config( 'comms_email_from_name', '' );
            return $v !== '' ? $v : $name;
        } );

        // Channel adapters. The original "register from owning module"
        // plan was reversed at v3.110.0 — keeping all five channels in
        // one place is clearer for the dispatcher's channel-resolver to
        // reason about, and the Push / SMS / Inapp adapters thin-wrap
        // their dependencies (Push module, transport filter, inbox
        // table) without coupling Comms to those modules' lifecycles.
        ChannelAdapterRegistry::register( new EmailChannelAdapter() );        // pluggable, wp_mail default (Q1)
        ChannelAdapterRegistry::register( new WhatsappLinkChannelAdapter() ); // deep-link only (Q3) — v3.109.0
        ChannelAdapterRegistry::register( new PushChannelAdapter() );         // wraps Push module (Q4) — v3.110.0
        // #1538 — SMS is an optional sub-feature; skip the adapter when off
        // so SMS isn't offered as a channel (provider cost/setup).
        //
        // #3106 — and skip it when the plan does not include it. Gating at
        // registration rather than at the send site is deliberate: an
        // adapter that was never registered cannot be reached by any path —
        // dispatcher, cron, filter or a future caller nobody has written —
        // and there is exactly one place to get it right. Every SMS this
        // module sends costs the operator money per message, so "the check
        // is somewhere downstream" is not good enough.
        if ( \TT\Core\FeatureRegistry::isEnabled( 'comms_sms_channel' )
             && \TT\Modules\License\LicenseGate::allows( 'comms_sms_channel' )
        ) {
            ChannelAdapterRegistry::register( new SmsChannelAdapter() );      // provider-pluggable filter (Q2) — v3.110.0
        }
        ChannelAdapterRegistry::register( new InappChannelAdapter() );        // tt_comms_inbox-backed — v3.110.0

        // v3.109.0 — daily retention cron. Tombstones rows older than
        // the per-club `comms_audit_retention_months` setting (default
        // 18 per spec Q6 lean) by clearing `address_blob` + `subject`
        // while keeping the row for safeguarding evidence.
        CommsRetentionCron::init();

        // #2605 — the module's REST surface. Comms recorded every send
        // from the start and exposed none of it, which left the audit
        // table readable only by SQL and put the module outside
        // CLAUDE.md §4. The controller composes; the queries live in
        // `Repositories\`, so a non-WordPress front end and the rendered
        // surfaces get the same answers.
        CommsRestController::init();

        // v3.110.18 — register every shipped template. Closes #0066.
        // Template copy is hardcoded EN + NL; the top-5 marked
        // editable (training_cancelled / selection_letter / pdp_ready /
        // letter_delivery / mass_announcement) honour per-club
        // `tt_config['comms_template_<key>_<locale>_<channel>_<subject|body>']`
        // overrides ahead of the hardcoded copy. Trigger code lives
        // in the owning module's first send (per the #0066 spec); the
        // generic `tt_comms_dispatch` action hook + `CommsDispatcher`
        // give owning modules a one-call path to fire any template
        // without having to wire CommsService directly.
        //
        // #3111 — the list itself moved to `TemplateCatalog`, which is
        // readable without the plugin having booted. Activation seeds a
        // fresh install's disabled set from it, and activation runs long
        // after `init`, so it cannot read the runtime registry.
        foreach ( TemplateCatalog::shipped() as $template ) {
            TemplateRegistry::register( $template );
        }

        // Generic event-driven dispatch hook. Owning modules fire
        //   do_action( 'tt_comms_dispatch', $template_key, $payload, $recipients, $options );
        // and `CommsDispatcher::dispatch()` builds the CommsRequest +
        // calls CommsService. Saves every owning module from importing
        // the full Comms domain when all they want is "send X to Y."
        CommsDispatcher::init();

        // #3081 — use case 1's trigger. The template has shipped since
        // v3.110.18 naming `tt_activity_cancelled`, a hook nothing raised;
        // ActivitiesRepository now fires it from both of its cancellation
        // write paths and this is the listener. Registered here rather
        // than from Activities so the full set of Comms triggers reads
        // from one place, as the templates and adapters above do.
        TrainingCancelledSend::init();

        // #2605 — use case 3. A PDP has no "published" state, so this
        // listens on the verdict sign-off: the point at which the plan
        // stops being a working draft. See `PdpReadySend`.
        PdpReadySend::init();

        // Schedule-driven triggers — wp-cron once a day. Each triggers
        // its own template's send loop scoped per club:
        //   - goal_nudge: goals 4+ weeks old without recent nudge
        //   - attendance_flag: players with 3+ consecutive absences
        //   - onboarding_nudge_inactive: parents with 30+ days inactive
        //   - staff_development_reminder: reviews due in <= 7 days
        // The other 11 templates are event-driven and fire from their
        // owning module via the `tt_comms_dispatch` action.
        // #1538 — scheduled sends are an optional sub-feature; when off,
        // the daily cron is never registered (operational overhead for
        // small academies). Event-driven sends are unaffected.
        if ( \TT\Core\FeatureRegistry::isEnabled( 'comms_scheduled_sends' ) ) {
            CommsScheduledCron::init();
        }
    }
}
