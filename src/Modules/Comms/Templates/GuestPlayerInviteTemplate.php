<?php
namespace TT\Modules\Comms\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Template\AbstractTemplate;

/**
 * #0066 use case 6 — Guest-player invite.
 *
 * Short-lived invite for a guest to join a single activity (#0026).
 * Sender: coach. Recipients: guest's parent.
 *
 * Tokens: {player_name} {activity_title} {date} {kickoff_time} {location}
 */
final class GuestPlayerInviteTemplate extends AbstractTemplate {

    public function key(): string { return 'guest_player_invite'; }
    public function label(): string { return __( 'Guest-player invite', 'talenttrack' ); }
    public function supportedChannels(): array { return [ 'email', 'sms', 'whatsapp_link', 'inapp' ]; }

    protected function defaultCopy( string $channelKey, string $locale ): array {
        if ( $channelKey === 'email' ) {
            return self::pickLocale( [
                'en_US' => [
                    __( 'Guest invite — {player_name} for {activity_title}', 'talenttrack' ),
                    __( "Hi {recipient_first_name},\n\nWe would like to invite {player_name} as a guest to:\n\n{activity_title}\nDate: {date}\nTime: {kickoff_time}\nLocation: {location}\n\nLet us know if you can make it.\n\nThe coaching team", 'talenttrack' ),
                ],
                'nl_NL' => [
                    __( 'Gast-uitnodiging — {player_name} voor {activity_title}', 'talenttrack' ),
                    __( "Hoi {recipient_first_name},\n\nWe willen {player_name} graag uitnodigen als gast voor:\n\n{activity_title}\nDatum: {date}\nTijd: {kickoff_time}\nLocatie: {location}\n\nLaat het ons weten als het lukt.\n\nHet coaching team", 'talenttrack' ),
                ],
            ], $locale );
        }
        return self::pickLocale( [
            'en_US' => [
                __( 'Guest invite for {player_name}', 'talenttrack' ),
                __( "{player_name} is invited to {activity_title} on {date} at {kickoff_time}, {location}.", 'talenttrack' ),
            ],
            'nl_NL' => [
                __( 'Gast-uitnodiging voor {player_name}', 'talenttrack' ),
                __( "{player_name} is uitgenodigd voor {activity_title} op {date} om {kickoff_time}, {location}.", 'talenttrack' ),
            ],
        ], $locale );
    }
}
