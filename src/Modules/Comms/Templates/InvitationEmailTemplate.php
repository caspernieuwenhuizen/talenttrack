<?php
namespace TT\Modules\Comms\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Template\AbstractTemplate;

/**
 * #1902 — invitation email. When an admin creates a parent/player
 * invitation, the accept link is emailed to the invitee (previously
 * link-only via copy / WhatsApp share). Sender: system. Recipient: the
 * invite's prefill email (not yet a WP user).
 *
 * Transactional (`*_OPERATIONAL` message type) — never opt-out-able and
 * not rate-limited, so an invitee never loses their invite.
 *
 * Tokens: {first_name} {inviter_name} {academy_name} {accept_url} {ttl_days}
 */
final class InvitationEmailTemplate extends AbstractTemplate {

    public function key(): string { return 'invitation_email'; }
    public function label(): string { return __( 'Invitation email', 'talenttrack' ); }
    public function supportedChannels(): array { return [ 'email' ]; }

    protected function defaultCopy( string $channelKey, string $locale ): array {
        return self::pickLocale( [
            'en_US' => [
                __( 'You\'re invited to join {academy_name}', 'talenttrack' ),
                __( "Hi {first_name},\n\n{inviter_name} has invited you to join {academy_name} on TalentTrack.\n\nAccept your invitation and set your password here:\n{accept_url}\n\nThis link expires in {ttl_days} days.\n\nSee you there!", 'talenttrack' ),
            ],
            'nl_NL' => [
                __( 'Je bent uitgenodigd voor {academy_name}', 'talenttrack' ),
                __( "Hoi {first_name},\n\n{inviter_name} heeft je uitgenodigd voor {academy_name} op TalentTrack.\n\nAccepteer je uitnodiging en stel hier je wachtwoord in:\n{accept_url}\n\nDeze link vervalt over {ttl_days} dagen.\n\nTot snel!", 'talenttrack' ),
            ],
        ], $locale );
    }
}
