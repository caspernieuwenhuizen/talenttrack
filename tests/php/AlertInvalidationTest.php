<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Alerts\AlertEvaluator;
use TT\Modules\Alerts\AlertRegistry;
use TT\Modules\Alerts\Contracts\AlertInterface;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\AlertOccurrence;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Invalidation\AlertInvalidationBuffer;
use TT\Modules\Alerts\Invalidation\AlertInvalidationMap;
use TT\Modules\Alerts\Repositories\AlertOccurrencesRepository;

/**
 * #2731 — event-driven invalidation.
 *
 * The behaviour under test is "the alert is gone by the time the user looks
 * again", which decomposes into three separable claims:
 *
 *   1. a domain event names the right subject (the map),
 *   2. the buffer collects, deduplicates and caps them (the buffer),
 *   3. a narrowed run reconciles only that subject, and only definitions
 *      about that kind of subject (the evaluator).
 *
 * The third is where the danger is. A narrowed run that resolves outside
 * its scope is silent data loss wearing the costume of a working feature —
 * the backlog empties and everything looks healthy — so it gets the
 * heaviest coverage here, including the cross-type id collision that the
 * `subject_id`-only scope in `resolveMissing()` used to allow.
 */
final class AlertInvalidationTest extends WP_UnitTestCase {

    /** @var AlertOccurrencesRepository */
    private $repo;

    /** @var int */
    private $coach;

    public function set_up(): void {
        parent::set_up();
        $this->repo  = new AlertOccurrencesRepository();
        $this->coach = self::factory()->user->create( [ 'role' => 'administrator' ] );

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_alert_occurrences" );

        AlertRegistry::flush();
        AlertInvalidationMap::flush();
        AlertInvalidationBuffer::reset();
    }

    public function tear_down(): void {
        AlertRegistry::flush();
        AlertInvalidationMap::flush();
        AlertInvalidationBuffer::reset();
        parent::tear_down();
    }

    // ── the narrowed run ───────────────────────────────────────────────

    public function test_fixing_one_subject_resolves_only_that_subject(): void {
        // Two activities alerting. The coach fixes one.
        $alert = $this->mutableStub( [ 41, 42 ] );
        $this->register( $alert );

        ( new AlertEvaluator() )->runAll( new AlertContext( 1 ) );
        $this->assertSame( 2, $this->repo->openCountForUser( $this->coach ) );

        $alert->subjectIds = [ 42 ];
        ( new AlertEvaluator() )->runForSubject( new AlertContext( 1, 'activity', [ 41 ] ) );

        $this->assertNotNull( $this->row( 41 )->resolved_at, 'the fixed activity must resolve' );
        $this->assertNull( $this->row( 42 )->resolved_at, 'the untouched activity must not' );
        $this->assertSame( 1, $this->repo->openCountForUser( $this->coach ) );
    }

    public function test_a_narrowed_run_does_not_resolve_subjects_it_did_not_look_at(): void {
        // The whole danger of a narrowed reconcile: the definition returns
        // nothing for activity 41 because it is fixed, and 42 must not be
        // read as fixed just because this run never asked about it.
        $alert = $this->mutableStub( [ 41, 42 ] );
        $this->register( $alert );

        ( new AlertEvaluator() )->runAll( new AlertContext( 1 ) );

        $alert->subjectIds = [];
        ( new AlertEvaluator() )->runForSubject( new AlertContext( 1, 'activity', [ 41 ] ) );

        $this->assertNull( $this->row( 42 )->resolved_at );
    }

    public function test_a_narrowed_run_skips_definitions_about_another_subject(): void {
        $activityAlert = $this->mutableStub( [ 41 ], 'test.activity', 'activity' );
        $playerAlert   = $this->mutableStub( [ 41 ], 'test.player', 'player' );
        $this->register( $activityAlert, $playerAlert );

        ( new AlertEvaluator() )->runAll( new AlertContext( 1 ) );
        $this->assertSame( 2, $this->repo->openCountForUser( $this->coach ) );

        // Activity 41 is fixed. Player 41 is a different record that
        // happens to share an id — nothing about them has changed.
        $activityAlert->subjectIds = [];
        $stats = ( new AlertEvaluator() )->runForSubject( new AlertContext( 1, 'activity', [ 41 ] ) );

        $this->assertArrayHasKey( 'test.activity', $stats );
        $this->assertArrayNotHasKey( 'test.player', $stats, 'a player definition has no business in an activity-scoped run' );
        $this->assertNull( $this->row( 41, 'test.player' )->resolved_at, 'the colliding id must survive' );
        $this->assertNotNull( $this->row( 41, 'test.activity' )->resolved_at );
    }

