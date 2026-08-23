<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Exercises\Vision\ExerciseFuzzyMatcher;
use TT\Modules\Exercises\Vision\VisionDataRegion;
use TT\Modules\Training\Frontend\FrontendTrainingPhotoView;

/**
 * #2502 — the photo-to-plan screen.
 *
 * Its refusals are tested first and hardest, because they are the state
 * every install is in until an operator declares a destination, and
 * because the thing being refused is sending a photograph taken at a
 * youth academy to a third party. A screen that quietly offered a camera
 * on an install that had declared nothing would be the whole #2695 audit
 * happening again in a different file.
 */
final class TrainingPhotoViewTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    private function coach(): int {
        $id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $id );

        return $id;
    }

    private function render(): string {
        ob_start();
        FrontendTrainingPhotoView::render( get_current_user_id(), true );

        return (string) ob_get_clean();
    }

    // ---- the refusals -----------------------------------------------------

    /**
     * The test environment declares no vision constants, which is exactly
     * the state a fresh install is in.
     */
    public function test_an_install_that_declared_no_destination_offers_no_camera(): void {
        $this->coach();
        FeatureRegistry::setEnabled( 'exercises_vision_extraction', true );

        $this->assertFalse( VisionDataRegion::isDeclared(), 'precondition: nothing declared' );

        $html = $this->render();

        $this->assertStringNotContainsString(
            'data-tt-photo',
            $html,
            'the camera must not be mounted on an install that has declared no destination'
        );
        $this->assertStringContainsString( 'has not said where', $html );
    }

    public function test_the_refusal_says_nothing_was_sent(): void {
        $this->coach();
        FeatureRegistry::setEnabled( 'exercises_vision_extraction', true );

        // A coach told only "this cannot be used" is left wondering
        // whether their photo went somewhere first.
        $this->assertStringContainsString( 'Nothing has been sent', $this->render() );
    }

    public function test_the_feature_being_off_refuses_too(): void {
        $this->coach();
        FeatureRegistry::setEnabled( 'exercises_vision_extraction', false );

        $html = $this->render();

        $this->assertStringNotContainsString( 'data-tt-photo', $html );
        $this->assertStringContainsString( 'switched off', $html );
    }

    /**
     * Every path out of this screen — both refusals included — offers the
     * flat form. A coach who came here to plan a training must never
     * reach a dead end.
     */
    public function test_every_refusal_still_offers_the_manual_route(): void {
        $this->coach();

        foreach ( [ true, false ] as $enabled ) {
            FeatureRegistry::setEnabled( 'exercises_vision_extraction', $enabled );

            $this->assertStringContainsString(
                'tt_view=training-plans',
                $this->render(),
                'the way out has to be on the refusal page, not only on the working one'
            );
        }
    }

    public function test_someone_without_planning_rights_is_refused_first(): void {
        $id = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        wp_set_current_user( $id );
        FeatureRegistry::setEnabled( 'exercises_vision_extraction', true );

        $html = $this->render();

        $this->assertStringContainsString( 'do not have permission', $html );
        $this->assertStringNotContainsString( 'data-tt-photo', $html );
    }

    // ---- the thresholds ---------------------------------------------------

    /**
     * The review grid tints a row by how confident the matcher was. A
     * copy of the matcher's threshold on the screen would drift the day
     * someone tuned the matcher, and the tint would then call a row
     * trustworthy on a number the matcher no longer uses.
     */
    public function test_the_amber_threshold_is_the_matcher_s_own(): void {
        $this->assertSame(
            0.6,
            ExerciseFuzzyMatcher::DEFAULT_MIN_SIMILARITY,
            'if this changes deliberately, the review grid follows it automatically — that is the point'
        );
    }

    public function test_certain_is_above_the_matcher_s_floor(): void {
        // Otherwise a row could be tinted "certain" and "not recognised"
        // at once, and the bands would be meaningless.
        $this->assertGreaterThan(
            ExerciseFuzzyMatcher::DEFAULT_MIN_SIMILARITY,
            FrontendTrainingPhotoView::CONFIDENCE_SURE
        );
        $this->assertLessThanOrEqual( 1.0, FrontendTrainingPhotoView::CONFIDENCE_SURE );
    }

    // ---- the strings ------------------------------------------------------

    /**
     * Every `i18n.` key the script reads must be sent. One that is not is
     * a blank button or an empty explanation, with no error anywhere —
     * and on this screen the empty explanation would be the sentence
     * telling a coach what an unmatched row costs.
     */
    public function test_every_string_the_script_reads_is_sent(): void {
        $strings = new \ReflectionMethod( FrontendTrainingPhotoView::class, 'strings' );
        $strings->setAccessible( true );
        $sent = array_keys( (array) $strings->invoke( null ) );

        $js = (string) file_get_contents( TT_PLUGIN_DIR . 'assets/js/frontend-training-photo.js' );
        preg_match_all( '/i18n\.([a-zA-Z0-9_]+)/', $js, $matches );
        $read = array_values( array_unique( $matches[1] ) );

        $this->assertSame( [], array_values( array_diff( $read, $sent ) ), 'read by the script, never sent' );

        foreach ( (array) $strings->invoke( null ) as $key => $value ) {
            $this->assertNotSame( '', trim( (string) $value ), "the '{$key}' string is empty" );
        }
    }

    /**
     * The script must not restate the destination in English of its own.
     * Where a photograph goes is the one sentence on this screen a coach
     * has to be able to read in their own language.
     */
    public function test_the_script_holds_no_user_facing_english(): void {
        $js = (string) file_get_contents( TT_PLUGIN_DIR . 'assets/js/frontend-training-photo.js' );

        // Strip comments before looking: the file explains itself at
        // length, and that prose is not user-facing.
        $code = preg_replace( '#/\*.*?\*/|//.*#s', '', $js );

        $this->assertDoesNotMatchRegularExpression(
            '/textContent\s*=\s*[\'"][A-Z][a-z]{3,}/',
            (string) $code,
            'a literal English sentence is being written into the DOM'
        );
    }
}
