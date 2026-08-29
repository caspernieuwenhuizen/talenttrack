<?php
namespace TT\Modules\MatchAnalysis\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\MatchAnalysis\MatchAnalysisEnums;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisComposer;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisWriter;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * ReviewStep — read it back, then save.
 *
 * The read-back matters more here than on most wizards: the player items
 * land on children's timelines, and a coach should see the sentence that
 * will appear on a player's file before it does, not after.
 *
 * `submit()` writes through `MatchAnalysisWriter`, the same path the REST
 * API and the flat form use, so the wizard cannot end up with its own idea
 * of what saving means.
 */
final class ReviewStep implements WizardStepInterface {

    public function slug(): string  { return 'review'; }
    public function label(): string { return __( 'Review', 'talenttrack' ); }

    public function render( array $state ): void {
        \TT\Modules\MatchAnalysis\Frontend\MatchAnalysisAssets::enqueue();

        $summary  = trim( (string) ( $state['summary'] ?? '' ) );
        $sections = isset( $state['sections'] ) && is_array( $state['sections'] ) ? $state['sections'] : [];
        $items    = isset( $state['players'] ) && is_array( $state['players'] ) ? $state['players'] : [];

        echo '<div class="tt-ma tt-ma--review">';

        if ( $summary !== '' ) {
            echo '<section class="tt-ma__section">';
            echo '<h2 class="tt-ma__section-title">'
                . esc_html( MatchAnalysisEnums::sectionLabel( MatchAnalysisEnums::SECTION_GENERAL ) )
                . '</h2>';
            echo '<p class="tt-ma__read-summary">' . nl2br( esc_html( $summary ) ) . '</p>';
            echo '</section>';
        }

        $written = false;
        foreach ( MatchAnalysisEnums::ratedSectionKeys() as $key ) {
            $row    = isset( $sections[ $key ] ) && is_array( $sections[ $key ] ) ? $sections[ $key ] : [];
            $rating = $row['rating'] ?? null;
            $notes  = isset( $row['notes'] ) && is_array( $row['notes'] ) ? array_values( $row['notes'] ) : [];

            if ( $rating === null && empty( $notes ) ) continue;
            $written = true;

            echo '<section class="tt-ma__section">';
            echo '<h2 class="tt-ma__section-title">' . esc_html( MatchAnalysisEnums::sectionLabel( $key ) );
            if ( $rating !== null ) {
                printf(
                    ' <span class="tt-ma__rating-pill" data-rating="%s">%s</span>',
                    esc_attr( (string) $rating ),
                    esc_html( MatchAnalysisEnums::ratings()[ $rating ] ?? '' )
                );
            }
            echo '</h2>';

            if ( $notes ) {
                echo '<ul class="tt-ma__read-bullets">';
                foreach ( $notes as $note ) {
                    self::renderReadNote( $note );
                }
                echo '</ul>';
            }
            echo '</section>';
        }

        if ( $items ) {
            $names = self::namesFor( $state );

            echo '<section class="tt-ma__section tt-ma__section--players">';
            echo '<h2 class="tt-ma__section-title">' . esc_html__( 'Players', 'talenttrack' ) . '</h2>';
            echo '<p class="tt-ma__hint">'
                . esc_html__( 'Each of these lands on that player\'s own timeline, visible to staff.', 'talenttrack' )
                . '</p>';
            echo '<ul class="tt-ma__read-players">';
            foreach ( $items as $pid => $item ) {
                echo '<li class="tt-ma__read-player">';
                printf(
                    '<span class="tt-ma__player-name">%s</span>',
                    esc_html( (string) ( $names[ (int) $pid ] ?? '' ) )
                );
                $marker = (string) ( $item['marker'] ?? '' );
                if ( $marker !== '' ) {
                    printf(
                        ' <span class="tt-ma__marker-pill" data-marker="%s">%s</span>',
                        esc_attr( $marker ),
                        esc_html( MatchAnalysisEnums::markerLabel( $marker ) )
                    );
                }
                $notes = isset( $item['notes'] ) && is_array( $item['notes'] ) ? array_values( $item['notes'] ) : [];
                if ( $notes ) {
                    echo '<ul class="tt-ma__read-bullets">';
                    foreach ( $notes as $note ) {
                        self::renderReadNote( $note );
                    }
                    echo '</ul>';
                }
                echo '</li>';
            }
            echo '</ul>';
            echo '</section>';
        }

        if ( ! $written && $summary === '' && ! $items ) {
            echo '<p class="tt-notice">'
                . esc_html__( 'Nothing has been written yet. Step back if you meant to say something; saving an empty analysis is allowed.', 'talenttrack' )
                . '</p>';
        }

        echo '</div>';
    }