    public function test_a_full_sweep_context_is_refused_rather_than_downgraded(): void {
        $this->register( $this->mutableStub( [ 41 ] ) );

        $stats = ( new AlertEvaluator() )->runForSubject( new AlertContext( 1 ) );

        $this->assertSame( [], $stats );
        $this->assertSame( 0, $this->repo->openCountForUser( $this->coach ) );
    }

    // ── the buffer ─────────────────────────────────────────────────────

    public function test_repeated_events_for_one_subject_buffer_once(): void {
        AlertInvalidationBuffer::subscribe();

        // What an attendance grid save looks like: one event per player row,
        // all naming the same activity.
        for ( $i = 0; $i < 20; $i++ ) {
            do_action( 'tt_activity_attendance_changed', 41 );
        }

        $pending = AlertInvalidationBuffer::peek();
        $this->assertSame( [ 41 ], array_values( $pending[1]['activity'] ?? [] ) );
    }

    public function test_one_event_can_name_several_subject_types(): void {
        AlertInvalidationBuffer::subscribe();

        do_action( 'tt_evaluation_saved', 7, 900 );

        $pending = AlertInvalidationBuffer::peek();
        $this->assertSame( [ 7 ], array_values( $pending[1]['player'] ?? [] ) );
        $this->assertSame( [ 900 ], array_values( $pending[1]['evaluation'] ?? [] ) );
    }

    public function test_a_bulk_operation_past_the_ceiling_is_abandoned_to_the_sweep(): void {
        AlertInvalidationBuffer::subscribe();

        for ( $id = 1; $id <= 120; $id++ ) {
            do_action( 'tt_after_player_save', $id, [] );
        }

        $pending = AlertInvalidationBuffer::peek();
        $this->assertArrayNotHasKey(
            'player',
            $pending[1] ?? [],
            'past the ceiling the type is dropped, not partially reconciled'
        );
    }

    public function test_an_extractor_that_throws_does_not_break_the_save(): void {
        add_filter( 'tt_alert_invalidation_map', static function ( array $map ): array {
            $map['tt_test_exploding_event'] = static function (): array {
                throw new \RuntimeException( 'boom' );
            };
            return $map;
        } );
        AlertInvalidationMap::flush();
        AlertInvalidationBuffer::subscribe();

        do_action( 'tt_test_exploding_event', 41 );

        $this->assertSame( [], AlertInvalidationBuffer::peek() );
    }

    public function test_a_module_can_register_its_own_trigger(): void {
        add_filter( 'tt_alert_invalidation_map', static function ( array $map ): array {
            $map['tt_test_widget_saved'] = static function ( $id ): array {
                return [ 'widget', [ (int) $id ] ];
            };
            return $map;
        } );
        AlertInvalidationMap::flush();
        AlertInvalidationBuffer::subscribe();

        do_action( 'tt_test_widget_saved', 5 );

        $pending = AlertInvalidationBuffer::peek();
        $this->assertSame( [ 5 ], array_values( $pending[1]['widget'] ?? [] ) );
    }

    public function test_draining_reconciles_what_was_buffered(): void {
        $alert = $this->mutableStub( [ 41, 42 ] );
        $this->register( $alert );
        ( new AlertEvaluator() )->runAll( new AlertContext( 1 ) );

        AlertInvalidationBuffer::subscribe();
        $alert->subjectIds = [ 42 ];

        do_action( 'tt_activity_attendance_changed', 41 );
        AlertInvalidationBuffer::drain();

        $this->assertNotNull( $this->row( 41 )->resolved_at );
        $this->assertNull( $this->row( 42 )->resolved_at );
        $this->assertSame( [], AlertInvalidationBuffer::peek(), 'a drain empties the buffer' );
    }

    // ── the shipped map ────────────────────────────────────────────────

