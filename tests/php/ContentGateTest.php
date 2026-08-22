<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Core\ModuleRegistry;
use TT\Shared\Content\ContentGate;
use TT\Shared\Content\GateVerdict;

/**
 * #2645 — the four install gates, shared by the docs corpus (#2546) and
 * the course corpus.
 *
 * The assertions that matter are the fail-open ones. A gate that hides
 * content on an unknown key is a bug nobody finds: the content simply
 * stops appearing, on somebody else's install, with no error anywhere.
 * Every "unknown value leaves it visible" test below is guarding that.
 */
final class ContentGateTest extends WP_UnitTestCase {

    /** A real feature key, so the registry recognises it. */
    private const FEATURE = 'knowledge_courses';

    /** Its owning module. */
    private const MODULE = 'TT\\Modules\\Knowledge\\KnowledgeModule';

    public function tear_down(): void {
        // Both registries persist state; leaving a module off would break
        // every test that runs after this file.
        ModuleRegistry::setEnabled( self::MODULE, true );
        FeatureRegistry::setEnabled( self::FEATURE, true );

        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── no keys ────────────────────────────────────────────────────────

    /**
     * Most content carries none of these keys and must behave exactly as
     * it did before the gate existed.
     */
    public function test_content_with_no_gating_keys_is_available(): void {
        $verdict = ContentGate::verdict( [ 'title' => 'Something' ] );

        $this->assertTrue( $verdict->isAvailable() );
        $this->assertSame( GateVerdict::KIND_AVAILABLE, $verdict->kind() );
    }

    public function test_empty_key_values_are_not_gates(): void {
        $verdict = ContentGate::verdict( [
            'module'     => '',
            'feature'    => '',
            'tier'       => '',
            'capability' => '',
        ] );

        $this->assertTrue( $verdict->isAvailable() );
    }

    // ── module ─────────────────────────────────────────────────────────

    public function test_a_disabled_module_makes_content_unavailable(): void {
        ModuleRegistry::setEnabled( self::MODULE, false );

        $verdict = ContentGate::verdict( [ 'module' => self::MODULE ] );

        $this->assertTrue( $verdict->isUnavailable() );
        $this->assertSame( ContentGate::REASON_MODULE, $verdict->reason() );
        $this->assertFalse( $verdict->isListable() );
    }

    public function test_re_enabling_a_module_restores_the_content(): void {
        ModuleRegistry::setEnabled( self::MODULE, false );
        $this->assertFalse( ContentGate::isVisible( [ 'module' => self::MODULE ] ) );

        ModuleRegistry::setEnabled( self::MODULE, true );

        $this->assertTrue(
            ContentGate::isVisible( [ 'module' => self::MODULE ] ),
            'The gate must not be one-way — nothing here may be cached.'
        );
    }

    /**
     * A typo in `module:` must not silently hide content. This mirrors
     * `ModuleRegistry::isEnabled()`, which returns true for classes it
     * does not know.
     */
    public function test_an_unknown_module_leaves_content_visible(): void {
        $this->assertTrue( ContentGate::isVisible( [ 'module' => 'TT\\Modules\\NoSuch\\Module' ] ) );
    }

    // ── feature ────────────────────────────────────────────────────────

    public function test_a_disabled_feature_makes_content_unavailable(): void {
        FeatureRegistry::setEnabled( self::FEATURE, false );

        $verdict = ContentGate::verdict( [ 'feature' => self::FEATURE ] );

        $this->assertTrue( $verdict->isUnavailable() );
        $this->assertSame( ContentGate::REASON_FEATURE, $verdict->reason() );
    }

    public function test_an_unknown_feature_leaves_content_visible(): void {
        $this->assertTrue( ContentGate::isVisible( [ 'feature' => 'knowlege_corses' ] ) );
    }

    // ── tier ───────────────────────────────────────────────────────────

    /**
     * The install's own tier must always satisfy content asking for it.
     * Asserted against whatever the test install reports rather than a
     * hardcoded tier, so this keeps holding if the default changes.
     */
    public function test_content_at_the_installs_own_tier_is_available(): void {
        $current = strtolower( (string) \TT\Modules\License\LicenseGate::effectiveTier() );

        $this->assertTrue( ContentGate::isVisible( [ 'tier' => $current ] ) );
    }

    public function test_free_tier_content_is_always_available(): void {
        $this->assertTrue( ContentGate::isVisible( [ 'tier' => 'free' ] ) );
    }

    /**
     * A tier the licence map does not define is not a gate. Hiding a
     * licensed academy's content over a tier-name typo is the worse
     * failure than showing one topic too many.
     */
    public function test_an_unknown_tier_leaves_content_visible(): void {
        $this->assertTrue( ContentGate::isVisible( [ 'tier' => 'platinum' ] ) );
    }

    public function test_tier_comparison_is_ordered_not_equality(): void {
        $tiers = \TT\Modules\License\FeatureMap::tiers();
        $this->assertNotEmpty( $tiers );

        // The lowest tier is reachable from every install.
        $this->assertTrue( ContentGate::isVisible( [ 'tier' => (string) $tiers[0] ] ) );
    }

    // ── capability ─────────────────────────────────────────────────────

    public function test_missing_capability_is_denied_not_unavailable(): void {
        $user = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $user );

        $verdict = ContentGate::verdict( [ 'capability' => 'tt_manage_knowledge' ] );

        $this->assertTrue( $verdict->isDenied() );
        $this->assertFalse( $verdict->isUnavailable(), 'Denied and unavailable must not be conflated.' );
        $this->assertSame( ContentGate::REASON_CAPABILITY, $verdict->reason() );
        $this->assertSame( 'tt_manage_knowledge', $verdict->context()['capability'] );
    }

