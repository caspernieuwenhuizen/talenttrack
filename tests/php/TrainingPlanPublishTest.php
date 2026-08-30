<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Training\Repositories\TrainingPlansRepository;

/**
 * #3220 — publishing a training plan.
 *
 * The `methodology_delivered` message has promised since #0066 that a
 * plan "is published", and the product had no such concept: Methodology
 * raised no event at all, and `visibility` is an access scope rather than
 * a state. So publishing was built rather than approximated — firing the
 * message from the nearest existing event (a plan being attached to a
 * Tuesday) would have told coaches something the copy does not mean.
 *
 * The behaviour worth pinning is that the hook is **edge-triggered**. A
 * coach hears about a plan once. Re-publishing, and editing a published
 * plan, must both stay silent, or the message trains people to ignore it.
 */
final class TrainingPlanPublishTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private TrainingPlansRepository $repo;

    /** @var list<int> */
    private array $fired = [];

    /** @var callable|null */
    private $spy = null;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
        $this->repo = new TrainingPlansRepository();

        $this->fired = [];
        $this->spy   = function ( int $plan_id, int $club_id ): void {
            $this->fired[] = $plan_id;
        };
        add_action( 'tt_training_plan_published', $this->spy, 10, 2 );
    }

    public function tear_down(): void {
        if ( $this->spy !== null ) {
            remove_action( 'tt_training_plan_published', $this->spy, 10 );
            $this->spy = null;
        }
        parent::tear_down();
    }

    private function makePlan( bool $is_template = false ): int {
        return $this->repo->create( [
            'title'       => 'Pressing week 3',
            'is_template' => $is_template ? 1 : 0,
        ] );
    }

    private function publishedAt( int $id ): ?string {
        $plan = $this->repo->findById( $id );
        $v    = $plan->published_at ?? null;
        return $v === null ? null : (string) $v;
    }

    public function test_a_new_plan_is_not_published(): void {
        $id = $this->makePlan();

        $this->assertGreaterThan( 0, $id );
        $this->assertNull( $this->publishedAt( $id ) );
        $this->assertSame( [], $this->fired );
    }

    public function test_publishing_stamps_the_plan_and_announces_once(): void {
        $id = $this->makePlan();

        $this->assertTrue( $this->repo->publish( $id ) );

        $this->assertNotNull( $this->publishedAt( $id ) );
        $this->assertSame( [ $id ], $this->fired );
    }

    /**
     * The edge trigger. Publishing an already-published plan reports
     * success — from the caller's side it is published either way — and
     * announces nothing.
     */
    public function test_republishing_announces_nothing(): void {
        $id = $this->makePlan();
        $this->repo->publish( $id );
        $stamp = $this->publishedAt( $id );

        $this->assertTrue( $this->repo->publish( $id ) );

        $this->assertSame( [ $id ], $this->fired, 'the coaches are told once' );
        $this->assertSame( $stamp, $this->publishedAt( $id ), 'and the stamp does not move' );
    }

    /**
     * Publishing announces; it does not lock. Migration 0213 made plans
     * mutable by design and that stays true — but an edit must not
     * re-notify.
     */
    public function test_editing_a_published_plan_keeps_it_published_and_silent(): void {
        $id = $this->makePlan();
        $this->repo->publish( $id );

        $this->repo->update( $id, [ 'title' => 'Pressing week 3 (corrected)' ] );

        $this->assertNotNull( $this->publishedAt( $id ) );
        $this->assertSame( [ $id ], $this->fired );
    }

    /** A template is library material; there are no coaches to tell. */
    public function test_a_template_cannot_be_published(): void {
        $id = $this->makePlan( true );

        $this->assertFalse( $this->repo->publish( $id ) );
        $this->assertNull( $this->publishedAt( $id ) );
        $this->assertSame( [], $this->fired );
    }

    /** Unpublishing corrects a mistake. It says nothing to anybody. */
    public function test_unpublishing_clears_the_stamp_silently(): void {
        $id = $this->makePlan();
        $this->repo->publish( $id );

        $this->assertTrue( $this->repo->unpublish( $id ) );

        $this->assertNull( $this->publishedAt( $id ) );
        $this->assertSame( [ $id ], $this->fired, 'unpublish announces nothing' );
    }

    /** Publishing after an unpublish is a fresh announcement, so it fires. */
    public function test_publishing_again_after_unpublish_announces_again(): void {
        $id = $this->makePlan();
        $this->repo->publish( $id );
        $this->repo->unpublish( $id );
        $this->repo->publish( $id );

        $this->assertSame( [ $id, $id ], $this->fired );
    }

    public function test_an_unknown_plan_publishes_nothing(): void {
        $this->assertFalse( $this->repo->publish( 999999 ) );
        $this->assertSame( [], $this->fired );
    }
}
