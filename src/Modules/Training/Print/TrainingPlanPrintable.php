<?php
namespace TT\Modules\Training\Print;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Exercises\ExerciseScenesRepository;
use TT\Modules\Exercises\Frontend\SceneRenderer;
use TT\Modules\Training\Repositories\TrainingPlanBlocksRepository;
use TT\Modules\Training\Repositories\TrainingPlansRepository;

/**
 * TrainingPlanPrintable (#2499) — the clipboard sheet.
 *
 * The paper version of a plan, for the coach who would rather carry a
 * sheet than a phone, and for the assistant who is running half the
 * session. It has to fit a 75-minute plan on one A4 page, which is the
 * constraint that shapes every decision below: block names and coaching
 * points earn their space, everything else is trimmed.
 *
 * This class composes the document (CLAUDE.md §4 — the router only wraps
 * it). Styles come from `assets/css/frontend-training-print.css`, read
 * from disk rather than written here, so the print sheet's CSS lives
 * where every other stylesheet does.
 */
final class TrainingPlanPrintable {

    /**
     * @return array{title:string, filename:string, style:string, body:string, empty:bool}
     */
    public static function render( int $plan_id ): array {
        $plan = ( new TrainingPlansRepository() )->findById( $plan_id );
        if ( ! $plan ) {
            return [
                'title'    => __( 'Training plan', 'talenttrack' ),
                'filename' => __( 'Training plan', 'talenttrack' ),
                'style'    => self::style(),
                'body'     => '<p>' . esc_html__( 'That training plan no longer exists.', 'talenttrack' ) . '</p>',
                'empty'    => true,
            ];
        }

        $blocks = ( new TrainingPlanBlocksRepository() )->listForPlan( $plan_id );
        $title  = (string) $plan->title;

        return [
            'title'    => $title,
            'filename' => $title,
            'style'    => self::style(),
            'body'     => self::body( $plan, $blocks ),
            'empty'    => $blocks === [],
        ];
    }

    /**
     * @param list<object> $blocks
     */
    private static function body( object $plan, array $blocks ): string {
        $total = 0;
        foreach ( $blocks as $block ) $total += (int) $block->duration_minutes;

        $out  = '<div class="tt-tp">';
        $out .= '<header class="tt-tp__head">';
        $out .= '<h1>' . esc_html( (string) $plan->title ) . '</h1>';
        $out .= '<p class="tt-tp__meta">' . esc_html( sprintf(
            /* translators: 1: number of blocks, 2: total minutes. */
            __( '%1$d blocks · %2$d minutes', 'talenttrack' ),
            count( $blocks ),
            $total
        ) ) . '</p>';
        $out .= '</header>';

        if ( $blocks === [] ) {
            $out .= '<p>' . esc_html__( 'This plan has no blocks yet.', 'talenttrack' ) . '</p>';
            return $out . '</div>';
        }

        // A running clock down the left edge. On paper there is no timer,
        // so the sheet has to answer "when does this block start" itself
        // — otherwise the coach is doing arithmetic on the touchline.
        $elapsed = 0;

        // #2501 — one query for every block's diagram, rather than one
        // per row of a sheet that is already three joins deep.
        $scenes = ( new ExerciseScenesRepository() )->primaryForExercises(
            array_map( static fn( object $b ): int => (int) ( $b->exercise_id ?? 0 ), $blocks )
        );

        $out .= '<table class="tt-tp__blocks"><tbody>';
        foreach ( $blocks as $index => $block ) {
            $minutes = (int) $block->duration_minutes;
            $name    = $block->title_override !== null && $block->title_override !== ''
                ? (string) $block->title_override
                : (string) ( $block->exercise_name ?? __( 'Untitled block', 'talenttrack' ) );

            $out .= '<tr class="tt-tp__row tt-tp__row--' . esc_attr( self::blockType( (string) $block->block_type ) ) . '">';

            $out .= '<td class="tt-tp__when">';
            $out .= '<span class="tt-tp__clock">' . esc_html( self::clock( $elapsed ) ) . '</span>';
            $out .= '<span class="tt-tp__dur">' . esc_html( sprintf(
                /* translators: %d is a number of minutes. */
                __( '%d min', 'talenttrack' ),
                $minutes
            ) ) . '</span>';
            $out .= '</td>';

            $out .= '<td class="tt-tp__what">';
            $out .= '<p class="tt-tp__type">' . esc_html( sprintf(
                '%d · %s',
                (int) $index + 1,
                self::blockTypeLabel( (string) $block->block_type )
            ) ) . '</p>';
            $out .= '<p class="tt-tp__name">' . esc_html( $name ) . '</p>';

            if ( ! empty( $block->organisation ) ) {
                $out .= '<p class="tt-tp__org">' . esc_html( (string) $block->organisation ) . '</p>';
            }
            if ( ! empty( $block->coaching_points ) ) {
                $out .= '<p class="tt-tp__points">' . esc_html( (string) $block->coaching_points ) . '</p>';
            }

            // The drill's diagram, as its final frame. Paper cannot play
            // an animation, and the finished picture is what a coach
            // glances at between blocks anyway.
            $scene = $scenes[ (int) ( $block->exercise_id ?? 0 ) ] ?? null;
            if ( $scene !== null ) {
                $out .= '<div class="tt-tp__scene">' . SceneRenderer::staticMarkup( $scene ) . '</div>';
            }

            $out .= '</td>';
            $out .= '</tr>';

            $elapsed += $minutes;
        }
        $out .= '</tbody></table>';

        $out .= self::phvWarning( $plan, $blocks );

        $out .= '<p class="tt-tp__foot">' . esc_html__( 'What you actually run is recorded on the training itself, not on this sheet.', 'talenttrack' ) . '</p>';

        return $out . '</div>';
    }

