<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Alerts\AlertEvaluator;
use TT\Modules\Alerts\AlertRegistry;
use TT\Modules\Alerts\Contracts\AlertInterface;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\AlertOccurrence;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Repositories\AlertOccurrencesRepository;

/**
 * #2631 — the reconcile loop.
 *
 * This is the class the whole alerts engine rests on, so it gets the
 * densest coverage: the three-way reconcile (insert / bump / resolve), the
 * idempotence that makes an hourly sweep safe, the capability gate, and the
 * truncation guard.
 *
 * Definitions are stubbed rather than driven through real activity rows.
 * The point of these tests is what the evaluator does with what a
 * definition returns; controlling that directly is what lets us assert
 * "returns nothing → everything resolves", which is the single most
 * dangerous behaviour in the design and awkward to provoke through SQL.
 * `AlertsActivityDefinitionsTest` covers the real queries.
 */
final class AlertsReconcileTest extends WP_UnitTestCase {

    /**
     * A capability invented for these tests rather than a real plugin one.
     * The gate mechanism is what is under test; borrowing a production cap
     * would couple these assertions to the authorization matrix and make
     * them fail for reasons that have nothing to do with the evaluator.
     */
    private const TEST_CAP = 'tt_test_alert_cap';

    /** @var AlertOccurrencesRepository */
    private $repo;

    /** @var int */
    private $coach;

    public function set_up(): void {
        parent::set_up();
        $this->repo  = new AlertOccurrencesRepository();
        $this->coach = self::factory()->user->create( [ 'role' => 'administrator' ] );

        // DELETE, not TRUNCATE: TRUNCATE forces an implicit commit in MySQL,
        // which would break the transaction WP_UnitTestCase rolls back
        // between tests and leak rows into the next one.
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_alert_occurrences" );

        AlertRegistry::flush();
    }

    public function tear_down(): void {
        AlertRegistry::flush();
        parent::tear_down();
    }

    // ── the three-way reconcile ────────────────────────────────────────

    public function test_new_condition_inserts_one_row_per_recipient(): void {
        $second = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $alert  = $this->stub( [ $this->occurrence( 41, $this->coach ), $this->occurrence( 41, $second ) ] );

        $stat = ( new AlertEvaluator() )->run( $alert, new AlertContext( 1 ) );

        $this->assertSame( 2, $stat['created'] );
        $this->assertSame( 0, $stat['bumped'] );
        $this->assertSame( 1, $this->repo->openCountForUser( $this->coach ) );
        $this->assertSame( 1, $this->repo->openCountForUser( $second ) );
    }

    public function test_second_sweep_with_no_change_bumps_rather_than_duplicating(): void {
        $alert = $this->stub( [ $this->occurrence( 41, $this->coach ) ] );
        $ev    = new AlertEvaluator();

        $ev->run( $alert, new AlertContext( 1 ) );
        $before = $this->row( 41, $this->coach );

        $stat = $ev->run( $alert, new AlertContext( 1 ) );

        $this->assertSame( 0, $stat['created'], 'a repeat sweep must not insert' );
        $this->assertSame( 1, $stat['bumped'] );
        $this->assertSame( 1, $this->repo->openCountForUser( $this->coach ) );

        $after = $this->row( 41, $this->coach );
        $this->assertSame(
            $before->first_seen_at,
            $after->first_seen_at,
            'first_seen_at is when the recipient first had this problem and must never move'
        );
    }

    public function test_condition_no_longer_returned_is_resolved(): void {
        $ev = new AlertEvaluator();
        $ev->run( $this->stub( [ $this->occurrence( 41, $this->coach ) ] ), new AlertContext( 1 ) );
        $this->assertSame( 1, $this->repo->openCountForUser( $this->coach ) );

        // The coach fixed the underlying thing. Nobody told the alerts
        // engine; the definition simply stops seeing it.
        $stat = $ev->run( $this->stub( [] ), new AlertContext( 1 ) );

        $this->assertSame( 1, $stat['resolved'] );
        $this->assertSame( 0, $this->repo->openCountForUser( $this->coach ) );
    }

    public function test_resolving_one_occurrence_leaves_the_others_open(): void {
        $ev = new AlertEvaluator();
        $ev->run(
            $this->stub( [ $this->occurrence( 41, $this->coach ), $this->occurrence( 42, $this->coach ) ] ),
            new AlertContext( 1 )
        );
        $this->assertSame( 2, $this->repo->openCountForUser( $this->coach ) );

        $ev->run( $this->stub( [ $this->occurrence( 42, $this->coach ) ] ), new AlertContext( 1 ) );

        $this->assertSame( 1, $this->repo->openCountForUser( $this->coach ) );
        $this->assertNotNull( $this->row( 41, $this->coach )->resolved_at );
        $this->assertNull( $this->row( 42, $this->coach )->resolved_at );
    }

