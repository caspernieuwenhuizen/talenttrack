<?php
namespace TT\Modules\MatchAnalysis\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\MatchAnalysis\Frontend\MatchAnalysisAssets;
use TT\Modules\MatchAnalysis\MatchAnalysisEnums;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisComposer;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * OverallStep — the result, and the coach's read of the match in a few
 * sentences.
 *
 * The score is shown, never asked for: it belongs to match execution (or
 * to the activity form), and a second place to type it is a second place
 * for it to be wrong.
 */
final class OverallStep implements WizardStepInterface {

    public function slug(): string  { return 'overall'; }
    public function label(): string { return __( 'The match', 'talenttrack' ); }

    public function render( array $state ): void {
        $activity_id = self::activityId( $state );

        if ( $activity_id <= 0 ) {
            echo '<p class="tt-notice tt-notice-error">'
                . esc_html__( 'Open the match analysis from a match activity\'s detail page.', 'talenttrack' )
                . '</p>';
            return;
        }

        $payload = ( new MatchAnalysisComposer() )->forActivity( $activity_id, false );
        if ( $payload === null ) {
            echo '<p class="tt-notice tt-notice-error">'
                . esc_html__( 'A match analysis can only be written for a match activity.', 'talenttrack' )
                . '</p>';
            return;
        }

        MatchAnalysisAssets::enqueue();

        echo '<input type="hidden" name="activity_id" value="' . esc_attr( (string) $activity_id ) . '" />';

        /** @var object $activity */
        $activity = $payload['activity'];
        $result   = (array) $payload['result'];

        $meta = [];
        $date = (string) ( $activity->session_date ?? '' );
        if ( $date !== '' ) $meta[] = date_i18n( (string) get_option( 'date_format' ), strtotime( $date ) );
        if ( (string) ( $result['opponent'] ?? '' ) !== '' ) $meta[] = (string) $result['opponent'];
        if ( ! empty( $result['has_score'] ) ) {
            $meta[] = sprintf( '%d – %d', (int) $result['home_score'], (int) $result['away_score'] );
        }

        if ( $meta ) {
            echo '<p class="tt-ma__meta">' . esc_html( implode( ' · ', $meta ) ) . '</p>';
        }

        $summary = isset( $state['summary'] )
            ? (string) $state['summary']
            : (string) $payload['summary'];

        echo '<label class="tt-ma__label" for="tt-ma-wz-summary">'
            . esc_html__( 'How did the match go, in a few sentences?', 'talenttrack' )
            . '</label>';
        echo '<textarea class="tt-input tt-ma__summary" id="tt-ma-wz-summary" name="summary" rows="5">'
            . esc_textarea( $summary )
            . '</textarea>';
        echo '<p class="tt-ma__hint">'
            . esc_html__( 'Skip anything you have nothing to say about — an empty section is a valid answer.', 'talenttrack' )
            . '</p>';
    }

    public function validate( array $post, array $state ) {
        $activity_id = isset( $post['activity_id'] ) ? (int) $post['activity_id'] : self::activityId( $state );

        if ( $activity_id <= 0 ) {
            return new \WP_Error(
                'tt_ma_no_activity',
                __( 'This analysis is not attached to a match. Open it from the match activity.', 'talenttrack' )
            );
        }

        $activity = MatchAnalysisComposer::activity( $activity_id );
        if ( ! MatchAnalysisComposer::supportsActivity( $activity ) ) {
            return new \WP_Error(
                'tt_ma_not_a_match',
                __( 'A match analysis can only be written for a match activity.', 'talenttrack' )
            );
        }

        return [
            'activity_id' => $activity_id,
            'summary'     => sanitize_textarea_field( (string) ( $post['summary'] ?? '' ) ),
        ];
    }

    public function nextStep( array $state ): ?string {
        return 'team-functions';
    }

    public function submit( array $state ) {
        return null;
    }

    /**
     * @param array<string,mixed> $state
     */
    public static function activityId( array $state ): int {
        if ( isset( $state['activity_id'] ) ) return (int) $state['activity_id'];

        return isset( $_GET['activity_id'] ) ? absint( $_GET['activity_id'] ) : 0;
    }

    /**
     * Section keys are the wizard's own vocabulary too — kept here so the
     * step classes agree with `MatchAnalysisEnums` without each repeating
     * the list.
     *
     * @return list<string>
     */
    public static function teamFunctionKeys(): array {
        return array_values( array_filter(
            MatchAnalysisEnums::ratedSectionKeys(),
            static fn( string $key ): bool => $key !== MatchAnalysisEnums::SECTION_SET_PIECES
        ) );
    }
}
