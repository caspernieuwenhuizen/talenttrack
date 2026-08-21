<?php
namespace TT\Modules\Training\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 3 — Shape (#2497).
 *
 * How long, and how many players. Both prefilled: the duration from a
 * sensible default, the turnout from this team's recent attendance
 * (epic decision D14).
 *
 * The turnout number matters more than it looks. A 7v5 needs twelve
 * outfield players; proposing it to a squad of eight wastes the coach's
 * evening. The roster count is consistently too high — a sixteen-player
 * squad rarely puts sixteen on the pitch — so the average turnout is the
 * better starting point, and the coach can always override it because
 * they know about the school trip that the data does not.
 */
final class ShapeStep implements WizardStepInterface {

    private const MIN_MINUTES = 30;
    private const MAX_MINUTES = 120;

    public function slug(): string { return 'shape'; }

    public function label(): string { return __( 'Shape', 'talenttrack' ); }

    public function render( array $state ): void {
        $team     = (int) ( $state['team_id'] ?? 0 );
        $squad    = WizardContext::squadFor( $team );
        $minutes  = (int) ( $state['requested_duration_minutes'] ?? 75 );
        $expected = (int) ( $state['expected_players'] ?? $squad['value'] );

        echo '<p>' . esc_html__( 'How long is the training, and how many players do you expect?', 'talenttrack' ) . '</p>';

        echo '<label><span>' . esc_html__( 'Length in minutes', 'talenttrack' ) . '</span>'
            . '<input type="number" name="requested_duration_minutes" inputmode="numeric"'
            . ' min="' . (int) self::MIN_MINUTES . '" max="' . (int) self::MAX_MINUTES . '" step="5"'
            . ' value="' . esc_attr( (string) $minutes ) . '" required></label>';

        echo '<label><span>' . esc_html__( 'Players you expect', 'talenttrack' ) . '</span>'
            . '<input type="number" name="expected_players" inputmode="numeric" min="1" max="40"'
            . ' value="' . esc_attr( (string) $expected ) . '" required></label>';

        echo '<p class="description">' . esc_html( self::sourceHint( $squad ) ) . '</p>';

        echo '<p class="description">'
            . esc_html__( 'The age group sets its own ceiling on how hard and how long a training may be. If what you ask for goes past it, the next step says so rather than quietly trimming it.', 'talenttrack' )
            . '</p>';
    }

    /** @param array{value:int, source:string, roster:list<int>} $squad */
    private static function sourceHint( array $squad ): string {
        switch ( $squad['source'] ) {
            case 'attendance':
                return sprintf(
                    /* translators: %d is the average number of players at recent trainings. */
                    __( 'Suggested from recent attendance — an average of %d turned up at this team\'s last few trainings.', 'talenttrack' ),
                    (int) $squad['value']
                );
            case 'roster':
                return sprintf(
                    /* translators: %d is how many players are on the team. */
                    __( 'This team has no attendance recorded yet, so this is its full squad of %d. Expect fewer in practice.', 'talenttrack' ),
                    (int) $squad['value']
                );
        }
        return __( 'No squad information for this team yet, so this is a guess. Change it to what you expect.', 'talenttrack' );
    }

    public function validate( array $post, array $state ) {
        $minutes  = isset( $post['requested_duration_minutes'] ) ? absint( $post['requested_duration_minutes'] ) : 0;
        $expected = isset( $post['expected_players'] ) ? absint( $post['expected_players'] ) : 0;

        if ( $minutes < self::MIN_MINUTES || $minutes > self::MAX_MINUTES ) {
            return new \WP_Error( 'bad_duration', sprintf(
                /* translators: 1: shortest allowed session, 2: longest allowed session, both in minutes. */
                __( 'Give the training a length between %1$d and %2$d minutes.', 'talenttrack' ),
                self::MIN_MINUTES,
                self::MAX_MINUTES
            ) );
        }
        if ( $expected < 1 || $expected > 40 ) {
            return new \WP_Error( 'bad_squad', __( 'How many players do you expect? Somewhere between 1 and 40.', 'talenttrack' ) );
        }

        // The roster is carried forward whole: the composer scores
        // coverage against actual players, and the count alone cannot
        // say whose goals a drill would serve.
        $squad = WizardContext::squadFor( (int) ( $state['team_id'] ?? 0 ) );

        return [
            'requested_duration_minutes' => $minutes,
            'expected_players'           => $expected,
            'roster_player_ids'          => array_slice( $squad['roster'], 0, $expected ),
        ];
    }

    public function nextStep( array $state ): ?string { return 'proposal'; }

    public function submit( array $state ) { return null; }
}
