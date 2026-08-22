<?php
namespace TT\Modules\Exercises\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Exercises\ExerciseScenesRepository;

/**
 * SceneEditorRenderer (#2501, epic #2493) — the canvas at
 * `?tt_view=exercises&id=N&mode=scene`.
 *
 * ## What PHP renders and what it does not
 *
 * PHP renders the frame: the heading, the small-screen notice, the
 * no-JavaScript message, and every word the editor will ever say. The
 * canvas itself is built by `frontend-training-scene-editor.js`, because
 * every part of it — token positions, the timeline, the inspector —
 * changes on every pointer move, and server-rendering any of it would
 * mean a page load per drag.
 *
 * The words are the important half of that split. Nothing user-facing
 * lives in the JS file (CLAUDE.md §4): the strings are translated here
 * and handed over, so a Dutch coach gets a Dutch editor without a second
 * translation mechanism existing for JavaScript.
 *
 * ## Desktop-primary, stated rather than enforced
 *
 * The wave calls this surface desktop-primary. The notice says so. What
 * it does NOT do is refuse to load below 1024px — a coach who opens the
 * scene on a phone can still watch it, select a marker and move one,
 * which is most of what they would want there. Blocking the surface
 * would be a bigger promise than the layout needs to make.
 */
final class SceneEditorRenderer {

    public static function render( object $exercise, ?object $scene, string $cancel_url ): void {
        self::enqueue( $exercise, $scene, $cancel_url );

        echo '<p class="tt-notice tt-notice--info tt-sced__note">'
            . esc_html__( 'Drawing works best on a tablet or a desktop. On a phone you can watch the scene and move a marker, but the timeline needs more room than a phone has.', 'talenttrack' )
            . '</p>';

        echo '<div class="tt-sced-root" data-tt-scene-editor>';
        // Replaced by the editor on boot. Left as real text rather than
        // an empty div so a browser with JavaScript off says why the
        // page is empty instead of just being empty.
        echo '<p class="tt-notice">'
            . esc_html__( 'The scene editor needs JavaScript. The scene itself still shows on the exercise page without it.', 'talenttrack' )
            . '</p>';
        echo '</div>';
    }

    private static function enqueue( object $exercise, ?object $scene, string $cancel_url ): void {
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
        wp_enqueue_style(
            'tt-frontend-training-scene-editor',
            TT_PLUGIN_URL . 'assets/css/frontend-training-scene-editor.css',
            [ 'tt-frontend-training-scene' ],
            TT_VERSION
        );

        wp_enqueue_script(
            'tt-frontend-training-scene',
            TT_PLUGIN_URL . 'assets/js/frontend-training-scene.js',
            [],
            TT_VERSION,
            true
        );
        // Depends on the renderer, not merely loaded after it: the editor
        // draws through `TTTrainingScene.mount()` and has nothing to draw
        // with until that file has run.
        wp_enqueue_script(
            'tt-frontend-training-scene-editor',
            TT_PLUGIN_URL . 'assets/js/frontend-training-scene-editor.js',
            [ 'tt-frontend-training-scene' ],
            TT_VERSION,
            true
        );

        $decoded = $scene !== null
            ? ( new ExerciseScenesRepository() )->decode( $scene )
            : [ 'pitch' => 'full', 'duration_ms' => 6000, 'actors' => [], 'links' => [] ];

        // `wp_add_inline_script`, NOT `wp_localize_script`. The latter
        // casts every scalar in the array to a string on the way out,
        // and a scene is numbers: an actor at x "30" makes the
        // interpolator concatenate instead of add, so a marker halfway
        // between two keyframes lands at "305" and vanishes off the
        // pitch. Everything else on this surface would look fine, which
        // is what makes it worth naming here.
        $config = [
            'exerciseId'      => (int) $exercise->id,
            'sceneId'         => $scene !== null ? (int) $scene->id : 0,
            'name'            => $scene !== null ? (string) ( $scene->name ?? '' ) : '',
            'scene'           => $decoded,
            'restBase'        => esc_url_raw( rest_url( 'talenttrack/v1' ) ),
            'nonce'           => wp_create_nonce( 'wp_rest' ),
            'cancelUrl'       => esc_url_raw( $cancel_url ),
            'decimalPoint'    => self::decimalPoint(),
            'actorTools'      => self::actorTools(),
            'linkKinds'       => self::linkKinds(),
            'pitchOptions'    => self::pitchOptions(),
            'durationOptions' => self::durationOptions(),
            'i18n'            => self::strings(),
        ];

        wp_add_inline_script(
            'tt-frontend-training-scene-editor',
            'var TT_SCENE_EDITOR = ' . wp_json_encode( $config ) . ';',
            'before'
        );
    }

