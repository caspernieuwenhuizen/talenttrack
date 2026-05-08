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
 */
final class OnboardingNudgeInactiveTemplate extends AbstractTemplate {

    public function key(): string { return 'onboarding_nudge_inactive'; }
    public function label(): string { return __( 'Onboarding nudge (inactive accounts)', 'talenttrack' ); }
    public function supportedChannels(): array { return [ 'email' ]; }

    protected function defaultCopy( string $channelKey, string $locale ): array {
        return self::pickLocale( [
            'en_US' => [
                __( "What's new on {player_name}", 'talenttrack' ),
                __( "Hi {recipient_first_name},\n\nWe haven't seen you log in for a while. Here's what's been happening with {player_name}:\n\n• {recent_evaluations_count} new evaluation(s)\n• {recent_goals_count} new goal(s)\n\nTake a look: {deep_link}\n\nThe coaching team", 'talenttrack' ),
            ],
            'nl_NL' => [
                __( "Wat is er nieuw bij {player_name}", 'talenttrack' ),
                __( "Hoi {recipient_first_name},\n\nWe hebben je een tijdje niet zien inloggen. Dit is er gebeurd bij {player_name}:\n\n• {recent_evaluations_count} nieuwe evaluatie(s)\n• {recent_goals_count} nieuw(e) doel(en)\n\nKijk eens: {deep_link}\n\nHet coaching team", 'talenttrack' ),
            ],
        ], $locale );
    }
}
