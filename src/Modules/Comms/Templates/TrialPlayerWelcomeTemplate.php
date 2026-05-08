<?php
namespace TT\Modules\Comms\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Template\AbstractTemplate;

/**
 * #0066 use case 5 — Trial player welcome.
 *
 * When a trial player is created (#0017 trial-module), they get a
 * welcome message with what to bring and where to be. Sender: system.
 * Recipients: trial player + parent.
 *
 * Tokens: {player_name} {team_name} {first_session_date} {first_session_location} {what_to_bring}
 */
final class TrialPlayerWelcomeTemplate extends AbstractTemplate {

    public function key(): string { return 'trial_player_welcome'; }
    public function label(): string { return __( 'Trial player welcome', 'talenttrack' ); }
    public function supportedChannels(): array { return [ 'email', 'inapp' ]; }

    protected function defaultCopy( string $channelKey, string $locale ): array {
        return self::pickLocale( [
            'en_US' => [
                __( 'Welcome to the trial — {player_name}', 'talenttrack' ),
                __( "Hi {recipient_first_name},\n\nWelcome to the trial for {team_name}!\n\nFirst day: {first_session_date}\nWhere: {first_session_location}\nWhat to bring: {what_to_bring}\n\nWe're looking forward to seeing what {player_name} can do.\n\nThe coaching team", 'talenttrack' ),
            ],
            'nl_NL' => [
                __( 'Welkom bij de proeftraining — {player_name}', 'talenttrack' ),
                __( "Hoi {recipient_first_name},\n\nWelkom bij de proeftraining voor {team_name}!\n\nEerste dag: {first_session_date}\nWaar: {first_session_location}\nMeenemen: {what_to_bring}\n\nWe kijken ernaar uit om te zien wat {player_name} laat zien.\n\nHet coaching team", 'talenttrack' ),
            ],
        ], $locale );
    }
}
