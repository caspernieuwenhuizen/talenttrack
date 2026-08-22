<?php
namespace TT\Modules\Exercises\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Exercises\ExerciseScenesRepository;

/**
 * SceneRenderer (#2501, epic #2493) — one way to put a scene on a page.
 *
 * The wave's acceptance criterion is that a scene "renders identically in
 * the exercise detail, the sideline view and the A4 print". The only way
 * to make that true by construction rather than by care is for all three
 * to call the same thing, so this is the only place that emits a scene
 * container.
 *
 * It emits a `.tt-training-scene` element carrying the normalised scene
 * as a JSON payload; `frontend-training-scene.js` finds it and draws.
 * The markup is deliberately inert — a page with the JS blocked shows an
 * empty frame rather than a broken half-drawing, and the print path
 * (below) does not need the JS at all.
 *
 * ## The print path is different on purpose
 *
 * A sheet of paper cannot play an animation. `renderStatic()` emits the
 * scene's FINAL frame as plain SVG, server-side, with no script. That is
 * the same thing `prefers-reduced-motion` shows on screen, so the paper
 * and the accessible screen version agree with each other rather than
 * each being a special case.
 */
final class SceneRenderer {

    /**
     * The interactive scene. Enqueues its own assets.
     *
     * @param object $scene a row from tt_exercise_scenes
     */
    public static function render( object $scene ): void {
        self::enqueue();

        $decoded = ( new ExerciseScenesRepository() )->decode( $scene );

        echo '<figure class="tt-training-scene"'
            . ' data-i18n-play="' . esc_attr__( 'Play', 'talenttrack' ) . '"'
            . ' data-i18n-pause="' . esc_attr__( 'Pause', 'talenttrack' ) . '"'
            . ' data-i18n-restart="' . esc_attr__( 'Restart', 'talenttrack' ) . '"'
            . ' aria-label="' . esc_attr( self::describe( $scene, $decoded ) ) . '">';

        // The payload the renderer reads. wp_json_encode escapes for a
        // script context; the type keeps the browser from executing it.
        echo '<script type="application/json">'
            . wp_json_encode( $decoded )
            . '</script>';

        echo '</figure>';

        if ( ! empty( $scene->name ) ) {
            echo '<p class="tt-scene__caption tt-small tt-muted">' . esc_html( (string) $scene->name ) . '</p>';
        }
    }

