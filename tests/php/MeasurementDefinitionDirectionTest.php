<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Measurements\Frontend\FrontendMeasurementTestsView;
use TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository;

/**
 * #2195 — some test-definition settings (notably "Richting" / direction) did
 * not persist on save.
 *
 * Root cause: the Manage-tests edit save (FrontendMeasurementTestsView::
 * saveEdit) clamped `direction` to `neutral` for *every* non-numeric value
 * type. But the Direction dropdown is shown for scale-score tests too — a
 * scale test has higher/lower-is-better semantics and target bands — so a
 * scale test's chosen direction was silently overwritten with `neutral` on
 * every save.
 *
 * These tests lock the corrected behaviour: an ordered value type (numeric or
 * scale) round-trips its direction; an unordered one (pass/fail, status) is
 * forced neutral. The last test drives the repository update end-to-end so the
 * fix is proven against the real column, not just the resolver.
 */
final class MeasurementDefinitionDirectionTest extends WP_UnitTestCase {

    public function test_ordered_value_types_keep_chosen_direction(): void {
        // Regression: this was the dropped field. A scale test must keep the
        // "lower is better" direction the operator picked.
        $this->assertSame( 'lower', FrontendMeasurementTestsView::resolveDirection( 'scale', 'lower' ) );
        $this->assertSame( 'higher', FrontendMeasurementTestsView::resolveDirection( 'scale', 'higher' ) );
        $this->assertSame( 'lower', FrontendMeasurementTestsView::resolveDirection( 'numeric', 'lower' ) );
        $this->assertSame( 'neutral', FrontendMeasurementTestsView::resolveDirection( 'numeric', 'neutral' ) );
    }

    public function test_unordered_value_types_force_neutral(): void {
        $this->assertSame( 'neutral', FrontendMeasurementTestsView::resolveDirection( 'passfail', 'higher' ) );
        $this->assertSame( 'neutral', FrontendMeasurementTestsView::resolveDirection( 'status', 'lower' ) );
    }

    public function test_invalid_direction_falls_back_to_higher_for_ordered(): void {
        $this->assertSame( 'higher', FrontendMeasurementTestsView::resolveDirection( 'scale', 'sideways' ) );
    }

    public function test_scale_test_direction_round_trips_through_repository(): void {
        $repo = new MeasurementDefinitionsRepository();

        $id = $repo->create( [
            'category_id' => 1,
            'name'        => 'Wall pass accuracy',
            'value_type'  => 'scale',
            'direction'   => 'higher',
            'scale_min'   => 1,
            'scale_max'   => 10,
            'frequency'   => 'adhoc',
        ] );
        $this->assertGreaterThan( 0, $id );

        // Mirror the corrected Manage-tests save: a scale test edited to
        // "lower is better" must persist that direction.
        $repo->update( $id, [
            'value_type' => 'scale',
            'direction'  => FrontendMeasurementTestsView::resolveDirection( 'scale', 'lower' ),
        ] );

        $def = $repo->find( $id );
        $this->assertNotNull( $def );
        $this->assertSame( 'lower', (string) $def->direction );
    }
}
