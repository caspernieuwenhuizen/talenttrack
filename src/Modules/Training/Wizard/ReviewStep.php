<?php
namespace TT\Modules\Training\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Training\Services\TrainingPlanComposer;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 5 — Review (#2497).
 *
 * What this session does for the squad, then save.
 *
 * The coverage line is the reason the module belongs in TalentTrack: it
 * names the players whose open development goals this session actually
 * serves. Where no goal carries a principle yet the step says so plainly
 * rather than showing a confident zero — see #2566, which is the other
 * half of that join.
 */
final class ReviewStep implements WizardStepInterface {

    public function slug(): string { return 'review'; }

    public function label(): string { return __( 'Review', 'talenttrack' ); }

    public function render( array $state ): void {
        $draft = ( new TrainingPlanComposer() )->preview( ProposalStep::payloadFrom( $state ) );

        $minutes = 0;
        foreach ( $draft['blocks'] as $block ) $minutes += (int) $block['duration_minutes'];

        echo '<p>' . esc_html( sprintf(
            /* translators: 1: theme name, 2: date, 3: total minutes. */
            __( '%1$s on %2$s — %3$d minutes.', 'talenttrack' ),
            WizardContext::themeLabel( (string) ( $state['tactical_theme'] ?? '' ) ) ?: __( 'No particular theme', 'talenttrack' ),
            (string) ( $state['session_date'] ?? '' ),
            $minutes
        ) ) . '</p>';

        self::renderCoverage( $draft['coverage'] );

        foreach ( $draft['warnings'] as $warning ) {
            if ( ( $warning['severity'] ?? '' ) === 'block' ) continue;
            echo '<p class="description">' . esc_html( ProposalStep::warningText( (string) $warning['code'] ) ) . '</p>';
        }

        echo '<label><span>' . esc_html__( 'Name this plan', 'talenttrack' ) . '</span>'
            . '<input type="text" name="title" maxlength="190" value="' . esc_attr( self::defaultTitle( $state ) ) . '"></label>';
    }

    /** @param array{principle_ids:list<int>, player_ids:list<int>, missed_player_ids:list<int>} $coverage */
    private static function renderCoverage( array $coverage ): void {
        $hit    = count( $coverage['player_ids'] );
        $missed = count( $coverage['missed_player_ids'] );

        if ( $hit === 0 && $missed === 0 ) {
            echo '<p class="description">'
                . esc_html__( 'None of this squad\'s goals name a playing principle yet, so there is nothing to match this training against. Once goals are linked to principles, this is where you will see which players it serves.', 'talenttrack' )
                . '</p>';
            return;
        }

        echo '<p>' . esc_html( sprintf(
            /* translators: 1: how many players this session serves, 2: how many it does not. */
            _n(
                'This training works on an open goal for %1$d player. %2$d others have goals it does not touch.',
                'This training works on an open goal for %1$d players. %2$d others have goals it does not touch.',
                $hit,
                'talenttrack'
            ),
            $hit,
            $missed
        ) ) . '</p>';

        if ( ! $coverage['player_ids'] ) return;

        echo '<ul>';
        foreach ( array_slice( $coverage['player_ids'], 0, 8 ) as $player_id ) {
            echo '<li>' . esc_html( self::playerName( (int) $player_id ) ) . '</li>';
        }
        echo '</ul>';
    }

    private static function playerName( int $player_id ): string {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT first_name, last_name FROM {$wpdb->prefix}tt_players WHERE id = %d",
            $player_id
        ) );
        if ( ! $row ) return __( 'Unknown player', 'talenttrack' );

        return trim( (string) $row->first_name . ' ' . (string) $row->last_name );
    }

    /** @param array<string,mixed> $state */
    private static function defaultTitle( array $state ): string {
        $theme = WizardContext::themeLabel( (string) ( $state['tactical_theme'] ?? '' ) );
        $date  = (string) ( $state['session_date'] ?? '' );

        if ( $theme !== '' && $date !== '' ) {
            /* translators: 1: theme name, 2: session date. */
            return mb_substr( sprintf( __( '%1$s · %2$s', 'talenttrack' ), $theme, $date ), 0, 190 );
        }
        return __( 'Training plan', 'talenttrack' );
    }

    public function validate( array $post, array $state ) {
        $title = isset( $post['title'] ) ? trim( (string) $post['title'] ) : '';
        return [ 'title' => mb_substr( $title, 0, 190 ) ];
    }

    public function nextStep( array $state ): ?string { return null; }

    public function submit( array $state ) {
        $payload = ProposalStep::payloadFrom( $state );

        $title = trim( (string) ( $state['title'] ?? '' ) );
        if ( $title !== '' ) $payload['title'] = $title;

        $result = ( new TrainingPlanComposer() )->generate( $payload );

        if ( $result['plan_id'] === null ) {
            $reasons = [];
            foreach ( $result['warnings'] as $warning ) {
                if ( ( $warning['severity'] ?? '' ) === 'block' ) {
                    $reasons[] = ProposalStep::warningText( (string) $warning['code'] );
                }
            }
            return new \WP_Error(
                'cannot_compose',
                $reasons
                    ? implode( ' ', $reasons )
                    : __( 'This training could not be saved. Go back and try a different length or theme.', 'talenttrack' )
            );
        }

        return [
            'redirect_url' => add_query_arg(
                [ 'tt_view' => 'training-plan', 'id' => (int) $result['plan_id'] ],
                RecordLink::dashboardUrl()
            ),
        ];
    }
}
