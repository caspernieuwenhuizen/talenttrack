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
use TT\Modules\Comms\Cron\CommsScheduledCron;
use TT\Modules\Comms\Dispatch\CommsDispatcher;
use TT\Modules\Comms\Retention\CommsRetentionCron;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Templates\AttendanceFlagTemplate;
use TT\Modules\Comms\Templates\GoalNudgeTemplate;
use TT\Modules\Comms\Templates\GuestPlayerInviteTemplate;
use TT\Modules\Comms\Templates\LetterDeliveryTemplate;
use TT\Modules\Comms\Templates\MassAnnouncementTemplate;
use TT\Modules\Comms\Templates\MethodologyDeliveredTemplate;
use TT\Modules\Comms\Templates\OnboardingNudgeInactiveTemplate;
use TT\Modules\Comms\Templates\ParentMeetingInviteTemplate;
use TT\Modules\Comms\Templates\PdpReadyTemplate;
use TT\Modules\Comms\Templates\SafeguardingBroadcastTemplate;
use TT\Modules\Comms\Templates\ScheduleChangeFromSpondTemplate;
use TT\Modules\Comms\Templates\SelectionLetterTemplate;
use TT\Modules\Comms\Templates\StaffDevelopmentReminderTemplate;
use TT\Modules\Comms\Templates\TrainingCancelledTemplate;
use TT\Modules\Comms\Templates\TrialPlayerWelcomeTemplate;

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

        // v3.110.18 — register all 15 use-case templates. Closes #0066.
        // Template copy is hardcoded EN + NL; the top-5 marked
        // editable (training_cancelled / selection_letter / pdp_ready /
        // letter_delivery / mass_announcement) honour per-club
        // `tt_config['comms_template_<key>_<locale>_<channel>_<subject|body>']`
        // overrides ahead of the hardcoded copy. Trigger code lives
        // in the owning module's first send (per the #0066 spec); the
        // generic `tt_comms_dispatch` action hook + `CommsDispatcher`
        // give owning modules a one-call path to fire any template
        // without having to wire CommsService directly.
        TemplateRegistry::register( new TrainingCancelledTemplate() );          // use case 1
        TemplateRegistry::register( new SelectionLetterTemplate() );            // use case 2
        TemplateRegistry::register( new PdpReadyTemplate() );                   // use case 3
        TemplateRegistry::register( new ParentMeetingInviteTemplate() );        // use case 4
        TemplateRegistry::register( new TrialPlayerWelcomeTemplate() );         // use case 5
        TemplateRegistry::register( new GuestPlayerInviteTemplate() );          // use case 6
        TemplateRegistry::register( new GoalNudgeTemplate() );                  // use case 7
        TemplateRegistry::register( new AttendanceFlagTemplate() );             // use case 8
        TemplateRegistry::register( new ScheduleChangeFromSpondTemplate() );    // use case 9 (gated on #0062)
        TemplateRegistry::register( new MethodologyDeliveredTemplate() );       // use case 10
        TemplateRegistry::register( new OnboardingNudgeInactiveTemplate() );    // use case 11
        TemplateRegistry::register( new StaffDevelopmentReminderTemplate() );   // use case 12
        TemplateRegistry::register( new LetterDeliveryTemplate() );             // use case 13
        TemplateRegistry::register( new MassAnnouncementTemplate() );           // use case 14
        TemplateRegistry::register( new SafeguardingBroadcastTemplate() );      // use case 15

        // Generic event-driven dispatch hook. Owning modules fire
        //   do_action( 'tt_comms_dispatch', $template_key, $payload, $recipients, $options );
        // and `CommsDispatcher::dispatch()` builds the CommsRequest +
        // calls CommsService. Saves every owning module from importing
        // the full Comms domain when all they want is "send X to Y."
        CommsDispatcher::init();

        // Schedule-driven triggers — wp-cron once a day. Each triggers
        // its own template's send loop scoped per club:
        //   - goal_nudge: goals 4+ weeks old without recent nudge
        //   - attendance_flag: players with 3+ consecutive absences
        //   - onboarding_nudge_inactive: parents with 30+ days inactive
        //   - staff_development_reminder: reviews due in <= 7 days
        // The other 11 templates are event-driven and fire from their
        // owning module via the `tt_comms_dispatch` action.
        CommsScheduledCron::init();
    }
}
