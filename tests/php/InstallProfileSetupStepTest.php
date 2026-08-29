<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Core\ModuleRegistry;
use TT\Modules\Onboarding\Admin\OnboardingHandlers;
use TT\Modules\Onboarding\OnboardingState;
use TT\Shared\Modules\ModuleMetadata;
use TT\Shared\Modules\ProfileRegistry;
use TT\Shared\Modules\ProfileService;

/**
 * #3038 — the install-profile step in the Setup wizard.
 *
 * Two properties matter more than the rest and neither is visible from
 * the screen: that the step sits where the operator's decisions happen
 * in the right order, and that re-running Setup on a configured install
 * cannot silently undo what somebody already chose.
 */
final class InstallProfileSetupStepTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        global $wpdb; $wpdb->hide_errors();
        $this->clearCaches();
        delete_option( 'tt_onboarding_state' );
    }

    public function tear_down(): void {
        $this->clearCaches();
        parent::tear_down();
    }

    private function clearCaches(): void {
        foreach ( [ ModuleRegistry::class, FeatureRegistry::class ] as $class ) {
            $ref = new \ReflectionClass( $class );
            foreach ( [ 'stateCache', 'devStateCache' ] as $prop ) {
                $p = $ref->getProperty( $prop );
                $p->setAccessible( true );
                $p->setValue( null, null );
            }
        }
        ProfileService::resetConfigCache();
    }

    /**
     * Before the import, after the academy. Choosing after the import
     * would mean importing into a shape that is about to change.
     */
    public function test_the_profile_step_sits_between_academy_and_import(): void {
        $steps = OnboardingState::STEPS;

        $academy = array_search( 'academy', $steps, true );
        $profile = array_search( 'profile', $steps, true );
        $import  = array_search( 'import', $steps, true );

        $this->assertIsInt( $academy );
        $this->assertIsInt( $profile );
        $this->assertIsInt( $import );
        $this->assertSame( $academy + 1, $profile );
        $this->assertSame( $profile + 1, $import );
    }

    public function test_saving_the_academy_step_advances_to_the_profile_step(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        OnboardingHandlers::saveAcademy( [
            'academy_name'  => 'Test academy',
            'primary_color' => '#0b3d2e',
            'season_label'  => '2026/27',
            'date_format'   => 'Y-m-d',
        ] );

        $this->assertSame( 'profile', OnboardingState::get()['step'] );
    }

    // ------------------------------------------------------------------
    // The freshness gate
    // ------------------------------------------------------------------

    public function test_a_fresh_install_reports_no_operator_changes(): void {
        $this->assertFalse( ProfileService::hasOperatorChanges() );
    }

    public function test_one_hand_thrown_switch_makes_the_install_configured(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        ModuleRegistry::setEnabled( 'TT\\Modules\\Training\\TrainingModule', false );
        $this->clearCaches();

        $this->assertTrue(
            ProfileService::hasOperatorChanges(),
            'An install somebody has already shaped must not be applied to silently.'
        );
    }

    /**
     * Skipping means Full academy, and Full academy is reached by
     * applying nothing at all — so an operator who skips gets exactly
     * what an install gets today.
     */
    public function test_skipping_leaves_the_install_untouched(): void {
        $before = [];
        foreach ( ModuleRegistry::allWithState() as $row ) {
            $before[ $row['class'] ] = $row['enabled'];
        }

        OnboardingState::recordPayload( 'profile', [ 'step_skipped' => true ] );
        OnboardingState::setStep( 'import' );
        $this->clearCaches();

        $after = [];
        foreach ( ModuleRegistry::allWithState() as $row ) {
            $after[ $row['class'] ] = $row['enabled'];
        }

        $this->assertSame( $before, $after );
        $this->assertNull( ProfileService::current() );
    }

    // ------------------------------------------------------------------
    // "What it includes"
    // ------------------------------------------------------------------

    public function test_included_modules_are_grouped_and_exclude_what_the_profile_switches_off(): void {
        $grouped = ProfileRegistry::includedByCategory( 'basics' );
        $this->assertNotEmpty( $grouped );

        $categories = ModuleMetadata::categories();
        foreach ( array_keys( $grouped ) as $category ) {
            $this->assertArrayHasKey( $category, $categories, "Unknown category {$category}." );
        }

        $flat = [];
        foreach ( $grouped as $labels ) {
            foreach ( $labels as $label ) $flat[] = $label;
            // An empty group is dropped rather than rendered as a heading
            // with nothing under it.
            $this->assertNotEmpty( $labels );
        }

        $players  = ModuleMetadata::for( 'TT\\Modules\\Players\\PlayersModule' );
        $training = ModuleMetadata::for( 'TT\\Modules\\Training\\TrainingModule' );

        $this->assertContains( (string) $players['label'], $flat );
        $this->assertNotContains( (string) $training['label'], $flat );
    }

    public function test_included_modules_for_an_unknown_profile_is_empty(): void {
        $this->assertSame( [], ProfileRegistry::includedByCategory( 'no-such-profile' ) );
    }
}
