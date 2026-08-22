<?php
namespace TT\Modules\Comms\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Template\AbstractTemplate;

/**
 * #2634 (epic #2629) — the periodic alert digest.
 *
 * One message carrying a user's open alerts, for people who do not open the
 * dashboard often enough for the in-app surfaces to reach them.
 *
 * Opt-in only (epic decision 10): nobody receives this until they ask for
 * it. The app may nag you in-app; it may not put unsolicited mail in your
 * inbox. The tension worth remembering is that the coach who never logs in
 * is exactly who this was built for, and they will not opt in — if the pilot
 * shows the digest reaching nobody, that is evidence to revisit decision 10,
 * not a reason to quietly flip the default.
 *
 * Email only in v1. A push carrying "you have 7 alerts" is a notification
 * that cannot be acted on from the lock screen and cannot be summarised in
 * the space available; per-alert push is a separate, per-definition choice.
 *
 * Tokens: {recipient_first_name} {alert_count} {alert_lines} {deep_link}
 */
final class AlertDigestTemplate extends AbstractTemplate {

    public function key(): string { return 'alert_digest'; }

    public function label(): string { return __( 'Alert summary', 'talenttrack' ); }

    public function supportedChannels(): array { return [ 'email' ]; }

    protected function defaultCopy( string $channelKey, string $locale ): array {
        return self::pickLocale( [
            'en_US' => [
                __( '{alert_count} things need your attention', 'talenttrack' ),
                __( "Hi {recipient_first_name},\n\nThese need a look:\n\n{alert_lines}\n\nEach one clears itself once the underlying thing is fixed — there is nothing to tick off.\n\nOpen TalentTrack: {deep_link}", 'talenttrack' ),
            ],
            'nl_NL' => [
                __( '{alert_count} dingen vragen je aandacht', 'talenttrack' ),
                __( "Hoi {recipient_first_name},\n\nHier moet even naar gekeken worden:\n\n{alert_lines}\n\nElke melding verdwijnt vanzelf zodra het onderliggende is opgelost — je hoeft niets af te vinken.\n\nOpen TalentTrack: {deep_link}", 'talenttrack' ),
            ],
        ], $locale );
    }
}
