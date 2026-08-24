<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Core\ModuleRegistry;
use TT\Modules\Documentation\HelpTopics;

/**
 * #2546 — the docs corpus consults what the install actually runs.
 *
 * Before this, the TOC filtered on the audience marker alone: an academy
 * that switched Methodology off still saw the Methodology topic, and a
 * free-tier install listed topics for features it could not open.
 *
 * The gate has to be reversible. A one-way filter — one that hides a topic
 * and leaves it hidden after the module comes back — would be worse than
 * no gate, so `test_re_enabling_a_module_restores_the_topic` is the load-
 * bearing case here rather than the hiding itself.
 */
final class HelpTopicsGatingTest extends WP_UnitTestCase {

    /** A registered topic that declares `module:` in its front matter. */
    private const TOPIC = 'methodology';

    /** The module that topic names. */
    private const MODULE = 'TT\\Modules\\Methodology\\MethodologyModule';

    /** A registered topic carrying `feature:` + `tier:`. */
    private const GATED_TOPIC = 'team-chemistry';

    private const GATED_FEATURE = 'team_chemistry';

    public function set_up(): void {
        parent::set_up();
        HelpTopics::flushCache();
    }

    public function tear_down(): void {
        ModuleRegistry::setEnabled( self::MODULE, true );
        FeatureRegistry::setEnabled( self::GATED_FEATURE, true );
        HelpTopics::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    private function admin(): int {
        $id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $id );
        return $id;
    }

    // ── the corpus still declares what these tests assume ───────────────

    /**
     * Guards the fixtures rather than the behaviour. If the front matter
     * these tests lean on is edited away, the rest of the file would keep
     * passing while asserting nothing.
     */
    public function test_the_fixture_topics_declare_the_keys_under_test(): void {
        $all = HelpTopics::all();

        $this->assertArrayHasKey( self::TOPIC, $all );
        $this->assertSame( self::MODULE, $all[ self::TOPIC ]['module'] );

        $this->assertArrayHasKey( self::GATED_TOPIC, $all );
        $this->assertSame( self::GATED_FEATURE, $all[ self::GATED_TOPIC ]['feature'] );
        $this->assertSame( 'pro', $all[ self::GATED_TOPIC ]['tier'] );
    }

    // ── module ─────────────────────────────────────────────────────────

    public function test_a_disabled_module_hides_its_topic(): void {
        $user = $this->admin();

        $this->assertArrayHasKey( self::TOPIC, HelpTopics::visibleFor( $user ) );

        ModuleRegistry::setEnabled( self::MODULE, false );

        $this->assertArrayNotHasKey( self::TOPIC, HelpTopics::visibleFor( $user ) );
    }

    /**
     * The gate is computed per call, never cached alongside the scan — a
     * module toggle has to take effect on the next page load, not on the
     * next plugin update.
     */
    public function test_re_enabling_a_module_restores_the_topic(): void {
        $user = $this->admin();

        ModuleRegistry::setEnabled( self::MODULE, false );
        $this->assertArrayNotHasKey( self::TOPIC, HelpTopics::visibleFor( $user ) );

        ModuleRegistry::setEnabled( self::MODULE, true );
        $this->assertArrayHasKey( self::TOPIC, HelpTopics::visibleFor( $user ) );
    }

    public function test_a_disabled_module_leaves_ungated_topics_alone(): void {
        $user = $this->admin();

        ModuleRegistry::setEnabled( self::MODULE, false );

        // The default topic declares no gating keys and must survive
        // anything — it is what every fallback path lands on.
        $this->assertArrayHasKey( HelpTopics::defaultSlug(), HelpTopics::visibleFor( $user ) );
    }

    // ── feature ────────────────────────────────────────────────────────

    public function test_a_disabled_feature_hides_its_topic(): void {
        $user = $this->admin();

        FeatureRegistry::setEnabled( self::GATED_FEATURE, false );

        $this->assertArrayNotHasKey( self::GATED_TOPIC, HelpTopics::visibleFor( $user ) );
    }