    public function validate( array $post, array $state ) {
        return [];
    }

    public function nextStep( array $state ): ?string {
        return null;
    }

    public function submit( array $state ) {
        $activity_id = OverallStep::activityId( $state );

        $composer = new MatchAnalysisComposer();
        $payload  = $composer->forActivity( $activity_id, true );
        if ( $payload === null || (int) $payload['analysis_id'] <= 0 ) {
            return new \WP_Error(
                'tt_ma_save_failed',
                __( 'The analysis could not be saved. Open the match and try again.', 'talenttrack' )
            );
        }

        $analysis_id = (int) $payload['analysis_id'];

        $minutes = [];
        foreach ( (array) $payload['players'] as $row ) {
            $minutes[ (int) $row['player_id'] ] = $row['minutes'] !== null ? (int) $row['minutes'] : null;
        }

        $sections = isset( $state['sections'] ) && is_array( $state['sections'] ) ? $state['sections'] : [];
        $players  = isset( $state['players'] ) && is_array( $state['players'] ) ? $state['players'] : [];

        ( new MatchAnalysisWriter() )->apply(
            $analysis_id,
            [
                'summary'  => (string) ( $state['summary'] ?? '' ),
                'status'   => MatchAnalysisEnums::STATUS_FINAL,
                'sections' => $sections,
                'players'  => $players,
            ],
            $minutes
        );

        return [
            'redirect_url' => add_query_arg(
                [ 'tt_view' => 'match-analysis', 'activity_id' => $activity_id ],
                RecordLink::dashboardUrl()
            ),
        ];
    }

    /**
     * One note in the read-back, carrying its + / − if it has one (#3091).
     *
     * The review step is where a coach checks what they are about to
     * commit, so a mark they set two steps ago has to be visible here or
     * the check is incomplete.
     *
     * @param mixed $note
     */
    private static function renderReadNote( $note ): void {
        $body    = '';
        $valence = '';

        if ( is_array( $note ) ) {
            $body    = trim( (string) ( $note['body'] ?? '' ) );
            $valence = (string) ( $note['valence'] ?? '' );
        } else {
            $body = trim( (string) $note );
        }

        if ( $body === '' ) return;

        printf( '<li data-valence="%s">', esc_attr( $valence ) );

        if ( MatchAnalysisEnums::isValence( $valence ) ) {
            printf(
                '<span class="tt-ma__read-mark" data-valence="%s"><span aria-hidden="true">%s</span><span class="tt-ma__sr">%s</span></span> ',
                esc_attr( $valence ),
                esc_html( MatchAnalysisEnums::valenceGlyph( $valence ) ),
                esc_html( MatchAnalysisEnums::valenceLabel( $valence ) )
            );
        }

        echo esc_html( $body );
        echo '</li>';
    }

    /**
     * player id => short name, for the read-back. Pulled from the composer
     * rather than carried through state: names are not the wizard's data,
     * and a player renamed mid-flow should read correctly.
     *
     * @param array<string,mixed> $state
     * @return array<int,string>
     */
    private static function namesFor( array $state ): array {
        $out = [];
        foreach ( PlayersStep::players( $state ) as $player ) {
            $out[ (int) $player['player_id'] ] = (string) $player['name'];
        }
        return $out;
    }
}
