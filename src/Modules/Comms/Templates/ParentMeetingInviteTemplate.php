<?php
namespace TT\Modules\Comms\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Template\AbstractTemplate;

/**
 * #0066 use case 4 — Parent-meeting invite.
 *
 * Calendar invite + reminder for the periodic parent meeting (#0017
 * sprint 5). Sender: coach. Recipients: parent.
 *
 * Tokens: {player_name} {meeting_date} {meeting_time} {meeting_location} {coach_name}
 */
final class ParentMeetingInviteTemplate extends AbstractTemplate {

    public function key(): string { return 'parent_meeting_invite'; }
    public function label(): string { return __( 'Parent-meeting invite', 'talenttrack' ); }
    public function supportedChannels(): array { return [ 'email', 'inapp' ]; }

    protected function defaultCopy( string $channelKey, string $locale ): array {
        return self::pickLocale( [
            'en_US' => [
                __( 'Parent meeting invitation — {player_name}', 'talenttrack' ),
                __( "Hi {recipient_first_name},\n\nWe would like to invite you to a parent meeting about {player_name}.\n\nWhen: {meeting_date} at {meeting_time}\nWhere: {meeting_location}\n\nLet us know if the time doesn't work and we'll reschedule.\n\n{coach_name}", 'talenttrack' ),
            ],
            'nl_NL' => [
                __( 'Uitnodiging oudergesprek — {player_name}', 'talenttrack' ),
                __( "Hoi {recipient_first_name},\n\nWe nodigen je graag uit voor een oudergesprek over {player_name}.\n\nWanneer: {meeting_date} om {meeting_time}\nWaar: {meeting_location}\n\nLaat het ons weten als de tijd niet schikt, dan plannen we opnieuw.\n\n{coach_name}", 'talenttrack' ),
            ],
        ], $locale );
    }
}