    // ── verdicts, which decide the REST status code ────────────────────

    /**
     * The distinction the REST controller reads: a module the install does
     * not run is *unavailable* (404), not *denied* (403). Answering 403
     * would confirm the topic exists here, which is what hiding it was for.
     */
    public function test_a_disabled_module_reports_unavailable_not_denied(): void {
        $user = $this->admin();

        ModuleRegistry::setEnabled( self::MODULE, false );

        $verdict = HelpTopics::verdictFor( self::TOPIC, $user );
        $this->assertTrue( $verdict->isUnavailable() );
        $this->assertFalse( $verdict->isDenied() );
    }

    public function test_an_unregistered_slug_reports_unavailable(): void {
        $verdict = HelpTopics::verdictFor( 'no-such-topic-anywhere', $this->admin() );

        $this->assertTrue( $verdict->isUnavailable() );
        $this->assertSame( HelpTopics::REASON_UNREGISTERED, $verdict->reason() );
    }

    public function test_an_ungated_topic_is_available(): void {
        $verdict = HelpTopics::verdictFor( HelpTopics::defaultSlug(), $this->admin() );

        $this->assertTrue( $verdict->isAvailable() );
    }

    // ── audience still applies ─────────────────────────────────────────

    /**
     * The liveness gates are additive. A reader whose audience does not
     * intersect the topic's never sees it, regardless of module state —
     * `visibleFor()` is both filters, not a replacement for the first.
     */
    public function test_audience_still_filters_when_every_gate_passes(): void {
        $subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $subscriber );

        $visible = HelpTopics::visibleFor( $subscriber );

        foreach ( $visible as $slug => $topic ) {
            $this->assertNotEmpty(
                array_intersect( $topic['audience'], [ 'user', 'player', 'parent' ] ),
                "Topic {$slug} reached a non-admin reader without a matching audience."
            );
        }
    }

    // ── the corpus is internally consistent ────────────────────────────

    /**
     * Every `module:` in the corpus names a class that exists. A typo here
     * does not hide the topic — `ContentGate` fails open on an unknown key
     * — so nothing surfaces it at runtime. #2551's lint is the permanent
     * home for this; until it lands, this test is the only thing standing
     * between a mistyped namespace and a gate that silently never fires.
     */
    public function test_every_declared_module_class_exists(): void {
        foreach ( HelpTopics::all() as $slug => $topic ) {
            if ( ( $topic['module'] ?? '' ) === '' ) {
                continue;
            }
            $this->assertTrue(
                class_exists( $topic['module'] ),
                "Topic {$slug} declares module {$topic['module']}, which does not exist."
            );
        }
    }

    public function test_every_declared_feature_key_is_catalogued(): void {
        foreach ( HelpTopics::all() as $slug => $topic ) {
            if ( ( $topic['feature'] ?? '' ) === '' ) {
                continue;
            }
            $this->assertTrue(
                FeatureRegistry::exists( $topic['feature'] ),
                "Topic {$slug} declares feature {$topic['feature']}, which is not in the catalog."
            );
        }
    }

    public function test_every_declared_tier_is_known(): void {
        foreach ( HelpTopics::all() as $slug => $topic ) {
            if ( ( $topic['tier'] ?? '' ) === '' ) {
                continue;
            }
            $this->assertContains(
                $topic['tier'],
                [ 'free', 'standard', 'pro' ],
                "Topic {$slug} declares an unknown tier {$topic['tier']}."
            );
        }
    }

    /**
     * The default topic is the fallback for the drawer, both viewers and
     * every 403/404 path. Gating it would strand every one of them.
     */
    public function test_the_default_topic_declares_no_gating_keys(): void {
        $topic = HelpTopics::all()[ HelpTopics::defaultSlug() ] ?? null;

        $this->assertNotNull( $topic );
        foreach ( [ 'module', 'feature', 'tier', 'capability' ] as $key ) {
            $this->assertSame( '', $topic[ $key ] ?? '', "The default topic must not be {$key}-gated." );
        }
    }
}
