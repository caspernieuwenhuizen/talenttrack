<?php
namespace TT\Modules\Comms\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Template\AbstractTemplate;

/**
 * #0066 use case 11 — Onboarding nudge for inactive accounts.
 *
 * "We noticed you haven't logged in for 30 days; here's what's new on
 * your child" — adoption tool, not spam. Frequency-capped.
 *
 * Trigger: weekly cron `tt_comms_onboarding_nudge_cron`. Finds
 * users whose `last_login >= 30 days ago` AND has at least one linked
 * player. Sends at most one nudge per 60 days (frequency cap).
 *
 * Sender: system. Recipients: parents.
 *
 * Tokens: {player_name} {recent_evaluations_count} {recent_goals_count} {deep_link}
 *
 * A count the payload leaves out is a section the child keeps from this
 * parent: its line is dropped, rather than read as "0".
 */
final class OnboardingNudgeInactiveTemplate extends AbstractTemplate {

    private const COUNT_TOKENS = [ 'recent_evaluations_count', 'recent_goals_count' ];

    public function key(): string { return 'onboarding_nudge_inactive'; }
    public function label(): string { return __( 'Onboarding nudge (inactive accounts)', 'talenttrack' ); }
    public function supportedChannels(): array { return [ 'email' ]; }

    protected function defaultCopy( string $channelKey, string $locale ): array {
        return self::pickLocale( [
            'en_US' => [
                __( "What's new on {player_name}", 'talenttrack' ),
                __( "Hi {recipient_first_name},\n\nWe haven't seen you log in for a while. Here's what's been happening with {player_name}:\n\n• {recent_evaluations_count} new evaluation(s)\n• {recent_goals_count} new goal(s)\n\nTake a look: {deep_link}\n\nThe coaching team", 'talenttrack' ),
            ],
        ], $locale );
    }

    protected function shapeCopy( array $copy, array $payload ): array {
        $body = $copy[1];
        foreach ( self::COUNT_TOKENS as $token ) {
            if ( isset( $payload[ $token ] ) ) continue;
            $body = (string) preg_replace( '/^[^\n]*\{' . $token . '\}[^\n]*(\n|$)/m', '', $body );
        }
        $body = (string) preg_replace( '/\n{3,}/', "\n\n", $body );
        return [ $copy[0], $body ];
    }
}
