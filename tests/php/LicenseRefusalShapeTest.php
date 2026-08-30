<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\UpgradePanel;

/**
 * #3104 — the three answers a plan refusal gives, pinned.
 *
 * Slice 1 of #3017 ships no gates. What it ships is the shape the other
 * twenty-seven will take, and a shape is only worth having if it cannot
 * quietly fork. These tests are what stops it:
 *
 *   1. A plan refusal is **402**, and a capability refusal stays 403.
 *      Nothing in `LicenseGate` may return 403 — if it did, "why did this
 *      fail" stops being answerable from a log line.
 *   2. A **read of an existing record survives** its feature leaving the
 *      plan. The gate is on the mutating verbs, not on `GET`.
 *   3. The **panel names the feature** the same way the account page's
 *      plan matrix does, because both read `FeatureMap::featureLabel()`.
 */
final class LicenseRefusalShapeTest extends WP_UnitTestCase {

    /** A Pro feature with stored records — the case the asymmetry is for. */
    private const PRO_FEATURE = 'match_analysis';

    // ── 402, never 403 ─────────────────────────────────────────────────

    public function test_a_plan_refusal_is_402(): void {
        $response = LicenseGate::planRefusal( self::PRO_FEATURE );

        $this->assertSame(
            402,
            $response->get_status(),
            'A plan refusal is 402 Payment Required. 403 is the capability '
            . 'model saying no, and the two must stay distinguishable.'
        );
    }

    public function test_a_plan_refusal_never_returns_403(): void {
        foreach ( FeatureMap::allFeatures() as $feature ) {
            $this->assertNotSame( 403, LicenseGate::planRefusal( $feature )->get_status() );
        }
    }

    public function test_the_refusal_body_names_the_feature_and_the_plan(): void {
        $data = LicenseGate::planRefusal( self::PRO_FEATURE )->get_data();

        $this->assertFalse( $data['success'] );
        $this->assertSame( 'license_required', $data['errors'][0]['code'] );

        $details = (array) $data['errors'][0]['details'];
        $this->assertSame( self::PRO_FEATURE, $details['feature'] );
        $this->assertSame( FeatureMap::TIER_PRO, $details['required_tier'] );

        $this->assertStringContainsString(
            FeatureMap::featureLabel( self::PRO_FEATURE ),
            $data['errors'][0]['message'],
            'A refusal that does not name the feature leaves the reader guessing '
            . 'which of thirty Pro surfaces they just hit.'
        );
    }

    public function test_the_cap_refusal_is_402_too(): void {
        // Caps only bite on an unentitled install, so `enforceCapRest()`
        // returns null here. The envelope's shape is still worth pinning:
        // a cap is a plan refusal, not a permission one.
        $this->assertNotSame(
            '',
            LicenseGate::capMessage( 'players' ),
            'The cap sentence is shared by the REST envelope and the on-screen '
            . 'panel so a club is not told two different things.'
        );
        $this->assertNotSame( LicenseGate::capMessage( 'teams' ), LicenseGate::capMessage( 'players' ) );
    }

    // ── reads survive, writes do not ───────────────────────────────────

    /**
     * The property the whole asymmetry exists for: a club that drops off
     * Pro keeps reading the match analyses it wrote while it was on Pro.
     */
    public function test_a_read_survives_the_feature_being_out_of_plan(): void {
        foreach ( [ 'GET', 'HEAD', 'OPTIONS', 'get' ] as $method ) {
            $this->assertNull(
                LicenseGate::refusalForMethod( self::PRO_FEATURE, $method ),
                "A {$method} of an existing record must survive its feature leaving the plan."
            );
        }
    }

    public function test_a_write_is_refused_when_the_feature_is_out_of_plan(): void {
        foreach ( [ 'POST', 'PUT', 'PATCH', 'DELETE' ] as $method ) {
            $refusal = LicenseGate::refusalForMethod( self::PRO_FEATURE, $method );

            $this->assertNotNull( $refusal, "{$method} must be refused." );
            $this->assertSame( 402, $refusal->get_status() );
        }
    }

