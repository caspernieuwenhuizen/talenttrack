<?php
namespace TT\Modules\Comms\Template;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Templates\AlertDigestTemplate;
use TT\Modules\Comms\Templates\AttendanceFlagTemplate;
use TT\Modules\Comms\Templates\DesktopLinkTemplate;
use TT\Modules\Comms\Templates\DirectMessageTemplate;
use TT\Modules\Comms\Templates\GoalNudgeTemplate;
use TT\Modules\Comms\Templates\GuestPlayerInviteTemplate;
use TT\Modules\Comms\Templates\InvitationEmailTemplate;
use TT\Modules\Comms\Templates\LetterDeliveryTemplate;
use TT\Modules\Comms\Templates\MassAnnouncementTemplate;
use TT\Modules\Comms\Templates\MethodologyDeliveredTemplate;
use TT\Modules\Comms\Templates\NotificationTemplate;
use TT\Modules\Comms\Templates\OnboardingNudgeInactiveTemplate;
use TT\Modules\Comms\Templates\ParentMeetingInviteTemplate;
use TT\Modules\Comms\Templates\PdpReadyTemplate;
use TT\Modules\Comms\Templates\SafeguardingBroadcastTemplate;
use TT\Modules\Comms\Templates\ScheduleChangeFromSpondTemplate;
use TT\Modules\Comms\Templates\ScheduledReportTemplate;
use TT\Modules\Comms\Templates\ScoutReportDeliveryTemplate;
use TT\Modules\Comms\Templates\SelectionLetterTemplate;
use TT\Modules\Comms\Templates\StaffDevelopmentReminderTemplate;
use TT\Modules\Comms\Templates\TrainingCancelledTemplate;
use TT\Modules\Comms\Templates\TrialInputReminderTemplate;
use TT\Modules\Comms\Templates\TrialPlayerWelcomeTemplate;

/**
 * TemplateCatalog (#3111) — the templates this release ships, readable
 * without the plugin having booted.
 *
 * `TemplateRegistry` is the runtime lookup and stays the authority for
 * anything that happens during a request. It is populated from
 * `CommsModule::boot()`, which runs on `init` — and plugin activation
 * does not. `activate_plugin()` loads the plugin file long after `init`
 * has passed, so at activation the registry is empty.
 *
 * That matters because #3111 seeds a fresh install's disabled set at
 * activation. Reading the registry there would seed an empty set and
 * silently leave a new install with every message switched on, which is
 * the exact bug the seeding exists to prevent — and it would fail
 * quietly, because an empty registry looks like "no templates" rather
 * than like an error.
 *
 * So the shipped set lives here, as data, and `CommsModule::boot()`
 * registers from it. One list, two readers.
 */
final class TemplateCatalog {

    /**
     * Every template this release ships, in registration order.
     *
     * @return TemplateInterface[]
     */
    public static function shipped(): array {
        return [
            // #0066 use cases 1–15.
            new TrainingCancelledTemplate(),
            new SelectionLetterTemplate(),
            new PdpReadyTemplate(),
            new ParentMeetingInviteTemplate(),
            new TrialPlayerWelcomeTemplate(),
            new GuestPlayerInviteTemplate(),
            new GoalNudgeTemplate(),
            new AttendanceFlagTemplate(),
            new ScheduleChangeFromSpondTemplate(),
            new MethodologyDeliveredTemplate(),
            new OnboardingNudgeInactiveTemplate(),
            new StaffDevelopmentReminderTemplate(),
            new LetterDeliveryTemplate(),
            new MassAnnouncementTemplate(),
            new SafeguardingBroadcastTemplate(),

            new InvitationEmailTemplate(),   // #1902 — account mail, not switchable (#3110)
            new NotificationTemplate(),      // #2604 caller-composed copy
            new AlertDigestTemplate(),       // #2634 alerts roll-up

            // #2604 — the last of the direct `wp_mail()` senders, each now
            // routed through the same opt-out / quiet-hours / rate-limit /
            // audit path as everything else.
            new TrialInputReminderTemplate(),
            new ScheduledReportTemplate(),
            new DirectMessageTemplate(),
            new ScoutReportDeliveryTemplate(),
            new DesktopLinkTemplate(),
        ];
    }

    /**
     * Keys of the shipped templates an academy can switch — everything
     * except account mail (#3110). This is what a fresh install's
     * disabled set is seeded with.
     *
     * @return string[]
     */
    public static function shippedSwitchableKeys(): array {
        $keys = [];
        foreach ( self::shipped() as $template ) {
            if ( $template instanceof AccountMailTemplate ) continue;
            $keys[] = $template->key();
        }
        return $keys;
    }
}