    /** Dutch writes 1,5 s. Reading it from the locale beats hardcoding either. */
    private static function decimalPoint(): string {
        global $wp_locale;

        $point = $wp_locale->number_format['decimal_point'] ?? '.';

        return $point === '' ? '.' : (string) $point;
    }

    /**
     * The markers a coach can place. `side` decides which shirt the
     * renderer draws, and is derived from the kind rather than being a
     * second control — nobody has ever wanted an opponent in their own
     * colours.
     *
     * @return list<array{kind:string, label:string, side:string}>
     */
    private static function actorTools(): array {
        return [
            [ 'kind' => 'player',   'label' => __( 'Player', 'talenttrack' ),   'side' => 'own' ],
            [ 'kind' => 'opponent', 'label' => __( 'Opponent', 'talenttrack' ), 'side' => 'opp' ],
            [ 'kind' => 'keeper',   'label' => __( 'Keeper', 'talenttrack' ),   'side' => 'own' ],
            [ 'kind' => 'ball',     'label' => __( 'Ball', 'talenttrack' ),     'side' => 'own' ],
            [ 'kind' => 'cone',     'label' => __( 'Cone', 'talenttrack' ),     'side' => 'own' ],
            [ 'kind' => 'goal',     'label' => __( 'Goal', 'talenttrack' ),     'side' => 'own' ],
        ];
    }

    /** @return list<array{value:string, label:string}> */
    private static function linkKinds(): array {
        return [
            [ 'value' => 'pass',    'label' => __( 'Pass', 'talenttrack' ) ],
            [ 'value' => 'dribble', 'label' => __( 'Dribble', 'talenttrack' ) ],
            [ 'value' => 'run',     'label' => __( 'Run', 'talenttrack' ) ],
            [ 'value' => 'shot',    'label' => __( 'Shot', 'talenttrack' ) ],
            [ 'value' => 'press',   'label' => __( 'Press', 'talenttrack' ) ],
        ];
    }

    /** @return list<array{value:string, label:string}> */
    private static function pitchOptions(): array {
        return [
            [ 'value' => 'full',  'label' => __( 'Full pitch', 'talenttrack' ) ],
            [ 'value' => 'half',  'label' => __( 'Half pitch', 'talenttrack' ) ],
            [ 'value' => 'third', 'label' => __( 'Grid square', 'talenttrack' ) ],
            [ 'value' => 'blank', 'label' => __( 'No markings', 'talenttrack' ) ],
        ];
    }

    /**
     * Whole seconds, in the lengths a drill phase actually lasts. A free
     * number field would invite 6473ms, which nobody means and which
     * makes two scenes impossible to compare.
     *
     * @return list<array{value:string, label:string}>
     */
    private static function durationOptions(): array {
        $out = [];
        foreach ( [ 4, 6, 8, 12, 20 ] as $seconds ) {
            $out[] = [
                'value' => (string) ( $seconds * 1000 ),
                'label' => sprintf(
                    /* translators: %d is a whole number of seconds. */
                    _n( '%d second', '%d seconds', $seconds, 'talenttrack' ),
                    $seconds
                ),
            ];
        }

        return $out;
    }

