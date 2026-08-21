<?php
namespace TT\Modules\Training\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Training\Repositories\TrainingObservationsRepository;
use TT\Modules\Training\Services\PlayerExposureReader;

/**
 * PlayerTrainingTab (#2500, epic #2493) — what this player has actually
 * been taught.
 *
 * The tab the whole epic points at. Everything before it exists so this
 * screen can answer one question honestly: *has this player's training
 * matched what they are supposed to be working on?*
 *
 * ## Zero is the finding
 *
 * A principle with no minutes against it is not an empty row to omit —
 * it is the most useful row on the page. A coach scanning for what a
 * player has never been taught learns nothing from a list that silently
 * drops the gaps. So every principle the club's methodology defines is
 * rendered, and the untrained ones are marked rather than hidden.
 *
 * That is also why this tab is not a chart. A sparkline of minutes looks
 * like insight and answers nothing; a named list of principles with a
 * number beside each, sorted so the empty ones surface, is the thing a
 * coach can act on before Tuesday.
 *
 * ## This composes; it does not decide (CLAUDE.md §4)
 *
 * Every figure comes from `PlayerExposureReader`, which reads the
 * aggregate the nightly job maintains. No arithmetic happens here — if
 * this file ever needs to add two numbers together, the reader is missing
 * a method.
 */
final class PlayerTrainingTab {

    public static function render( int $player_id, int $user_id ): void {
        // The tab is gated where it is built, but a tab panel is reachable
        // by URL, so the guard is repeated here rather than assumed.
        if ( ! MatrixGate::canAnyScope( $user_id, 'training_exposure', MatrixGate::READ ) ) {
            echo '<p class="tt-notice">'
                . esc_html__( 'You do not have permission to see this player\'s training history.', 'talenttrack' )
                . '</p>';
            return;
        }

        // #1867 — a parent reads only what the child has left visible.
        // The REST route applies the same check, so the screen and the
        // API cannot disagree about what a family may see.
        if ( ! AuthorizationService::parentCanViewSection( $user_id, $player_id, 'training' ) ) {
            echo '<p class="tt-notice">'
                . esc_html__( 'This player has chosen not to share their training history.', 'talenttrack' )
                . '</p>';
            return;
        }

        self::enqueue();

        $reader = new PlayerExposureReader();
        $rows   = $reader->forPlayer( $player_id );

        self::renderSummary( $reader->summaryFor( $player_id ) );
        self::renderPrinciples( $rows );
        self::renderObservations( $player_id );
    }

    private static function enqueue(): void {
        wp_enqueue_style(
            'tt-frontend-training-exposure',
            TT_PLUGIN_URL . 'assets/css/frontend-training-exposure.css',
            [],
            TT_VERSION
        );
    }

    /**
     * @param array{minutes:int, principles_trained:int, principles_total:int, sessions:int, last_trained_on:?string} $summary
     */
    private static function renderSummary( array $summary ): void {
        echo '<div class="tt-exposure__facts">';

        self::fact(
            (string) $summary['minutes'],
            __( 'Minutes trained', 'talenttrack' ),
            $summary['sessions'] > 0
                ? sprintf(
                    /* translators: %d is a number of trainings. */
                    _n( 'across %d training', 'across %d trainings', $summary['sessions'], 'talenttrack' ),
                    $summary['sessions']
                )
                : ''
        );

        self::fact(
            sprintf( '%d / %d', $summary['principles_trained'], $summary['principles_total'] ),
            __( 'Principles touched', 'talenttrack' ),
            $summary['principles_total'] > 0
                ? sprintf(
                    /* translators: %d is how many principles have never been trained. */
                    _n( '%d never trained', '%d never trained', $summary['principles_total'] - $summary['principles_trained'], 'talenttrack' ),
                    $summary['principles_total'] - $summary['principles_trained']
                )
                : ''
        );

        self::fact(
            $summary['last_trained_on'] !== null
                ? \TT\Shared\Dates\TTDate::date( (string) $summary['last_trained_on'] )
                : '—',
            __( 'Last training', 'talenttrack' ),
            ''
        );

        echo '</div>';
    }

