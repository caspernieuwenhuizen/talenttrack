<?php
namespace TT\Modules\Comms\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Template\AbstractTemplate;

/**
 * #0066 use case 14 — Mass announcement.
 *
 * "Training cancelled this weekend due to weather" — coach picks an
 * audience scope (team / age group / whole club) and writes one
 * message. Sender: coach or HoD. Recipients: scoped audience.
 *
 * Spec wizard plan: ships as a multi-step wizard (audience scope →
 * recipients preview → message → confirm + send), gated on
 * `tt_send_announcements`. Wizard wires from the Comms admin page
 * once it lands; the template is the rendering shell either way.
 *
 * Tokens: {announcement_subject} {announcement_body} {sender_name}
 */
final class MassAnnouncementTemplate extends AbstractTemplate {

    public function key(): string { return 'mass_announcement'; }
    public function label(): string { return __( 'Mass announcement', 'talenttrack' ); }
    public function supportedChannels(): array { return [ 'email', 'push', 'inapp' ]; }
    public function isEditable(): bool { return true; }

    protected function defaultCopy( string $channelKey, string $locale ): array {
        return self::pickLocale( [
            'en_US' => [
                __( '{announcement_subject}', 'talenttrack' ),
                __( "{announcement_body}\n\n— {sender_name}", 'talenttrack' ),
            ],
            'nl_NL' => [
                __( '{announcement_subject}', 'talenttrack' ),
                __( "{announcement_body}\n\n— {sender_name}", 'talenttrack' ),
            ],
        ], $locale );
    }
}
