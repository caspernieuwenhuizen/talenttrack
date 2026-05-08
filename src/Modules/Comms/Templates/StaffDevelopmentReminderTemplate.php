<?php
namespace TT\Modules\Comms\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Template\AbstractTemplate;

/**
 * #0066 use case 12 — Staff-development reminder.
 *
 * "Your CPD review is due next week" (#0039 staff development).
 * Sender: system. Recipients: coach + their HoD.
 *
 * Trigger: daily cron `tt_comms_staff_dev_reminder_cron`. Finds
 * `tt_staff_review_due_at <= now() + 7 days AND last_reminder_at
 * IS NULL OR last_reminder_at <= now() - 7 days`.
 *
 * Tokens: {coach_name} {review_type} {due_date} {deep_link}
 */
final class StaffDevelopmentReminderTemplate extends AbstractTemplate {

    public function key(): string { return 'staff_development_reminder'; }
    public function label(): string { return __( 'Staff-development reminder', 'talenttrack' ); }
    public function supportedChannels(): array { return [ 'email', 'inapp' ]; }

    protected function defaultCopy( string $channelKey, string $locale ): array {
        return self::pickLocale( [
            'en_US' => [
                __( 'Your {review_type} is due — {coach_name}', 'talenttrack' ),
                __( "Hi {recipient_first_name},\n\nYour {review_type} is due on {due_date}.\n\nOpen: {deep_link}\n\nLet your HoD know if you need to reschedule.", 'talenttrack' ),
            ],
            'nl_NL' => [
                __( 'Je {review_type} staat gepland — {coach_name}', 'talenttrack' ),
                __( "Hoi {recipient_first_name},\n\nJe {review_type} staat gepland voor {due_date}.\n\nOpen: {deep_link}\n\nLaat je HoD weten als je moet verplaatsen.", 'talenttrack' ),
            ],
        ], $locale );
    }
}
