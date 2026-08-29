<?php
namespace TT\Modules\Comms\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Template\AbstractTemplate;

/**
 * #0066 use case 3 — PDP / evaluation ready to read.
 *
 * "Your development plan is ready, tap to view." Links into the player
 * profile, not the doc itself. Sender: system on coach action.
 * Recipients: player + parent.
 *
 * Editable per club (top-5).
 *
 * Trigger: `Send\PdpReadySend` listens on `tt_pdp_verdict_signed_off`.
 * A PDP has no "published" state — see that class for why sign-off is
 * the moment a family should hear about.
 *
 * Tokens: {player_name} {deep_link} {season_name}
 */
final class PdpReadyTemplate extends AbstractTemplate {

    public const KEY = 'pdp_ready';

    public function key(): string { return self::KEY; }
    public function label(): string { return __( 'PDP / evaluation ready to read', 'talenttrack' ); }
    public function supportedChannels(): array { return [ 'push', 'email', 'inapp' ]; }
    public function isEditable(): bool { return true; }

    protected function defaultCopy( string $channelKey, string $locale ): array {
        if ( $channelKey === 'email' ) {
            return self::pickLocale( [
                'en_US' => [
                    __( "{player_name}'s PDP is ready", 'talenttrack' ),
                    __( "Hi {recipient_first_name},\n\n{player_name}'s development plan for {season_name} is ready to read.\n\nOpen it here: {deep_link}\n\nThe coaching team", 'talenttrack' ),
                ],
                'nl_NL' => [
                    __( "Het ontwikkelingsplan van {player_name} is klaar", 'talenttrack' ),
                    __( "Hoi {recipient_first_name},\n\nHet ontwikkelingsplan van {player_name} voor {season_name} is klaar om te lezen.\n\nOpen het hier: {deep_link}\n\nHet coaching team", 'talenttrack' ),
                ],
            ], $locale );
        }
        return self::pickLocale( [
            'en_US' => [
                __( 'PDP ready', 'talenttrack' ),
                __( "{player_name}'s development plan for {season_name} is ready to read.", 'talenttrack' ),
            ],
            'nl_NL' => [
                __( 'Plan klaar', 'talenttrack' ),
                __( "Het ontwikkelingsplan van {player_name} voor {season_name} is klaar.", 'talenttrack' ),
            ],
        ], $locale );
    }
}