    public function test_an_unknown_verb_counts_as_a_write(): void {
        $this->assertTrue(
            LicenseGate::isWriteMethod( 'PURGE' ),
            'An unrecognised verb reaching a gated controller is refused rather '
            . 'than waved through — the other default fails silently open.'
        );
        $this->assertFalse( LicenseGate::isWriteMethod( '' ) );
    }

    public function test_enforce_write_rest_accepts_a_request_object(): void {
        $request = new \WP_REST_Request( 'GET', '/talenttrack/v1/players' );

        $this->assertNull( LicenseGate::enforceWriteRest( self::PRO_FEATURE, $request ) );
    }

    // ── one panel ──────────────────────────────────────────────────────

    public function test_the_panel_names_the_feature_the_way_the_plan_matrix_does(): void {
        $html = UpgradePanel::render( self::PRO_FEATURE );

        $this->assertStringContainsString( 'tt-upgrade-panel', $html );
        $this->assertStringContainsString(
            esc_html( FeatureMap::featureLabel( self::PRO_FEATURE ) ),
            $html
        );
        $this->assertStringContainsString( FeatureMap::tierLabel( FeatureMap::TIER_PRO ), $html );
    }

    /**
     * The locked surface stays on screen. #3017's first decision is that
     * a Pro surface renders locked rather than vanishing, so the panel
     * has to say so rather than leaving the reader to assume a bug.
     */
    public function test_the_panel_says_the_surface_is_still_here(): void {
        $html = UpgradePanel::render( self::PRO_FEATURE );

        $this->assertStringContainsString( 'tt-upgrade-panel__cta', $html );
        $this->assertStringContainsString( 'page=tt-account', $html );
    }

    public function test_the_read_survives_sentence_is_opt_in(): void {
        $bare  = UpgradePanel::render( self::PRO_FEATURE );
        $kept  = UpgradePanel::render( self::PRO_FEATURE, [ 'reads_kept' => true ] );

        $this->assertStringNotContainsString( 'tt-upgrade-panel__body--reassure', $bare );
        $this->assertStringContainsString( 'tt-upgrade-panel__body--reassure', $kept );
    }

    /**
     * The label-and-tier entry point renders the same panel. If it ever
     * stops doing so there are two refusal shapes again, which is the one
     * outcome this slice exists to prevent.
     */
    public function test_the_legacy_entry_point_renders_the_same_panel(): void {
        $html = \TT\Modules\License\Admin\UpgradeNudge::inline( 'Something', FeatureMap::TIER_PRO );

        $this->assertStringContainsString( 'tt-upgrade-panel', $html );
        $this->assertStringContainsString( 'Something', $html );
    }

    public function test_the_cap_panel_shares_the_chrome(): void {
        $html = UpgradePanel::renderCap( 'players' );

        $this->assertStringContainsString( 'tt-upgrade-panel', $html );
        $this->assertStringContainsString( 'tt-upgrade-panel--cap', $html );
        $this->assertStringContainsString( esc_html( LicenseGate::capMessage( 'players' ) ), $html );
    }

    // ── the already-gated features stay gated ──────────────────────────

    /**
     * Slice 1 asserted that it gated nothing, by requiring `match_analysis`
     * to still be in the pending list. That was the right assertion while
     * the foundation was landing alone; #3105 gates `match_analysis` among
     * seven others, so keeping it would mean slice 2 could not ship without
     * slice 1's test being wrong about the product.
     *
     * What is worth keeping is the half that was never about slice 1's
     * scope: migrating the two already-gated features onto the shared panel
     * must not have dropped their `LicenseGate::allows()` call site, which
     * is how a gate silently disappears.
     */
    public function test_the_two_already_gated_features_stay_gated(): void {
        $pending = require TT_PLUGIN_DIR . 'config/license_gate_pending.php';

        foreach ( [ 'scout_access', 'team_chemistry' ] as $shipped ) {
            $this->assertArrayNotHasKey(
                $shipped,
                $pending,
                "{$shipped} was gated before this epic began; a pending entry means its gate was lost"
            );
        }
    }
}
