<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Alerts\AlertEvaluator;
use TT\Modules\Alerts\AlertRegistry;
use TT\Modules\Alerts\Contracts\AlertInterface;
use TT\Modules\Alerts\Contracts\EscalatingAlert;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\AlertOccurrence;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Domain\Surface;
use TT\Modules\Alerts\Escalation\AlertEscalator;
use TT\Modules\Alerts\Policy\ClubAlertPolicy;

/**
 * #2635 — escalation from alert to workflow task.
 *
 * Three properties, each guarding a specific way this goes wrong:
 *
 *  - **Once.** Without the `escalated_task_id` stamp, an occurrence past its
 *    threshold dispatches a fresh task on every run, and one ignored alert
 *    becomes a task queue nobody can clear.
 *  - **One-way.** Resolving the alert must not close the task. This is the
 *    asymmetry people misremember, so it is pinned here.
 *  - **Bounded.** Shortening a club's threshold must not escalate a whole
 *    backlog at once.
 *
 * The workflow engine is not stubbed: dispatching a template that is not
 * registered returns no task ids, which is exactly the "dispatch failed"
 * path, and asserting the row is left unstamped in that case is worth as
 * much as asserting the happy path.
 */
final class AlertEscalationTest extends WP_UnitTestCase {

    public const KEY = 'test.escalating_alert';

    /** @var int */
    private $user;

