<?php
namespace TT\Modules\Comms\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Template\AbstractTemplate;

/**
 * #0066 use case 8 — Attendance flag.
 *
 * "Player X has missed 3 sessions in a row" — internal coach-to-coach
 * / coach-to-HoD escalation. Sender: system. Recipients: coach + HoD.
 *
 * Trigger: daily cron `tt_comms_attendance_flag_cron` checks every
 * club's `tt_attendance` for players with 3+ consecutive absences in
 * the trailing 30 days.
 *
 * Tokens: {player_name} {team_name} {missed_count} {deep_link}
 */
final class AttendanceFlagTemplate extends AbstractTemplate {

    public function key(): string { return 'attendance_flag'; }
    public function label(): string { return __( 'Attendance flag', 'talenttrack' ); }
    public function supportedChannels(): array { return [ 'email', 'inapp' ]; }

    protected function defaultCopy( string $channelKey, string $locale ): array {
        return self::pickLocale( [
            'en_US' => [
                __( 'Attendance flag — {player_name}', 'talenttrack' ),
                __( "Hi {recipient_first_name},\n\n{player_name} ({team_name}) has missed {missed_count} activities in a row. You may want to follow up.\n\nOpen: {deep_link}", 'talenttrack' ),
            ],
            'nl_NL' => [
                __( 'Aanwezigheid signaal — {player_name}', 'talenttrack' ),
                __( "Hoi {recipient_first_name},\n\n{player_name} ({team_name}) heeft {missed_count} activiteiten op rij gemist. Mogelijk wil je opvolgen.\n\nOpen: {deep_link}", 'talenttrack' ),
            ],
        ], $locale );
    }
}