    /**
     * The PHV warning repeats on paper, because the person holding the
     * paper is the person who has to act on it.
     *
     * A growth-spurt flag carries an intensity ceiling for a named
     * player; a block above it needs that player given an adapted role.
     * Leaving this on the screen version only would mean the coach who
     * printed the sheet is the one who never sees it.
     *
     * @param list<object> $blocks
     */
    private static function phvWarning( object $plan, array $blocks ): string {
        $team_id = (int) ( $plan->team_id ?? 0 );
        if ( $team_id <= 0 ) return '';

        // #2739 — fall back to the exercise's band. Only the generator
        // ever wrote the block's own column, so every plan built by the
        // builder, the REST bulk replace or the photo flow read as peak
        // zero and lost this warning entirely. `listForPlan()` has always
        // selected the exercise's band; it was fetched and not read.
        $peak  = 0;
        $known = false;
        foreach ( $blocks as $block ) {
            $band = $block->intensity_band ?? $block->exercise_intensity_band ?? null;
            if ( $band === null ) continue;

            $known = true;
            $peak  = max( $peak, (int) $band );
        }

        // Nothing anywhere records how hard this plan is, so the check
        // cannot run. Saying so beats printing nothing: on a sheet whose
        // job is naming children who need an adapted role, an empty space
        // reads as an all-clear, and a coach has no way to tell the
        // difference from outside.
        if ( ! $known ) return self::phvUnknown();

        if ( $peak <= 0 ) return '';

        global $wpdb;

        // Only *active* flags: a cleared flag is a player who has come
        // through their growth spurt, and repeating it on paper would
        // train coaches to ignore the warning that still matters.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.first_name, p.last_name, f.intensity_ceiling
               FROM {$wpdb->prefix}tt_player_phv_flags f
               JOIN {$wpdb->prefix}tt_players p ON p.id = f.player_id
              WHERE p.team_id = %d
                AND p.archived_at IS NULL
                AND f.is_active = 1
                AND f.intensity_ceiling IS NOT NULL
                AND f.intensity_ceiling < %d
              ORDER BY p.last_name ASC",
            $team_id,
            $peak
        ) );

        if ( ! $rows ) return '';

        $out = '<div class="tt-tp__warn"><h2>' . esc_html__( 'Growth-spurt ceilings', 'talenttrack' ) . '</h2><ul>';
        foreach ( (array) $rows as $row ) {
            $out .= '<li>' . esc_html( sprintf(
                /* translators: 1: player name, 2: their intensity ceiling, 3: the hardest block in this plan. */
                __( '%1$s — ceiling %2$d, and this plan reaches %3$d. Give them an adapted role in the hard blocks.', 'talenttrack' ),
                trim( (string) $row->first_name . ' ' . (string) $row->last_name ),
                (int) $row->intensity_ceiling,
                $peak
            ) ) . '</li>';
        }

        return $out . '</ul></div>';
    }

    /**
     * The check could not run (#2739).
     *
     * Deliberately not styled as a warning — nothing is known to be
     * wrong. It is a statement that a check the reader might assume
     * happened did not, which is the one thing an empty space cannot
     * say.
     */
    private static function phvUnknown(): string {
        return '<div class="tt-tp__warn tt-tp__warn--unknown"><h2>'
            . esc_html__( 'Growth-spurt ceilings', 'talenttrack' )
            . '</h2><p>'
            . esc_html__( 'None of these blocks has an intensity recorded, so players with a growth-spurt ceiling could not be checked against this plan. Set an intensity on the exercises to get this warning.', 'talenttrack' )
            . '</p></div>';
    }

    private static function clock( int $minutes ): string {
        return sprintf( '%d:%02d', intdiv( $minutes, 60 ), $minutes % 60 );
    }

    private static function blockType( string $type ): string {
        $known = [ 'warmup', 'rondo', 'main', 'game', 'finishing', 'cooldown', 'talk' ];
        return in_array( $type, $known, true ) ? $type : 'main';
    }

    private static function blockTypeLabel( string $type ): string {
        switch ( self::blockType( $type ) ) {
            case 'warmup':    return __( 'Warm-up', 'talenttrack' );
            case 'rondo':     return __( 'Rondo', 'talenttrack' );
            case 'game':      return __( 'Game', 'talenttrack' );
            case 'finishing': return __( 'Finishing', 'talenttrack' );
            case 'cooldown':  return __( 'Cool-down', 'talenttrack' );
            case 'talk':      return __( 'Team talk', 'talenttrack' );
        }
        return __( 'Main', 'talenttrack' );
    }

    /**
     * The sheet's CSS, read from `assets/css/` rather than written here.
     *
     * A standalone print document has no `wp_head`, so the stylesheet
     * cannot be enqueued — but it can still live in the same place as
     * every other stylesheet instead of being a PHP heredoc.
     */
    private static function style(): string {
        // The two scene sheets come along because the sheet now carries
        // diagrams. Concatenated rather than copied: a scene on paper is
        // meant to look like the same scene on screen, and that is only
        // true for as long as both are reading the same rules.
        $css = '';
        foreach ( [
            'assets/css/frontend-methodology-scene.css',
            'assets/css/frontend-training-scene.css',
            'assets/css/frontend-training-print.css',
        ] as $relative ) {
            $path = TT_PLUGIN_DIR . $relative;
            if ( is_readable( $path ) ) $css .= (string) file_get_contents( $path ) . "\n";
        }

        return $css;
    }
}