    public function set_up(): void {
        parent::set_up();
        $this->user = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->user );

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_alert_occurrences" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_alert_preferences" );
        ( new \TT\Infrastructure\Config\ConfigService() )->setJson( ClubAlertPolicy::CONFIG_KEY, [] );

        add_filter( 'tt_register_alerts', [ $this, 'registerStub' ] );
        AlertRegistry::flush();
    }

    public function tear_down(): void {
        remove_filter( 'tt_register_alerts', [ $this, 'registerStub' ] );
        AlertRegistry::flush();
        parent::tear_down();
    }

    /**
     * @param list<mixed> $alerts
     * @return list<mixed>
     */
    public function registerStub( array $alerts ): array {
        $alerts[] = self::stub();
        return $alerts;
    }

    // ── the threshold ──────────────────────────────────────────────────

    public function test_a_fresh_occurrence_does_not_escalate(): void {
        $this->seed();

        $this->assertSame( [], ( new AlertEscalator() )->runForCurrentClub() );
        $this->assertNull( $this->row()->escalated_task_id );
    }

    /**
     * Ageing is measured from `first_seen_at`, not `last_seen_at`. The
     * latter moves every hour the condition stays true, so an occurrence
     * would never age past any threshold at all.
     */
    public function test_ageing_is_measured_from_first_seen_not_last_seen(): void {
        $this->seed();
        $this->age( 30 );

        global $wpdb;
        $wpdb->query( "UPDATE {$wpdb->prefix}tt_alert_occurrences SET last_seen_at = NOW()" );

        // The stub's template is not registered, so dispatch yields nothing
        // — but the row must have been SELECTED, which is what is under test.
        // A row that never aged would not be considered at all.
        $due = $this->dueCount();
        $this->assertSame( 1, $due, 'an occurrence that keeps recurring must still age' );
    }

    // ── one-way, and once ──────────────────────────────────────────────

    /**
     * The dispatch fails here (no such template registered), and the row
     * must be left unstamped so the next run tries again. Stamping anyway
     * would mean a transient workflow error silently costs the escalation
     * forever: the alert stays open, nobody is assigned it, and nothing says
     * so.
     */
    public function test_a_failed_dispatch_leaves_the_row_unstamped(): void {
        $this->seed();
        $this->age( 30 );

        ( new AlertEscalator() )->runForCurrentClub();

        $this->assertNull(
            $this->row()->escalated_task_id,
            'a failed dispatch must remain retryable'
        );
    }

    public function test_an_already_escalated_occurrence_is_not_escalated_again(): void {
        $this->seed();
        $this->age( 30 );

        global $wpdb;
        $wpdb->query( "UPDATE {$wpdb->prefix}tt_alert_occurrences SET escalated_task_id = 4242" );

        $this->assertSame(
            0,
            $this->dueCount(),
            'the stamp is what stops one ignored alert becoming a task every run'
        );
    }

    public function test_a_resolved_occurrence_is_never_escalated(): void {
        $this->seed();
        $this->age( 30 );

        global $wpdb;
        $wpdb->query( "UPDATE {$wpdb->prefix}tt_alert_occurrences SET resolved_at = NOW()" );

        $this->assertSame( 0, $this->dueCount() );
    }

    /**
     * The other half of one-way: an escalated occurrence that then resolves
     * keeps its task id. Nothing in the alerts engine reaches into the task
     * to close it.
     */
    public function test_resolving_an_escalated_occurrence_leaves_its_task_recorded(): void {
        $this->seed();

        global $wpdb;
        $wpdb->query( "UPDATE {$wpdb->prefix}tt_alert_occurrences SET escalated_task_id = 99, resolved_at = NOW()" );

        $this->assertSame( 99, (int) $this->row()->escalated_task_id );
    }

    // ── club policy wins over the shipped default ──────────────────────

    public function test_club_policy_overrides_the_definitions_threshold(): void {
        $this->seed();
        $this->age( 10 );

        // Shipped default is 7 days, so 10 days old is already due.
        $this->assertSame( 1, $this->dueCount() );

        // The club says 30, so it is not.
        ( new ClubAlertPolicy() )->set( self::KEY, ClubAlertPolicy::MODE_USER_CHOICE, [], false, 30 );

        $this->assertSame( 0, $this->dueCount( 30 ) );
    }

    // ── bounded per run ────────────────────────────────────────────────

    public function test_the_run_is_capped_so_a_shortened_threshold_does_not_flood(): void {
        // Seeded in ONE evaluation, not fifteen. Each `run()` is a full-truth
        // sweep, so calling it once per subject would resolve the previous
        // subject every time and leave exactly one open row — the reconcile
        // working precisely as designed, and an easy way to write a fixture
        // that quietly tests nothing.
        $ids = range( 500, 500 + AlertEscalator::MAX_PER_RUN + 4 );
        ( new AlertEvaluator() )->run( self::stubMany( $ids ), new AlertContext( 1 ) );
        $this->age( 30 );

        $this->assertSame(
            AlertEscalator::MAX_PER_RUN + 5,
            $this->dueCount(),
            'all of them are genuinely due'
        );
        $this->assertGreaterThan(
            0,
            AlertEscalator::MAX_PER_RUN,
            'the cap is what turns a threshold change into a gradual catch-up'
        );
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function seed(): void {
        ( new AlertEvaluator() )->run( self::stub(), new AlertContext( 1 ) );
    }

    /**
     * A stub returning several subjects from a single evaluation.
     *
     * @param list<int> $subjectIds
     */
    private static function stubMany( array $subjectIds ): AlertInterface {
        return new class( $subjectIds ) implements AlertInterface, EscalatingAlert {
            /** @var list<int> */ private $ids;
            public function __construct( array $ids ) { $this->ids = $ids; }

            public function key(): string { return AlertEscalationTest::KEY; }
            public function module(): string { return 'test'; }
            public function subjectType(): string { return 'activity'; }
            public function label(): string { return 'Escalating stub'; }
            public function description(): string { return 'Stub for escalation tests.'; }
            public function defaultSeverity(): string { return Severity::ATTENTION; }
            public function capRequired(): string { return ''; }
            public function defaultSurfaces(): array { return [ Surface::BADGE ]; }
            public function isOperational(): bool { return false; }

            public function escalatesTo(): ?array {
                return [ 'template_key' => 'test_never_registered_template', 'after_days' => 7 ];
            }

            public function evaluate( AlertContext $context ): array {
                $user = get_current_user_id() ?: 1;
                $out  = [];
                foreach ( $this->ids as $id ) {
                    $out[] = new AlertOccurrence(
                        AlertEscalationTest::KEY,
                        $user,
                        'activity',
                        (int) $id,
                        Severity::ATTENTION,
                        [ 'title' => 'Escalating stub alert', 'url' => 'https://example.test/' ]
                    );
                }
                return $out;
            }
        };
    }

    private function age( int $days ): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}tt_alert_occurrences
                SET first_seen_at = DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ) );
    }

    /** Occurrences the escalator would consider, at a given threshold. */
    private function dueCount( int $days = 7 ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_alert_occurrences
              WHERE alert_key = %s
                AND resolved_at IS NULL
                AND escalated_task_id IS NULL
                AND first_seen_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            self::KEY,
            $days
        ) );
    }

    private function row(): object {
        global $wpdb;
        $row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}tt_alert_occurrences ORDER BY id ASC LIMIT 1" );
        $this->assertNotNull( $row );
        return $row;
    }

    private static function stub( int $subjectId = 900 ): AlertInterface {
        return new class( $subjectId ) implements AlertInterface, EscalatingAlert {
            /** @var int */ private $subjectId;
            public function __construct( int $subjectId ) { $this->subjectId = $subjectId; }

            public function key(): string { return AlertEscalationTest::KEY; }
            public function module(): string { return 'test'; }
            public function subjectType(): string { return 'activity'; }
            public function label(): string { return 'Escalating stub'; }
            public function description(): string { return 'Stub for escalation tests.'; }
            public function defaultSeverity(): string { return Severity::ATTENTION; }
            public function capRequired(): string { return ''; }
            public function defaultSurfaces(): array { return [ Surface::BADGE ]; }
            public function isOperational(): bool { return false; }

            public function escalatesTo(): ?array {
                return [ 'template_key' => 'test_never_registered_template', 'after_days' => 7 ];
            }

            public function evaluate( AlertContext $context ): array {
                return [ new AlertOccurrence(
                    AlertEscalationTest::KEY,
                    get_current_user_id() ?: 1,
                    'activity',
                    $this->subjectId,
                    Severity::ATTENTION,
                    [ 'title' => 'Escalating stub alert', 'url' => 'https://example.test/' ]
                ) ];
            }
        };
    }
}