    public function test_a_held_capability_is_available(): void {
        $user = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $user );

        $this->assertTrue( ContentGate::isVisible( [ 'capability' => 'manage_options' ] ) );
    }

    /**
     * The docs surface resolves visibility for a named user rather than
     * always for whoever is logged in.
     */
    public function test_capability_resolves_for_an_explicit_user(): void {
        $admin      = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );

        wp_set_current_user( $subscriber );

        $this->assertTrue( ContentGate::isVisible( [ 'capability' => 'manage_options' ], $admin ) );
        $this->assertFalse( ContentGate::isVisible( [ 'capability' => 'manage_options' ], $subscriber ) );
    }

    public function test_a_logged_out_reader_fails_a_capability_gate(): void {
        $this->assertFalse( ContentGate::isVisible( [ 'capability' => 'manage_options' ], 0 ) );
    }

    // ── ordering ───────────────────────────────────────────────────────

    /**
     * Install-wide reasons win over per-reader ones. On an install without
     * the module, the answer is "not here", not "you lack a capability" —
     * the second sends an administrator looking for a permission to grant
     * that would change nothing.
     */
    public function test_module_state_outranks_capability(): void {
        $subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $subscriber );

        ModuleRegistry::setEnabled( self::MODULE, false );

        $verdict = ContentGate::verdict( [
            'module'     => self::MODULE,
            'capability' => 'tt_manage_knowledge',
        ] );

        $this->assertSame( ContentGate::REASON_MODULE, $verdict->reason() );
    }

    // ── verdict shape ──────────────────────────────────────────────────

    /**
     * Unavailable and denied content is absent from a listing; locked
     * content is listed. Hiding a locked lesson makes a course look
     * shorter than it is.
     */
    public function test_listability_by_kind(): void {
        $this->assertTrue( GateVerdict::available()->isListable() );
        $this->assertTrue( GateVerdict::locked( 'x' )->isListable() );
        $this->assertFalse( GateVerdict::unavailable( 'x' )->isListable() );
        $this->assertFalse( GateVerdict::denied( 'x' )->isListable() );
    }

    public function test_verdict_serialises_for_rest(): void {
        $array = GateVerdict::locked( 'previous_lesson_incomplete', [ 'after' => '01-intro' ] )->toArray();

        $this->assertSame( GateVerdict::KIND_LOCKED, $array['kind'] );
        $this->assertSame( 'previous_lesson_incomplete', $array['reason'] );
        $this->assertSame( '01-intro', $array['context']['after'] );
    }

    /**
     * The four kinds are mutually exclusive; a consumer switching on one
     * must not match another.
     */
    public function test_kinds_do_not_overlap(): void {
        $cases = [
            GateVerdict::available(),
            GateVerdict::unavailable( 'x' ),
            GateVerdict::denied( 'x' ),
            GateVerdict::locked( 'x' ),
        ];

        foreach ( $cases as $verdict ) {
            $matches = (int) $verdict->isAvailable()
                + (int) $verdict->isUnavailable()
                + (int) $verdict->isDenied()
                + (int) $verdict->isLocked();

            $this->assertSame( 1, $matches, 'Verdict ' . $verdict->kind() . ' matched more than one kind.' );
        }
    }
}