    private static function fact( string $value, string $label, string $sub ): void {
        echo '<div class="tt-exposure__fact">';
        echo '<span class="tt-exposure__fact-value">' . esc_html( $value ) . '</span>';
        echo '<span class="tt-exposure__fact-label">' . esc_html( $label ) . '</span>';
        if ( $sub !== '' ) {
            echo '<span class="tt-exposure__fact-sub">' . esc_html( $sub ) . '</span>';
        }
        echo '</div>';
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private static function renderPrinciples( array $rows ): void {
        echo '<h3 class="tt-exposure__title">' . esc_html__( 'Minutes per principle', 'talenttrack' ) . '</h3>';

        if ( $rows === [] ) {
            echo '<p class="tt-muted">'
                . esc_html__( 'No methodology principles are set up yet, so there is nothing to measure training against.', 'talenttrack' )
                . '</p>';
            return;
        }

        echo '<p class="tt-muted tt-small">'
            . esc_html__( 'Every principle in the club\'s methodology, including the ones this player has never trained — those are the ones worth reading first.', 'talenttrack' )
            . '</p>';

        $peak = 0;
        foreach ( $rows as $row ) $peak = max( $peak, (int) $row['minutes_total'] );

        echo '<ul class="tt-exposure__list">';
        foreach ( $rows as $row ) {
            $minutes = (int) $row['minutes_total'];
            $never   = $minutes <= 0;

            echo '<li class="tt-exposure__row' . ( $never ? ' is-never' : '' ) . '">';

            echo '<div class="tt-exposure__row-head">';
            echo '<span class="tt-exposure__code">' . esc_html( (string) $row['code'] ) . '</span>';
            echo '<span class="tt-exposure__name">' . esc_html( (string) $row['title'] ) . '</span>';
            echo '</div>';

            // The bar is the one genuinely computed style here: its width
            // is this principle's share of the player's most-trained one.
            $share = $peak > 0 ? (int) round( ( $minutes / $peak ) * 100 ) : 0;
            printf(
                '<div class="tt-exposure__bar"><i style="width:%d%%"></i></div>', /* tt-inline-ok */
                $share
            );

            echo '<div class="tt-exposure__row-meta tt-small">';
            if ( $never ) {
                echo '<strong>' . esc_html__( 'Never trained', 'talenttrack' ) . '</strong>';
            } else {
                echo esc_html( sprintf(
                    /* translators: 1: minutes, 2: number of trainings. */
                    _n( '%1$d min across %2$d training', '%1$d min across %2$d trainings', (int) $row['sessions_count'], 'talenttrack' ),
                    $minutes,
                    (int) $row['sessions_count']
                ) );

                if ( ! empty( $row['last_trained_on'] ) ) {
                    echo ' · ' . esc_html( sprintf(
                        /* translators: %s is a date. */
                        __( 'last %s', 'talenttrack' ),
                        \TT\Shared\Dates\TTDate::date( (string) $row['last_trained_on'] )
                    ) );
                }
            }
            echo '</div>';

            echo '</li>';
        }
        echo '</ul>';
    }

    private static function renderObservations( int $player_id ): void {
        $observations = ( new TrainingObservationsRepository() )->listForPlayer( $player_id, 10 );

        echo '<h3 class="tt-exposure__title">' . esc_html__( 'Recent observations', 'talenttrack' ) . '</h3>';

        if ( $observations === [] ) {
            echo '<p class="tt-muted">'
                . esc_html__( 'Nothing noted about this player during a training yet.', 'talenttrack' )
                . '</p>';
            return;
        }

        echo '<ul class="tt-exposure__observations">';
        foreach ( $observations as $observation ) {
            echo '<li class="tt-exposure__observation">';

            echo '<div class="tt-exposure__observation-head">';
            if ( ! empty( $observation->run_date ) ) {
                echo '<span class="tt-exposure__observation-date">'
                    . esc_html( \TT\Shared\Dates\TTDate::date( (string) $observation->run_date ) )
                    . '</span>';
            }
            if ( $observation->rating !== null ) {
                echo '<span class="tt-exposure__observation-rating">'
                    . esc_html( rtrim( rtrim( (string) $observation->rating, '0' ), '.' ) )
                    . '</span>';
            }
            echo '</div>';

            if ( ! empty( $observation->note ) ) {
                echo '<p class="tt-exposure__observation-note">' . esc_html( (string) $observation->note ) . '</p>';
            }

            echo '</li>';
        }
        echo '</ul>';
    }
}
