<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Exercises\ExerciseScenesRepository;
use TT\Modules\Exercises\Frontend\SceneEditorRenderer;
use TT\Modules\Exercises\Frontend\SceneRenderer;

/**
 * #2501 — animated scenes attached to an exercise.
 *
 * The promises, not the markup:
 *
 *   - a stored scene is always renderable, whatever the canvas sent;
 *   - saving what you were just given changes nothing, because that is
 *     the editor's own cycle and a scene that drifts on every save is
 *     unusable;
 *   - exactly one scene per exercise is the primary one, always;
 *   - the editor's coordinates arrive as NUMBERS — the failure that
 *     stringifies them leaves every page looking correct;
 *   - every string the editor's JS reads is actually sent, because one
 *     that is not is a blank button and nothing says so at runtime.
 */
final class ExerciseScenesTest extends WP_UnitTestCase {

    private const BASE = '/talenttrack/v1';

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ---- fixtures ---------------------------------------------------------

    private function author(): int {
        $id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $id );

        return $id;
    }

    private function makeExercise( string $name = 'Opbouw 7v5' ): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_exercises', [
            'club_id'    => 1,
            'uuid'       => wp_generate_uuid4(),
            'name'       => $name,
            'visibility' => 'club',
            'source'     => 'club',
        ] );

        return (int) $wpdb->insert_id;
    }

    /** @return array<string,mixed> */
    private function scene(): array {
        return [
            'actors' => [
                [ 'id' => 'play1', 'kind' => 'player', 'label' => '5', 'keyframes' => [
                    [ 't' => 0, 'x' => 30, 'y' => 70 ],
                    [ 't' => 2700, 'x' => 48, 'y' => 40 ],
                ] ],
                [ 'id' => 'play2', 'kind' => 'player', 'label' => '6', 'keyframes' => [
                    [ 't' => 0, 'x' => 55, 'y' => 55 ],
                ] ],
            ],
            'links' => [ [ 'from' => 'play1', 'to' => 'play2', 'kind' => 'pass', 't' => 2700 ] ],
        ];
    }

    private function call( string $method, string $route, array $params = [] ): array {
        $request = new WP_REST_Request( $method, $route );
        foreach ( $params as $key => $value ) {
            $request->set_param( $key, $value );
        }
        $response = rest_get_server()->dispatch( $request );

        return [ $response->get_status(), $response->get_data() ];
    }

    // ---- normalisation ----------------------------------------------------

    public function test_a_coordinate_off_the_pitch_is_pulled_back_onto_it(): void {
        $this->author();
        $repo = new ExerciseScenesRepository();

        $id = $repo->create( [
            'exercise_id' => $this->makeExercise(),
            'scene'       => [ 'actors' => [
                [ 'id' => 'play1', 'kind' => 'player', 'keyframes' => [
                    [ 't' => 0, 'x' => 140, 'y' => -20 ],
                ] ],
            ] ],
        ] );

        $decoded = $repo->decode( $repo->findById( $id ) );

        $this->assertSame( 100.0, $decoded['actors'][0]['keyframes'][0]['x'] );
        $this->assertSame( 0.0, $decoded['actors'][0]['keyframes'][0]['y'] );
    }

    public function test_keyframes_are_sorted_and_a_repeated_moment_collapses(): void {
        $this->author();
        $repo = new ExerciseScenesRepository();

        $id = $repo->create( [
            'exercise_id' => $this->makeExercise(),
            'duration_ms' => 6000,
            'scene'       => [ 'actors' => [
                [ 'id' => 'play1', 'kind' => 'player', 'keyframes' => [
                    [ 't' => 3000, 'x' => 50, 'y' => 50 ],
                    [ 't' => 0, 'x' => 10, 'y' => 10 ],
                    // Same moment twice: the interpolator would read this
                    // as a teleport, and nobody ever draws one.
                    [ 't' => 3000, 'x' => 60, 'y' => 60 ],
                ] ],
            ] ],
        ] );

        $keyframes = $repo->decode( $repo->findById( $id ) )['actors'][0]['keyframes'];

        $this->assertCount( 2, $keyframes );
        $this->assertSame( [ 0, 3000 ], array_column( $keyframes, 't' ) );
        $this->assertSame( 60.0, $keyframes[1]['x'], 'the last authored position at a moment wins' );
    }

    public function test_an_actor_with_nowhere_to_be_is_dropped_and_its_lines_with_it(): void {
        $this->author();
        $repo = new ExerciseScenesRepository();

        $id = $repo->create( [
            'exercise_id' => $this->makeExercise(),
            'scene'       => [
                'actors' => [
                    [ 'id' => 'play1', 'kind' => 'player', 'keyframes' => [ [ 't' => 0, 'x' => 20, 'y' => 20 ] ] ],
                    [ 'id' => 'ghost', 'kind' => 'player', 'keyframes' => [] ],
                ],
                'links' => [
                    [ 'from' => 'play1', 'to' => 'ghost', 'kind' => 'pass', 't' => 0 ],
                ],
            ],
        ] );

        $decoded = $repo->decode( $repo->findById( $id ) );

        $this->assertCount( 1, $decoded['actors'] );
        $this->assertSame( [], $decoded['links'], 'a pass to nobody is not drawn' );
    }

    public function test_shortening_a_scene_pulls_its_keyframes_in_with_it(): void {
        $this->author();
        $repo = new ExerciseScenesRepository();

        $id = $repo->create( [
            'exercise_id' => $this->makeExercise(),
            'duration_ms' => 12000,
            'scene'       => [ 'actors' => [
                [ 'id' => 'play1', 'kind' => 'player', 'keyframes' => [
                    [ 't' => 0, 'x' => 10, 'y' => 10 ],
                    [ 't' => 11000, 'x' => 80, 'y' => 80 ],
                ] ],
            ] ],
        ] );

        $stored = $repo->decode( $repo->findById( $id ) );
        $repo->update( $id, [ 'duration_ms' => 4000, 'scene' => $stored ] );

        $keyframes = $repo->decode( $repo->findById( $id ) )['actors'][0]['keyframes'];

        $this->assertSame( 4000, $keyframes[1]['t'], 'a keyframe past the end would never play' );
    }

    public function test_an_unknown_kind_falls_back_rather_than_reaching_the_stylesheet(): void {
        $this->author();
        $repo = new ExerciseScenesRepository();

        $id = $repo->create( [
            'exercise_id' => $this->makeExercise(),
            'scene'       => [
                'actors' => [
                    [ 'id' => 'play1', 'kind' => '"><script>', 'keyframes' => [ [ 't' => 0, 'x' => 5, 'y' => 5 ] ] ],
                    [ 'id' => 'play2', 'kind' => 'player', 'keyframes' => [ [ 't' => 0, 'x' => 6, 'y' => 6 ] ] ],
                ],
                'links' => [ [ 'from' => 'play1', 'to' => 'play2', 'kind' => 'nonsense', 't' => 0 ] ],
            ],
        ] );

        $decoded = $repo->decode( $repo->findById( $id ) );

        $this->assertSame( 'player', $decoded['actors'][0]['kind'] );
        $this->assertSame( 'pass', $decoded['links'][0]['kind'] );
    }

    // ---- the primary scene ------------------------------------------------

    public function test_the_first_scene_becomes_the_primary_one_without_being_asked(): void {
        $this->author();
        $repo        = new ExerciseScenesRepository();
        $exercise_id = $this->makeExercise();

        $id = $repo->create( [ 'exercise_id' => $exercise_id, 'scene' => $this->scene() ] );

        $this->assertSame( 1, (int) $repo->findById( $id )->is_primary );
    }

    public function test_only_ever_one_scene_is_primary(): void {
        $this->author();
        $repo        = new ExerciseScenesRepository();
        $exercise_id = $this->makeExercise();

        $first  = $repo->create( [ 'exercise_id' => $exercise_id, 'scene' => $this->scene() ] );
        $second = $repo->create( [ 'exercise_id' => $exercise_id, 'scene' => $this->scene() ] );
        $repo->setPrimary( $second );

        $flagged = array_filter(
            $repo->listForExercise( $exercise_id ),
            static fn( object $row ): bool => (int) $row->is_primary === 1
        );

        $this->assertCount( 1, $flagged );
        $this->assertSame( $second, (int) array_values( $flagged )[0]->id );
        $this->assertSame( 0, (int) $repo->findById( $first )->is_primary );
    }

    public function test_deleting_the_primary_promotes_another_rather_than_showing_none(): void {
        $this->author();
        $repo        = new ExerciseScenesRepository();
        $exercise_id = $this->makeExercise();

        $first  = $repo->create( [ 'exercise_id' => $exercise_id, 'scene' => $this->scene() ] );
        $second = $repo->create( [ 'exercise_id' => $exercise_id, 'scene' => $this->scene() ] );
        $repo->delete( $first );

        $this->assertNotNull( $repo->primaryFor( $exercise_id ) );
        $this->assertSame( $second, (int) $repo->primaryFor( $exercise_id )->id );
    }

    public function test_the_batch_lookup_returns_one_primary_per_exercise(): void {
        $this->author();
        $repo = new ExerciseScenesRepository();

        $a = $this->makeExercise( 'A' );
        $b = $this->makeExercise( 'B' );

        $repo->create( [ 'exercise_id' => $a, 'scene' => $this->scene() ] );
        $wanted = $repo->create( [ 'exercise_id' => $a, 'scene' => $this->scene() ] );
        $repo->setPrimary( $wanted );
        $repo->create( [ 'exercise_id' => $b, 'scene' => $this->scene() ] );

        $found = $repo->primaryForExercises( [ $a, $b, 999999 ] );

        $this->assertCount( 2, $found );
        $this->assertSame( $wanted, (int) $found[ $a ]->id );
    }

    // ---- REST -------------------------------------------------------------

    public function test_saving_what_the_editor_was_just_given_changes_nothing(): void {
        $this->author();
        $exercise_id = $this->makeExercise();

        [ $status, $created ] = $this->call( 'POST', self::BASE . "/exercises/{$exercise_id}/scenes", [
            'name'         => 'Opbouw',
            'pitch_preset' => 'half',
            'duration_ms'  => 6000,
            'scene'        => $this->scene(),
        ] );
        $this->assertSame( 201, $status );

        $scene_id = (int) $created['data']['scene']['id'];
        $returned = $created['data']['scene']['scene'];

        [ $status, $fetched ] = $this->call( 'GET', self::BASE . "/exercise-scenes/{$scene_id}" );
        $this->assertSame( 200, $status );
        $this->assertSame(
            wp_json_encode( $returned ),
            wp_json_encode( $fetched['data']['scene']['scene'] ),
            'what a write returns is what the next read gives back'
        );

        // The editor's own cycle: adopt the response, then save it again.
        [ $status, $again ] = $this->call( 'PUT', self::BASE . "/exercise-scenes/{$scene_id}", [
            'scene' => $fetched['data']['scene']['scene'],
        ] );

        $this->assertSame( 200, $status );
        $this->assertSame(
            wp_json_encode( $returned ),
            wp_json_encode( $again['data']['scene']['scene'] ),
            'normalisation is idempotent, so a scene does not drift on every save'
        );
    }

    /**
     * Regression: the single-scene routes once took `(?P<scene>\d+)`, and
     * the body field carrying the payload is also called `scene`. WordPress
     * merges body params over URL params, so the payload overwrote the id
     * and every PUT 404'd while the GET beside it worked.
     */
    public function test_a_scene_body_does_not_overwrite_the_scene_id_in_the_url(): void {
        $this->author();
        $exercise_id = $this->makeExercise();

        [ , $created ] = $this->call( 'POST', self::BASE . "/exercises/{$exercise_id}/scenes", [
            'scene' => $this->scene(),
        ] );
        $scene_id = (int) $created['data']['scene']['id'];

        [ $status, $updated ] = $this->call( 'PUT', self::BASE . "/exercise-scenes/{$scene_id}", [
            'name'  => 'Renamed',
            'scene' => $this->scene(),
        ] );

        $this->assertSame( 200, $status );
        $this->assertSame( $scene_id, (int) $updated['data']['scene']['id'] );
        $this->assertSame( 'Renamed', $updated['data']['scene']['name'] );
    }

    public function test_the_editor_is_told_when_the_server_moved_something(): void {
        $this->author();
        $exercise_id = $this->makeExercise();

        [ , $created ] = $this->call( 'POST', self::BASE . "/exercises/{$exercise_id}/scenes", [
            'scene' => [ 'actors' => [
                [ 'id' => 'play1', 'kind' => 'player', 'keyframes' => [ [ 't' => 0, 'x' => 140, 'y' => 50 ] ] ],
            ] ],
        ] );

        $this->assertSame(
            100.0,
            $created['data']['scene']['scene']['actors'][0]['keyframes'][0]['x'],
            'a write returns the stored scene, so a clamp is visible in the moment it happens'
        );
    }

    public function test_someone_who_cannot_author_exercises_cannot_author_scenes(): void {
        $this->author();
        $exercise_id = $this->makeExercise();

        wp_set_current_user( 0 );
        [ $status ] = $this->call( 'POST', self::BASE . "/exercises/{$exercise_id}/scenes", [
            'scene' => $this->scene(),
        ] );
        $this->assertContains( $status, [ 401, 403 ] );

        // A parent can read the library but must not be able to redraw it.
        $parent = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        wp_set_current_user( $parent );

        [ $status ] = $this->call( 'POST', self::BASE . "/exercises/{$exercise_id}/scenes", [
            'scene' => $this->scene(),
        ] );
        $this->assertContains( $status, [ 401, 403 ] );
    }

    public function test_a_scene_that_no_longer_exists_is_a_404_not_a_fatal(): void {
        $this->author();

        [ $status ] = $this->call( 'GET', self::BASE . '/exercise-scenes/999999' );
        $this->assertSame( 404, $status );

        [ $status ] = $this->call( 'PUT', self::BASE . '/exercise-scenes/999999', [ 'scene' => $this->scene() ] );
        $this->assertSame( 404, $status );
    }

    // ---- rendering --------------------------------------------------------

    public function test_the_print_frame_carries_no_script_and_maps_y_the_way_the_renderer_does(): void {
        $this->author();
        $repo = new ExerciseScenesRepository();

        $id = $repo->create( [
            'exercise_id'  => $this->makeExercise(),
            'pitch_preset' => 'half',
            'duration_ms'  => 6000,
            'scene'        => [ 'actors' => [
                [ 'id' => 'play1', 'kind' => 'player', 'label' => '6', 'keyframes' => [
                    [ 't' => 0, 'x' => 20, 'y' => 20 ],
                    [ 't' => 2200, 'x' => 44, 'y' => 34 ],
                ] ],
            ] ],
        ] );

        $markup = SceneRenderer::staticMarkup( $repo->findById( $id ) );

        $this->assertStringNotContainsString( '<script', $markup, 'paper does not run JavaScript' );
        // The final frame, in the same 6..134 band the JS renderer maps
        // into: 6 + 0.34 * 128 = 49.52. If these two ever disagree the
        // printed diagram stops matching the screen.
        $this->assertStringContainsString( 'translate(44 49.52)', $markup );
    }

    public function test_a_scene_that_never_moves_still_renders(): void {
        $this->author();
        $repo = new ExerciseScenesRepository();

        $id = $repo->create( [
            'exercise_id' => $this->makeExercise(),
            'scene'       => [ 'actors' => [
                [ 'id' => 'cone1', 'kind' => 'cone', 'keyframes' => [ [ 't' => 0, 'x' => 50, 'y' => 50 ] ] ],
            ] ],
        ] );

        $markup = SceneRenderer::staticMarkup( $repo->findById( $id ) );

        $this->assertStringContainsString( 'tt-scene-cone', $markup );
        $this->assertStringContainsString( 'translate(', $markup );
    }

    // ---- the editor's configuration ---------------------------------------

    /**
     * Regression: the config once went out through `wp_localize_script`,
     * which casts every scalar it walks to a string. An actor at x "30"
     * makes the interpolator concatenate rather than add, so a marker
     * halfway between two keyframes lands at "305" and leaves the pitch —
     * and every other thing on the page looks perfectly fine.
     */
    public function test_the_editor_receives_coordinates_as_numbers(): void {
        $this->author();
        $repo        = new ExerciseScenesRepository();
        $exercise_id = $this->makeExercise();

        $scene_id = $repo->create( [
            'exercise_id' => $exercise_id,
            'scene'       => $this->scene(),
        ] );

        $config = $this->editorConfig( $exercise_id, $repo->findById( $scene_id ) );
        $keyframe = $config['scene']['actors'][0]['keyframes'][1];

        $this->assertIsInt( $keyframe['t'] );
        $this->assertIsNumeric( $keyframe['x'] );
        $this->assertIsNotString( $keyframe['x'] );
        $this->assertIsNotString( $keyframe['y'] );
    }

    /**
     * Every `i18n.<key>` the editor's JS reads must be sent. One that is
     * not shows as an empty button with no error anywhere — the cheapest
     * possible check for the most invisible possible failure.
     */
    /**
     * The diagram vocabulary must be translated with a context (#2687).
     *
     * `Pass` and `Run` shipped as plain `__()` calls and resolved to
     * translations of an entirely different sense — a pass/fail result
     * and "run this job". Nothing caught it: the msgid resolved, the
     * English read fine, and the untranslated-string gate saw a full
     * catalogue.
     *
     * So this asserts the shape rather than any particular translation.
     * A future `__( 'Cross' )` added to `linkKinds()` fails here, which
     * is the only moment anyone would think about it.
     */
    public function test_the_diagram_vocabulary_is_translated_with_a_context(): void {
        $source = (string) file_get_contents(
            TT_PLUGIN_DIR . 'src/Modules/Exercises/Frontend/SceneEditorRenderer.php'
        );

        foreach ( [ 'actorTools', 'linkKinds', 'pitchOptions' ] as $method ) {
            $body = self::methodBody( $source, $method );
            $this->assertNotSame( '', $body, "could not read {$method}()" );

            $this->assertStringNotContainsString(
                "__( '",
                $body,
                "{$method}() has a label on a plain __() — a one-word msgid in a catalogue this "
                    . 'size will inherit whatever sense was registered first. Use _x() with the '
                    . 'diagram context.'
            );

            preg_match_all( "/_x\(\s*'[^']+',\s*'([^']+)'/", $body, $contexts );
            $this->assertNotEmpty( $contexts[1], "{$method}() has no _x() labels at all" );

            foreach ( $contexts[1] as $context ) {
                $this->assertSame(
                    'line or marker in a drill diagram',
                    $context,
                    "{$method}() uses a different context — the vocabulary has to share one, or "
                        . 'two words in the same picker end up in different catalogue neighbourhoods.'
                );
            }
        }
    }

    /** The text between a method's opening and closing brace. */
    private static function methodBody( string $source, string $method ): string {
        $at = strpos( $source, "function {$method}(" );
        if ( $at === false ) return '';

        $open = strpos( $source, '{', $at );
        if ( $open === false ) return '';

        $depth = 0;
        for ( $i = $open; $i < strlen( $source ); $i++ ) {
            if ( $source[ $i ] === '{' ) $depth++;
            if ( $source[ $i ] === '}' ) {
                $depth--;
                if ( $depth === 0 ) return substr( $source, $open, $i - $open + 1 );
            }
        }

        return '';
    }

    public function test_every_string_the_editor_reads_is_sent(): void {
        $this->author();
        $repo        = new ExerciseScenesRepository();
        $exercise_id = $this->makeExercise();

        $config = $this->editorConfig(
            $exercise_id,
            $repo->findById( $repo->create( [ 'exercise_id' => $exercise_id, 'scene' => $this->scene() ] ) )
        );

        $js = (string) file_get_contents( TT_PLUGIN_DIR . 'assets/js/frontend-training-scene-editor.js' );
        preg_match_all( '/i18n\.([a-zA-Z0-9_]+)/', $js, $matches );

        $read = array_values( array_unique( $matches[1] ) );
        $sent = array_keys( $config['i18n'] );

        $this->assertSame( [], array_values( array_diff( $read, $sent ) ), 'strings the editor reads but PHP never sends' );

        foreach ( $config['i18n'] as $key => $value ) {
            $this->assertNotSame( '', trim( (string) $value ), "the '{$key}' string is empty" );
        }
    }

    /**
     * Render the editor and read back the config the browser would get.
     *
     * @return array<string,mixed>
     */
    private function editorConfig( int $exercise_id, object $scene ): array {
        global $wp_scripts;
        $wp_scripts = null;

        $exercise = (object) [ 'id' => $exercise_id ];

        ob_start();
        SceneEditorRenderer::render( $exercise, $scene, home_url( '/' ) );
        ob_end_clean();

        $raw = '';
        foreach ( (array) wp_scripts()->get_data( 'tt-frontend-training-scene-editor', 'before' ) as $line ) {
            if ( is_string( $line ) && strpos( $line, 'TT_SCENE_EDITOR' ) !== false ) $raw = $line;
        }

        preg_match( '/var TT_SCENE_EDITOR = (.*);$/s', $raw, $matches );

        return (array) json_decode( $matches[1] ?? '{}', true );
    }
}