    /**
     * Every word the editor says.
     *
     * @return array<string,string>
     */
    private static function strings(): array {
        return [
            'addMarker'       => __( 'Add a marker', 'talenttrack' ),
            'sceneSettings'   => __( 'The scene', 'talenttrack' ),
            'sceneName'       => __( 'Name', 'talenttrack' ),
            'pitchView'       => __( 'Pitch', 'talenttrack' ),
            'sceneLength'     => __( 'Length', 'talenttrack' ),
            'timeline'        => __( 'Timeline', 'talenttrack' ),
            'timeInScene'     => __( 'Time in the scene', 'talenttrack' ),
            'play'            => __( 'Play', 'talenttrack' ),
            'pause'           => __( 'Pause', 'talenttrack' ),
            'undo'            => __( 'Undo', 'talenttrack' ),
            'selectedMarker'  => __( 'Selected marker', 'talenttrack' ),
            'lines'           => __( 'Lines', 'talenttrack' ),
            'markerLabel'     => __( 'Shirt number', 'talenttrack' ),
            'acrossPitch'     => __( 'Across', 'talenttrack' ),
            'alongPitch'      => __( 'Along', 'talenttrack' ),
            'addKeyframe'     => __( 'Mark position', 'talenttrack' ),
            'removeKeyframe'  => __( 'Clear position', 'talenttrack' ),
            'duplicate'       => __( 'Duplicate', 'talenttrack' ),
            'removeMarker'    => __( 'Remove', 'talenttrack' ),
            'removeLink'      => __( 'Remove', 'talenttrack' ),
            'cancel'          => __( 'Cancel', 'talenttrack' ),
            'saveScene'       => __( 'Save', 'talenttrack' ),

            'dragHint'        => __( 'Drag a marker on the pitch to record where it is at this moment. Arrow keys move it a step at a time.', 'talenttrack' ),
            'nothingSelected' => __( 'Pick a marker on the pitch to edit it.', 'talenttrack' ),
            'emptyScene'      => __( 'Nothing on the pitch yet. Choose a marker on the left, then tap the pitch to place it.', 'talenttrack' ),
            'noLinks'         => __( 'No lines yet. Pick a line type on the left, then tap two markers.', 'talenttrack' ),
            'selectFirst'     => __( 'Pick a marker first.', 'talenttrack' ),
            'lastKeyframe'    => __( 'A marker needs at least one position, so this one cannot be cleared.', 'talenttrack' ),
            'noKeyframeHere'  => __( 'This marker has no position recorded at this moment.', 'talenttrack' ),

            /* translators: %s is the name or shirt number of a marker. */
            'actorLabel'      => __( 'Marker %s', 'talenttrack' ),
            /* translators: %s is the name or shirt number of a marker. */
            'selectActor'     => __( 'Edit marker %s', 'talenttrack' ),
            /* translators: %s is the name or shirt number of a marker. */
            'added'           => __( 'Added %s.', 'talenttrack' ),
            /* translators: %s is the name or shirt number of a marker. */
            'removed'         => __( 'Removed %s.', 'talenttrack' ),
            /* translators: %s is the name or shirt number of a marker. */
            'linkFrom'        => __( 'Line starts at %s. Now tap where it ends.', 'talenttrack' ),
            /* translators: 1: line type, for example Pass. 2: the marker it starts at. 3: the marker it ends at. */
            'linkSummary'     => __( '%1$s from %2$s to %3$s', 'talenttrack' ),
            /* translators: %s is a moment in the scene, for example 2.4 s. */
            'keyframeAt'      => __( 'Position at %s', 'talenttrack' ),
            /* translators: %s is a moment in the scene, for example 2.4 s. */
            'keyframeMoved'   => __( 'Moved to %s', 'talenttrack' ),
            /* translators: %s is a number of seconds with one decimal, for example 2.4. */
            'seconds'         => __( '%s s', 'talenttrack' ),

            'linkAdded'       => __( 'Line added.', 'talenttrack' ),
            'toolArmed'       => __( 'Tap the pitch to place it.', 'talenttrack' ),
            'toolCleared'     => __( 'Back to selecting.', 'talenttrack' ),
            'undone'          => __( 'Undone.', 'talenttrack' ),
            'saving'          => __( 'Saving…', 'talenttrack' ),
            'saved'           => __( 'Scene saved.', 'talenttrack' ),
            'saveFailed'      => __( 'The scene could not be saved. Check your connection and try again.', 'talenttrack' ),
        ];
    }
}
