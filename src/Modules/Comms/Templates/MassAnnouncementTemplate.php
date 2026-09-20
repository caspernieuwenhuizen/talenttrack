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
 * #3693 wired the send: `NewTeamAnnouncementWizard` (audience →
 * compose → confirm) and the `comms/announcements` routes, behind
 * `MassAnnouncementSender`. Two capabilities rather than the one this
 * docblock used to name — `tt_send_team_announcement` for a coach or
 * team manager reaching their own squads, `tt_send_academy_announcement`
 * for any team, an age group or the whole academy. This class stays the
 * rendering shell either way.
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