    /**
     * The final frame, as static SVG, with no JavaScript.
     *
     * Used by the A4 print — and available to any surface that wants a
     * scene to be a picture rather than a player.
     */
    public static function renderStatic( object $scene ): void {
        echo self::staticMarkup( $scene ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — built below with esc_attr()/esc_html() on every dynamic value.
    }

    /**
     * The same static frame, returned rather than echoed.
     *
     * The print sheet composes one long string and hands it to the
     * router, so it needs the markup as a value. Both entry points build
     * it here, which is the whole point of this class.
     */
    public static function staticMarkup( object $scene ): string {
        ob_start();
        self::echoStatic( $scene );

        return (string) ob_get_clean();
    }

    private static function echoStatic( object $scene ): void {
        $decoded = ( new ExerciseScenesRepository() )->decode( $scene );
        $ms      = (int) $decoded['duration_ms'];

        echo '<figure class="tt-training-scene tt-training-scene--static">';
        echo '<svg class="tt-tsc-svg" viewBox="0 0 100 140" preserveAspectRatio="xMidYMid meet" role="img"'
            . ' aria-label="' . esc_attr( self::describe( $scene, $decoded ) ) . '">';

        self::pitch( (string) $decoded['pitch'] );

        // Links first, so tokens sit above their own arrows — the same
        // order the JS renderer builds in.
        $positions = [];
        foreach ( $decoded['actors'] as $actor ) {
            $positions[ $actor['id'] ] = self::positionAt( $actor['keyframes'], $ms );
        }

        foreach ( $decoded['links'] as $link ) {
            $from = $positions[ $link['from'] ] ?? null;
            $to   = $positions[ $link['to'] ] ?? null;
            if ( $from === null || $to === null ) continue;

            printf(
                '<line class="tt-tsc-arrow tt-tsc-arrow--%1$s" x1="%2$s" y1="%3$s" x2="%4$s" y2="%5$s" />',
                esc_attr( (string) $link['kind'] ),
                esc_attr( (string) self::mapX( $from['x'] ) ),
                esc_attr( (string) self::mapY( $from['y'] ) ),
                esc_attr( (string) self::mapX( $to['x'] ) ),
                esc_attr( (string) self::mapY( $to['y'] ) )
            );
        }

        foreach ( $decoded['actors'] as $actor ) {
            $p = $positions[ $actor['id'] ] ?? null;
            if ( $p === null ) continue;
            self::token( $actor, $p );
        }

        echo '</svg>';
        echo '</figure>';
    }

    private static function enqueue(): void {
        // The shared Speelwijze sheet carries the pitch, the tokens, the
        // ball, most arrow kinds and the whole control bar. Ours adds
        // only what the authored contract introduces.
        wp_enqueue_style(
            'tt-frontend-methodology-scene',
            TT_PLUGIN_URL . 'assets/css/frontend-methodology-scene.css',
            [],
            TT_VERSION
        );
        wp_enqueue_style(
            'tt-frontend-training-scene',
            TT_PLUGIN_URL . 'assets/css/frontend-training-scene.css',
            [ 'tt-frontend-methodology-scene' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-frontend-training-scene',
            TT_PLUGIN_URL . 'assets/js/frontend-training-scene.js',
            [],
            TT_VERSION,
            true
        );
    }

    /**
     * A sentence a screen reader can use.
     *
     * An SVG of coloured circles is nothing without one, and "diagram"
     * is not a description. Naming the count and the movement at least
     * says what kind of thing is being shown.
     *
     * @param array<string,mixed> $decoded
     */
    private static function describe( object $scene, array $decoded ): string {
        $name = trim( (string) ( $scene->name ?? '' ) );

        $moving = 0;
        foreach ( $decoded['actors'] as $actor ) {
            if ( count( $actor['keyframes'] ) > 1 ) $moving++;
        }

        $body = sprintf(
            /* translators: 1: number of players and objects, 2: how many of them move. */
            _n(
                'Animated drill diagram: %1$d marker, %2$d of which moves.',
                'Animated drill diagram: %1$d markers, %2$d of which move.',
                count( $decoded['actors'] ),
                'talenttrack'
            ),
            count( $decoded['actors'] ),
            $moving
        );

        return $name !== '' ? $name . ' — ' . $body : $body;
    }

    /** @param list<array{t:int,x:float,y:float}> $keyframes */
    private static function positionAt( array $keyframes, int $ms ): ?array {
        if ( $keyframes === [] ) return null;

        $last = $keyframes[ count( $keyframes ) - 1 ];

        return [ 'x' => (float) $last['x'], 'y' => (float) $last['y'] ];
    }

    private static function mapX( float $x ): float {
        return round( max( 0.0, min( 100.0, $x ) ), 2 );
    }

    private static function mapY( float $y ): float {
        // Same playable band the JS renderer maps into, so the static
        // frame and the animated one put a marker in the same place.
        return round( 6 + ( max( 0.0, min( 100.0, $y ) ) / 100 ) * ( 134 - 6 ), 2 );
    }

    private static function pitch( string $preset ): void {
        echo '<rect x="2" y="2" width="96" height="136" rx="2" class="tt-tsc-pitch" />';

        if ( $preset === 'blank' ) return;

        if ( $preset === 'half' ) {
            echo '<rect x="22" y="2" width="56" height="30" class="tt-tsc-line" fill="none" />';
            echo '<rect x="36" y="2" width="28" height="10" class="tt-tsc-line" fill="none" />';
            echo '<circle cx="50" cy="44" r="9" class="tt-tsc-line" fill="none" />';
            echo '<line x1="2" y1="136" x2="98" y2="136" class="tt-tsc-line" />';
            return;
        }

        if ( $preset === 'third' ) {
            echo '<line x1="2" y1="48" x2="98" y2="48" class="tt-tsc-line" />';
            echo '<line x1="2" y1="92" x2="98" y2="92" class="tt-tsc-line" />';
            return;
        }

        echo '<line x1="2" y1="70" x2="98" y2="70" class="tt-tsc-line" />';
        echo '<circle cx="50" cy="70" r="9" class="tt-tsc-line" fill="none" />';
        echo '<circle cx="50" cy="70" r="0.6" class="tt-tsc-line" fill="currentColor" />';
        echo '<rect x="22" y="2" width="56" height="18" class="tt-tsc-line" fill="none" />';
        echo '<rect x="36" y="2" width="28" height="6" class="tt-tsc-line" fill="none" />';
        echo '<rect x="22" y="120" width="56" height="18" class="tt-tsc-line" fill="none" />';
        echo '<rect x="36" y="132" width="28" height="6" class="tt-tsc-line" fill="none" />';
    }

    /**
     * @param array<string,mixed>          $actor
     * @param array{x:float,y:float}       $p
     */
    private static function token( array $actor, array $p ): void {
        $kind = (string) $actor['kind'];
        $side = ( (string) ( $actor['side'] ?? 'own' ) ) === 'opp' ? 'opp' : 'own';

        printf(
            '<g class="tt-tsc-token tt-scene-actor tt-scene-actor--%1$s tt-tsc-token--%2$s" transform="translate(%3$s %4$s)">',
            esc_attr( $kind ),
            esc_attr( $side ),
            esc_attr( (string) self::mapX( $p['x'] ) ),
            esc_attr( (string) self::mapY( $p['y'] ) )
        );

        if ( $kind === 'ball' ) {
            echo '<circle r="1.8" class="tt-tsc-ball" />';
        } elseif ( $kind === 'cone' ) {
            echo '<polygon points="0,-2.4 2,2 -2,2" class="tt-scene-cone" />';
        } elseif ( $kind === 'goal' ) {
            echo '<rect x="-5" y="-1.2" width="10" height="2.4" class="tt-scene-goal" />';
        } else {
            echo '<circle r="3.2" />';
            $label = (string) ( $actor['label'] ?? '' );
            if ( $label !== '' ) {
                echo '<text text-anchor="middle" dominant-baseline="central" font-size="3.4">'
                    . esc_html( $label )
                    . '</text>';
            }
        }

        echo '</g>';
    }
}