    public function test_recurring_condition_reuses_the_row_and_keeps_its_history(): void {
        $ev    = new AlertEvaluator();
        $alert = $this->stub( [ $this->occurrence( 41, $this->coach ) ] );

        $ev->run( $alert, new AlertContext( 1 ) );
        $first_seen = $this->row( 41, $this->coach )->first_seen_at;

        $ev->run( $this->stub( [] ), new AlertContext( 1 ) );
        $this->assertNotNull( $this->row( 41, $this->coach )->resolved_at );

        $ev->run( $alert, new AlertContext( 1 ) );

        $row = $this->row( 41, $this->coach );
        $this->assertNull( $row->resolved_at, 'a recurrence reopens the row' );
        $this->assertSame( $first_seen, $row->first_seen_at, 'and keeps the original first_seen_at' );
        $this->assertSame( 1, $this->repo->openCountForUser( $this->coach ) );
    }

    // ── severity ───────────────────────────────────────────────────────

    public function test_severity_is_recomputed_on_a_bump_not_frozen_at_insert(): void {
        $ev = new AlertEvaluator();
        $ev->run(
            $this->stub( [ $this->occurrence( 41, $this->coach, Severity::ATTENTION ) ] ),
            new AlertContext( 1 )
        );
        $this->assertSame( Severity::ATTENTION, $this->row( 41, $this->coach )->severity );

        // Same condition, now aged past the definition's threshold.
        $ev->run(
            $this->stub( [ $this->occurrence( 41, $this->coach, Severity::URGENT ) ] ),
            new AlertContext( 1 )
        );

        $this->assertSame(
            Severity::URGENT,
            $this->row( 41, $this->coach )->severity,
            'a bump-only reconcile would leave every occurrence at its birth severity'
        );
    }

    // ── capability gate ────────────────────────────────────────────────

    public function test_recipient_without_the_capability_receives_nothing(): void {
        $subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $alert      = $this->stub( [ $this->occurrence( 41, $subscriber ) ], self::TEST_CAP );

        $stat = ( new AlertEvaluator() )->run( $alert, new AlertContext( 1 ) );

        $this->assertSame( 0, $stat['created'] );
        $this->assertSame( 1, $stat['skipped'] );
        $this->assertSame( 0, $this->repo->openCountForUser( $subscriber ) );
    }

    public function test_losing_the_capability_resolves_an_existing_occurrence(): void {
        $user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $user    = new \WP_User( $user_id );
        $user->add_cap( self::TEST_CAP );
        clean_user_cache( $user_id );

        $alert = $this->stub( [ $this->occurrence( 41, $user_id ) ], self::TEST_CAP );
        $ev    = new AlertEvaluator();

        $ev->run( $alert, new AlertContext( 1 ) );
        $this->assertSame( 1, $this->repo->openCountForUser( $user_id ) );

        // The coach moves off the team. The definition still reports the
        // condition; the gate is what must stop it reaching them. This is why
        // the gate runs on every sweep rather than being trusted from the
        // definition's own audience resolution.
        ( new \WP_User( $user_id ) )->remove_cap( self::TEST_CAP );
        clean_user_cache( $user_id );

        $ev->run( $alert, new AlertContext( 1 ) );

        $this->assertSame(
            0,
            $this->repo->openCountForUser( $user_id ),
            'a skipped recipient is absent from the seen set, so the reconcile resolves their row'
        );
    }

    // ── truncation guard ───────────────────────────────────────────────

    public function test_a_run_over_the_ceiling_truncates_and_does_not_resolve(): void {
        $ev = new AlertEvaluator();
        $ev->run( $this->stub( [ $this->occurrence( 999, $this->coach ) ] ), new AlertContext( 1 ) );
        $this->assertSame( 1, $this->repo->openCountForUser( $this->coach ) );

        // A misspecified definition returning far too much. Subject 999 is
        // NOT among them, so a resolving run would mark it fixed.
        $flood = [];
        for ( $i = 0; $i <= AlertEvaluator::OCCURRENCE_CEILING; $i++ ) {
            $flood[] = $this->occurrence( 100000 + $i, $this->coach );
        }

        $stat = $ev->run( $this->stub( $flood ), new AlertContext( 1 ) );

        $this->assertTrue( $stat['truncated'] );
        $this->assertSame( 0, $stat['resolved'], 'a partial view of the truth must never resolve' );
        $this->assertNull(
            $this->row( 999, $this->coach )->resolved_at,
            'the pre-existing occurrence survives a truncated run'
        );
    }

    // ── isolation ──────────────────────────────────────────────────────

