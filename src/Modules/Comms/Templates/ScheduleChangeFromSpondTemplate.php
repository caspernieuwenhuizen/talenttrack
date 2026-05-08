<?php
namespace TT\Modules\Comms\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Template\AbstractTemplate;

/**
 * #0066 use case 9 — Schedule change from Spond.
 *
 * When a Spond-imported activity changes time/location, alert the
 * team. Depends on #0062 / #0031 shipping first — when the Spond
 * sync detects a delta, it fires the trigger that calls this template.
 * Until #0062 ships, the template is registered but not actively
 * dispatched.
 *
 * Sender: system. Recipients: parents (youth) / players (adult).
 *
 * Tokens: {activity_title} {old_date} {old_time} {new_date} {new_time} {old_location} {new_location} {deep_link}
 */
final class ScheduleChangeFromSpondTemplate extends AbstractTemplate {

    public function key(): string { return 'schedule_change_from_spond'; }
    public function label(): string { return __( 'Schedule change (from Spond)', 'talenttrack' ); }
    public function supportedChannels(): array { return [ 'push', 'sms', 'email', 'inapp' ]; }

    protected function defaultCopy( string $channelKey, string $locale ): array {
        return self::pickLocale( [
            'en_US' => [
                __( 'Schedule changed — {activity_title}', 'talenttrack' ),
                __( "{activity_title} has been rescheduled.\n\nNew date: {new_date} at {new_time}\nNew location: {new_location}\n\nOpen: {deep_link}", 'talenttrack' ),
            ],
            'nl_NL' => [
                __( 'Schema gewijzigd — {activity_title}', 'talenttrack' ),
                __( "{activity_title} is verplaatst.\n\nNieuwe datum: {new_date} om {new_time}\nNieuwe locatie: {new_location}\n\nOpen: {deep_link}", 'talenttrack' ),
            ],
        ], $locale );
    }
}