    public function test_every_shipped_definition_has_a_trigger(): void {
        // The point of the whole issue: an alert with no entry pointing at
        // its subject type is one that still waits an hour after being
        // fixed. This is the assertion that fails when someone adds a
        // definition and forgets the trigger.
        //
        // `course_submission` is the Knowledge module's, and is exempt on
        // purpose rather than by oversight: a module registering its own
        // alert registers its own trigger through
        // `tt_alert_invalidation_map`, which is the whole reason that
        // filter exists. Hard-coding it into the core table would make the
        // extension point untested on the day someone first needs it —
        // exactly the reasoning `AlertsModule` uses for `tt_register_alerts`.
        $registeredElsewhere = [ 'course_submission' ];

        $triggered = [];
        foreach ( AlertInvalidationMap::all() as $extractor ) {
            foreach ( $this->pairsFrom( $extractor ) as $pair ) {
                $triggered[ $pair[0] ] = true;
            }
        }

        foreach ( AlertRegistry::all() as $key => $alert ) {
            $type = $alert->subjectType();
            if ( in_array( $type, $registeredElsewhere, true ) ) continue;
            $this->assertArrayHasKey(
                $type,
                $triggered,
                sprintf( 'alert "%s" (subject "%s") has no invalidation trigger', $key, $type )
            );
        }
    }

    // ── helpers ────────────────────────────────────────────────────────

    /**
     * Call an extractor with placeholder ids to learn which subject types
     * it produces. Extractors are pure by contract, so this is safe.
     *
     * @return list<array{0:string,1:list<int>}>
     */
    private function pairsFrom( callable $extractor ): array {
        try {
            $pairs = $extractor( 1, 1, 1, 1 );
        } catch ( \Throwable $e ) {
            return [];
        }
        if ( ! is_array( $pairs ) ) return [];
        if ( isset( $pairs[0] ) && is_string( $pairs[0] ) ) return [ $pairs ];
        return array_values( array_filter( $pairs, 'is_array' ) );
    }

    private function register( AlertInterface ...$alerts ): void {
        add_filter( 'tt_register_alerts', static function ( array $registered ) use ( $alerts ): array {
            return array_merge( $registered, $alerts );
        } );
        AlertRegistry::flush();
    }

    private function row( int $subjectId, string $alertKey = 'test.activity' ): object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_alert_occurrences
              WHERE alert_key = %s AND subject_id = %d AND recipient_user_id = %d",
            $alertKey,
            $subjectId,
            $this->coach
        ) );
    }

    /**
     * A definition whose result set can be changed between runs, which is
     * how "the coach fixed it" is expressed without driving real rows
     * through six modules' write paths.
     */
    private function mutableStub( array $subjectIds, string $key = 'test.activity', string $subjectType = 'activity' ): object {
        return new class( $subjectIds, $key, $subjectType, $this->coach ) implements AlertInterface {
            /** @var list<int> */
            public $subjectIds;
            /** @var string */
            private $alertKey;
            /** @var string */
            private $type;
            /** @var int */
            private $recipient;

            public function __construct( array $subjectIds, string $key, string $type, int $recipient ) {
                $this->subjectIds = $subjectIds;
                $this->alertKey   = $key;
                $this->type       = $type;
                $this->recipient  = $recipient;
            }

            public function key(): string { return $this->alertKey; }
            public function module(): string { return 'test'; }
            public function subjectType(): string { return $this->type; }
            public function label(): string { return 'Test alert'; }
            public function description(): string { return 'Test alert.'; }
            public function defaultSeverity(): string { return Severity::ATTENTION; }
            public function capRequired(): string { return ''; }
            public function defaultSurfaces(): array { return [ 'badge' ]; }
            public function isOperational(): bool { return false; }

            public function evaluate( AlertContext $context ): array {
                $out = [];
                foreach ( $this->subjectIds as $id ) {
                    // Honour the scope exactly as a real definition's SQL
                    // does — returning out-of-scope rows from a narrowed run
                    // is the contract violation, not the thing under test.
                    if ( $context->narrowsTo( $this->type ) && ! in_array( $id, $context->subjectIds, true ) ) {
                        continue;
                    }
                    $out[] = new AlertOccurrence(
                        $this->alertKey,
                        $this->recipient,
                        $this->type,
                        $id,
                        Severity::ATTENTION,
                        [ 'title' => 'Something needs doing', 'url' => 'https://example.test/' ]
                    );
                }
                return $out;
            }
        };
    }
}
