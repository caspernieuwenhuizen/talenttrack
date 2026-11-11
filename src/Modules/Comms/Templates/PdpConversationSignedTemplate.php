<?php
namespace TT\Modules\Comms\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Template\AbstractTemplate;

/**
 * #4981 (epic #4975) — a PDP conversation on a conversation form was signed
 * off: the player and the family can read it and confirm.
 *
 * Sent by `Send\PdpConversationSignedSend` on
 * `tt_pdp_conversation_signed_off`, once per sign-off. Under the PDP-ready
 * message type, so a reader who muted "PDP / evaluation ready to read" is
 * not reached by this one either.
 *
 * Tokens: {player_name} {conversation_name} {deep_link}
 */
final class PdpConversationSignedTemplate extends AbstractTemplate {

    public const KEY = 'pdp_conversation_signed';

    public function key(): string { return self::KEY; }
    public function label(): string { return __( 'PDP conversation ready to read and confirm', 'talenttrack' ); }
    public function supportedChannels(): array { return [ 'push', 'email', 'inapp' ]; }
    public function isEditable(): bool { return true; }

    protected function defaultCopy( string $channelKey, string $locale ): array {
        if ( $channelKey === 'email' ) {
            return self::pickLocale( [
                'en_US' => [
                    __( "{player_name}'s development talk is ready to read", 'talenttrack' ),
                    __( "Hi {recipient_first_name},\n\n{player_name}'s development talk ({conversation_name}) has been signed off. You can read the scores, the strongest quality and the two development points, and confirm that you have read them.\n\nOpen it here: {deep_link}\n\nThe coaching team", 'talenttrack' ),
                ],
            ], $locale );
        }
        return self::pickLocale( [
            'en_US' => [
                __( 'Development talk ready', 'talenttrack' ),
                __( "{player_name}'s development talk ({conversation_name}) is ready to read. Open it to confirm.", 'talenttrack' ),
            ],
        ], $locale );
    }
}
