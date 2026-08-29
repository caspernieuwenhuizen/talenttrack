<?php
namespace TT\Modules\Comms\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Template\AbstractTemplate;

/**
 * #2604 — "email me this link", from the desktop-only prompt on a phone.
 *
 * Addressed to the requester's own account and carrying nothing but the
 * link they just asked for. Operational (see
 * `MessageType::DESKTOP_LINK`): the user is standing in front of the
 * screen waiting, so neither an old opt-out nor a quiet-hours window may
 * hold it.
 *
 * Tokens: {site_name} {link}
 */
final class DesktopLinkTemplate extends AbstractTemplate {

    public const KEY = 'desktop_link';

    public function key(): string { return self::KEY; }

    public function label(): string { return __( 'Desktop link you asked for', 'talenttrack' ); }

    public function supportedChannels(): array { return [ 'email' ]; }

    protected function defaultCopy( string $channelKey, string $locale ): array {
        return self::pickLocale( [
            'en_US' => [
                __( 'Your {site_name} desktop link', 'talenttrack' ),
                __( "You asked us to email you the link to a desktop-only TalentTrack page.\n\n{link}\n\nOpen this on a laptop or computer.", 'talenttrack' ),
            ],
            'nl_NL' => [
                __( 'Je desktoplink voor {site_name}', 'talenttrack' ),
                __( "Je hebt ons gevraagd de link naar een TalentTrack-pagina voor desktop te mailen.\n\n{link}\n\nOpen deze op een laptop of computer.", 'talenttrack' ),
            ],
        ], $locale );
    }
}
