<?php
namespace TT\Modules\MatchAnalysis\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\MatchAnalysis\MatchAnalysisEnums;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisComposer;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * PlayersStep — the roster, with an optional marker and one specific line
 * per player.
 *
 * Everyone who played is listed, and the resting state of every row is
 * "not mentioned". A two-list "who stood out / who struggled" picker would
 * be quicker to fill in and would systematically overlook the quiet
 * players — which is the bias a talent system exists to counteract, not to
 * automate.
 */
final class PlayersStep implements WizardStepInterface {

    public function slug(): string  { return 'players'; }
    public function label(): string { return __( 'Players', 'talenttrack' ); }

    public function render( array $state ): void {
        $players = self::players( $state );

        if ( empty( $players ) ) {
            echo '<p class="tt-notice">'
                . esc_html__( 'Nobody is recorded as having played this match, so there is no roster to mark up. Record attendance or minutes first.', 'talenttrack' )
                . '</p>';
            return;
        }

        $saved   = isset( $state['players'] ) && is_array( $state['players'] ) ? $state['players'] : [];
        $markers = MatchAnalysisEnums::markers();
        $tags    = MatchAnalysisEnums::playerItemTags();

        echo '<p class="tt-ma__hint">'
            . esc_html__( 'Leave a player untouched to say nothing about them — most rows usually stay empty.', 'talenttrack' )
            . '</p>';

        echo '<ul class="tt-ma__players">';
        foreach ( $players as $player ) {
            $pid  = (int) $player['player_id'];
            $item = isset( $saved[ $pid ] ) && is_array( $saved[ $pid ] ) ? $saved[ $pid ] : [
                'marker'        => (string) $player['marker'],
                'note'          => (string) $player['note'],
                'team_function' => $player['team_function'],
            ];

            echo '<li class="tt-ma__player">';
            echo '<div class="tt-ma__player-head">';
            echo '<span class="tt-ma__player-name">' . esc_html( (string) $player['name'] ) . '</span>';
            if ( $player['minutes'] !== null ) {
                printf(
                    '<span class="tt-ma__player-min">%s</span>',
                    esc_html( sprintf(
                        /* translators: %d: minutes played */
                        __( "%d'", 'talenttrack' ),
                        (int) $player['minutes']
                    ) )
                );
            }
            echo '</div>';

            if ( (string) $player['prep_focus'] !== '' ) {
                echo '<p class="tt-ma__player-plan">'
                    . esc_html( sprintf(
                        /* translators: %s: the attention note written on the match plan */
                        __( 'Asked to: %s', 'talenttrack' ),
                        (string) $player['prep_focus']
                    ) )
                    . '</p>';
            }

            printf(
                '<div class="tt-ma__markers" role="radiogroup" aria-label="%s">',
                esc_attr( sprintf(
                    /* translators: %s: player name */
                    __( 'How did %s do?', 'talenttrack' ),
                    (string) $player['name']
                ) )
            );

            $options = [ '' => __( 'Not mentioned', 'talenttrack' ) ] + $markers;
            foreach ( $options as $value => $label ) {
                $id = 'tt-ma-wz-p' . $pid . '-' . ( $value === '' ? 'none' : sanitize_key( (string) $value ) );
                printf(
                    '<input type="radio" class="tt-ma__marker-input" id="%1$s" name="players[%2$d][marker]" value="%3$s"%4$s />'
                    . '<label class="tt-ma__marker" for="%1$s" data-marker="%3$s">%5$s</label>',
                    esc_attr( $id ),
                    $pid,
                    esc_attr( (string) $value ),
                    checked( (string) ( $item['marker'] ?? '' ), (string) $value, false ),
                    esc_html( (string) $label )
                );
            }
            echo '</div>';

            printf(
                '<input type="text" class="tt-input tt-ma__player-note" name="players[%1$d][note]" value="%2$s" maxlength="240" placeholder="%3$s" aria-label="%4$s" />',
                $pid,
                esc_attr( (string) ( $item['note'] ?? '' ) ),
                esc_attr__( 'What exactly did they do?', 'talenttrack' ),
                esc_attr( sprintf(
                    /* translators: %s: player name */
                    __( 'Note about %s', 'talenttrack' ),
                    (string) $player['name']
                ) )
            );

            printf(
                '<select class="tt-input tt-ma__player-tag" name="players[%1$d][team_function]" aria-label="%2$s">',
                $pid,
                esc_attr( sprintf(
                    /* translators: %s: player name */
                    __( 'Which part of the game — %s', 'talenttrack' ),
                    (string) $player['name']
                ) )
            );
            $current_tag = (string) ( $item['team_function'] ?? '' );
            printf(
                '<option value=""%s>%s</option>',
                selected( $current_tag, '', false ),
                esc_html__( 'No particular phase', 'talenttrack' )
            );
            foreach ( $tags as $tag_key => $tag_label ) {
                printf(
                    '<option value="%s"%s>%s</option>',
                    esc_attr( (string) $tag_key ),
                    selected( $current_tag, (string) $tag_key, false ),
                    esc_html( (string) $tag_label )
                );
            }
            echo '</select>';
            echo '</li>';
        }
        echo '</ul>';
    }

    public function validate( array $post, array $state ) {
        $posted = isset( $post['players'] ) && is_array( $post['players'] ) ? $post['players'] : [];
        $out    = [];

        foreach ( $posted as $pid => $item ) {
            $player_id = (int) $pid;
            if ( $player_id <= 0 || ! is_array( $item ) ) continue;

            $marker = sanitize_key( (string) ( $item['marker'] ?? '' ) );
            $note   = sanitize_text_field( (string) ( $item['note'] ?? '' ) );
            $tag    = sanitize_key( (string) ( $item['team_function'] ?? '' ) );

            // Only carry the rows that say something. The rest are the
            // roster's resting state and never become records.
            if ( $marker === '' && trim( $note ) === '' ) continue;

            $out[ $player_id ] = [
                'marker'        => MatchAnalysisEnums::isMarker( $marker ) ? $marker : '',
                'note'          => $note,
                'team_function' => MatchAnalysisEnums::isPlayerItemTag( $tag ) ? $tag : null,
            ];
        }

        return [ 'players' => $out ];
    }

    public function nextStep( array $state ): ?string {
        return 'review';
    }

    public function submit( array $state ) {
        return null;
    }

    /**
     * @param array<string,mixed> $state
     * @return list<array<string,mixed>>
     */
    public static function players( array $state ): array {
        $payload = ( new MatchAnalysisComposer() )->forActivity( OverallStep::activityId( $state ), false );

        return $payload === null ? [] : (array) $payload['players'];
    }
}
