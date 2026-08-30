<?php
namespace TT\Modules\Comms\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Template\AbstractTemplate;

/**
 * #0066 use case 5 — Trial player welcome.
 *
 * When a trial case is opened (#0017 trial-module), the player and their
 * parents get a welcome message. Sender: system. Recipients: trial player
 * + parent, via `RecipientResolver`.
 *
 * Trigger: `Send\TrialPlayerWelcomeSend` listens on `tt_trial_started`,
 * which `TrialCasesRepository::create()` fires for all four callers.
 *
 * ## Why the copy promises so little (#2605)
 *
 * The original wrote `{team_name}`, `{first_session_location}` and
 * `{what_to_bring}`, and **none of the three has a column behind it**. A
 * trial case carries a player, a track, a start date and an end date; it
 * has no location field, no "what to bring" field, and it hangs off a
 * track rather than a team. The literal string `what_to_bring` appeared in
 * exactly one file in the repository, which was this one.
 *
 * So the message as written went out to a family reading "Where:" and
 * "What to bring:" with nothing after them — as the first thing the
 * academy ever sent them. The copy now promises only what a trial case
 * knows and says a coach will follow up with the rest, which is what
 * actually happens. Adding the two fields to `tt_trial_cases` stays
 * available if academies ask for it; it should not be smuggled in as part
 * of wiring a trigger.
 *
 * Tokens: {player_name} {start_date}
 */
final class TrialPlayerWelcomeTemplate extends AbstractTemplate {

    public const KEY = 'trial_player_welcome';

    public function key(): string { return self::KEY; }
    public function label(): string { return __( 'Trial player welcome', 'talenttrack' ); }
    public function supportedChannels(): array { return [ 'email', 'inapp' ]; }

    protected function defaultCopy( string $channelKey, string $locale ): array {
        return self::pickLocale( [
            'en_US' => [
                __( 'Welcome to the trial — {player_name}', 'talenttrack' ),
                __( "Hi {recipient_first_name},\n\nWelcome! {player_name}'s trial with us starts on {start_date}.\n\nOne of the coaches will be in touch before then with the time, the place and what to bring.\n\nWe're looking forward to seeing what {player_name} can do.\n\nThe coaching team", 'talenttrack' ),
            ],
            'nl_NL' => [
                __( 'Welkom bij de proefperiode — {player_name}', 'talenttrack' ),
                __( "Hoi {recipient_first_name},\n\nWelkom! De proefperiode van {player_name} bij ons begint op {start_date}.\n\nEen van de trainers neemt vóór die tijd contact op met het tijdstip, de plek en wat er mee moet.\n\nWe kijken ernaar uit om te zien wat {player_name} laat zien.\n\nHet trainersteam", 'talenttrack' ),
            ],
        ], $locale );
    }
}
