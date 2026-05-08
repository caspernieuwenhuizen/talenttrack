<?php
namespace TT\Modules\Comms\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Template\AbstractTemplate;

/**
 * #0066 use case 15 — Safeguarding broadcast.
 *
 * High-priority "club-wide message from the safeguarding lead" with
 * delivery confirmation. Sender: club admin. Recipients: every parent
 * + every adult player.
 *
 * Operational message-type — opt-out forbidden, quiet-hours bypassed
 * (per `MessageType::SAFEGUARDING_BROADCAST` operational suffix).
 *
 * Tokens: {broadcast_subject} {broadcast_body} {sender_name} {sender_role}
 */
final class SafeguardingBroadcastTemplate extends AbstractTemplate {

    public function key(): string { return 'safeguarding_broadcast'; }
    public function label(): string { return __( 'Safeguarding broadcast', 'talenttrack' ); }
    public function supportedChannels(): array { return [ 'email', 'push', 'sms', 'inapp' ]; }

    protected function defaultCopy( string $channelKey, string $locale ): array {
        return self::pickLocale( [
            'en_US' => [
                __( '[Safeguarding] {broadcast_subject}', 'talenttrack' ),
                __( "{broadcast_body}\n\n— {sender_name}, {sender_role}", 'talenttrack' ),
            ],
            'nl_NL' => [
                __( '[Veiligheid] {broadcast_subject}', 'talenttrack' ),
                __( "{broadcast_body}\n\n— {sender_name}, {sender_role}", 'talenttrack' ),
            ],
        ], $locale );
    }
}