    public function test_one_definition_does_not_resolve_another_definitions_rows(): void {
        $ev = new AlertEvaluator();
        $ev->run( $this->stub( [ $this->occurrence( 41, $this->coach ) ], '', 'test.alpha' ), new AlertContext( 1 ) );
        $ev->run( $this->stub( [ $this->occurrence( 42, $this->coach ) ], '', 'test.beta' ), new AlertContext( 1 ) );
        $this->assertSame( 2, $this->repo->openCountForUser( $this->coach ) );

        // Alpha goes quiet. Beta's row is none of its business.
        $ev->run( $this->stub( [], '', 'test.alpha' ), new AlertContext( 1 ) );

        $this->assertSame( 1, $this->repo->openCountForUser( $this->coach ) );
        $this->assertNull( $this->row( 42, $this->coach )->resolved_at );
    }

    public function test_a_throwing_definition_does_not_take_the_sweep_down(): void {
        add_filter( 'tt_register_alerts', function ( array $alerts ): array {
            $alerts[] = $this->throwingStub( 'test.broken' );
            $alerts[] = $this->stub( [ $this->occurrence( 41, $this->coach ) ], '', 'test.healthy' );

            // #3139 — this is the one test here that calls `runAll()`, so it
            // counts the shipped catalogue alongside its stubs. That held
            // while every shipped definition needed records to fire on and a
            // bare test install has none; `comms.messaging_never_configured`
            // is the first one about the install's *configuration*, and a
            // bare install is exactly the state it fires in. Keeping only the
            // stubs states what this test was always assuming.
            return array_values( array_filter(
                $alerts,
                static fn ( $a ): bool => strncmp( $a->key(), 'test.', 5 ) === 0
            ) );
        } );
        AlertRegistry::flush();

        $stats = ( new AlertEvaluator() )->runAll( new AlertContext( 1 ) );

        $this->assertArrayNotHasKey( 'test.broken', $stats );
        $this->assertArrayHasKey( 'test.healthy', $stats );
        $this->assertSame( 1, $this->repo->openCountForUser( $this->coach ) );
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function occurrence( int $subjectId, int $userId, string $severity = Severity::ATTENTION ): AlertOccurrence {
        return new AlertOccurrence(
            'test.alert',
            $userId,
            'activity',
            $subjectId,
            $severity,
            [ 'title' => 'Something needs doing', 'url' => 'https://example.test/' ]
        );
    }

    /**
     * @param list<AlertOccurrence> $occurrences
     */
    private function stub( array $occurrences, string $cap = '', string $key = 'test.alert' ): AlertInterface {
        return new class( $occurrences, $cap, $key ) implements AlertInterface {
            /** @var list<AlertOccurrence> */
            private $occurrences;
            /** @var string */
            private $cap;
            /** @var string */
            private $alertKey;

            public function __construct( array $occurrences, string $cap, string $key ) {
                $this->occurrences = $occurrences;
                $this->cap         = $cap;
                $this->alertKey    = $key;
            }

            public function key(): string { return $this->alertKey; }
            public function module(): string { return 'test'; }
            public function subjectType(): string { return 'activity'; }
            public function label(): string { return 'Test alert'; }
            public function description(): string { return 'Test alert.'; }
            public function defaultSeverity(): string { return Severity::ATTENTION; }
            public function capRequired(): string { return $this->cap; }
            public function defaultSurfaces(): array { return [ 'badge' ]; }
            public function isOperational(): bool { return false; }
            public function evaluate( AlertContext $context ): array { return $this->occurrences; }
        };
    }

    private function throwingStub( string $key ): AlertInterface {
        return new class( $key ) implements AlertInterface {
            /** @var string */
            private $alertKey;
            public function __construct( string $key ) { $this->alertKey = $key; }
            public function key(): string { return $this->alertKey; }
            public function module(): string { return 'test'; }
            public function subjectType(): string { return 'activity'; }
            public function label(): string { return 'Broken alert'; }
            public function description(): string { return 'Throws.'; }
            public function defaultSeverity(): string { return Severity::ATTENTION; }
            public function capRequired(): string { return ''; }
            public function defaultSurfaces(): array { return [ 'badge' ]; }
            public function isOperational(): bool { return false; }
            public function evaluate( AlertContext $context ): array {
                throw new \RuntimeException( 'query blew up' );
            }
        };
    }

    private function row( int $subjectId, int $userId ): object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_alert_occurrences
              WHERE subject_id = %d AND recipient_user_id = %d
              ORDER BY id DESC LIMIT 1",
            $subjectId,
            $userId
        ) );
        $this->assertNotNull( $row, "expected an occurrence row for subject {$subjectId}" );
        return $row;
    }
}
