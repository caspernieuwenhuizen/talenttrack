<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use ReflectionMethod;
use TT\Domain\Vocabularies\Lookups\GoalPriority;
use TT\Domain\Vocabularies\Lookups\GoalStatus;
use TT\Shared\Frontend\FrontendMyGoalsView;

/**
 * #3396 — the goals board sorted a player's goals by string-matching values
 * the product does not store (`achieved`, `signed_off`, `missed`, `canceled`),
 * while the two that fall through — `pending_approval` and `on_hold` — were
 * indistinguishable from live work.
 *
 * Every member of GoalStatus is asserted, so adding one to the vocabulary
 * without deciding where it belongs on the board fails here rather than
 * quietly landing in "Actief".
 */
final class GoalBoardBucketTest extends WP_UnitTestCase {

    private function bucketFor( string $status ): string {
        $m = new ReflectionMethod( FrontendMyGoalsView::class, 'bucketFor' );
        $m->setAccessible( true );
        return (string) $m->invoke( null, $status );
    }

    private function statusChip( string $status ): string {
        $m = new ReflectionMethod( FrontendMyGoalsView::class, 'statusChipClass' );
        $m->setAccessible( true );
        return (string) $m->invoke( null, $status, $this->bucketFor( $status ) );
    }

    private function priorityChip( string $priority ): string {
        $m = new ReflectionMethod( FrontendMyGoalsView::class, 'priorityChipClass' );
        $m->setAccessible( true );
        return (string) $m->invoke( null, $priority );
    }

    /** @return array<string, array{0:string, 1:string}> */
    public static function statusBuckets(): array {
        return [
            'pending'          => [ GoalStatus::PENDING,          'active' ],
            'pending_approval' => [ GoalStatus::PENDING_APPROVAL, 'active' ],
            'in_progress'      => [ GoalStatus::IN_PROGRESS,      'active' ],
            'on_hold'          => [ GoalStatus::ON_HOLD,          'active' ],
            'completed'        => [ GoalStatus::COMPLETED,        'done'   ],
            'cancelled'        => [ GoalStatus::CANCELLED,        'missed' ],
        ];
    }

    /** @dataProvider statusBuckets */
    public function test_every_stored_status_lands_in_a_decided_bucket( string $status, string $expected ): void {
        $this->assertSame( $expected, $this->bucketFor( $status ),
            "{$status} must land in the {$expected} column" );
    }

    public function test_the_vocabulary_is_covered(): void {
        $covered = array_map( static fn( array $row ): string => $row[0], self::statusBuckets() );
        $this->assertSame(
            [],
            array_values( array_diff( GoalStatus::ALL, $covered ) ),
            'a GoalStatus member with no row above would fall through to "active" unnoticed'
        );
    }

    public function test_awaiting_approval_is_visually_distinct_from_live_work(): void {
        $awaiting = $this->statusChip( GoalStatus::PENDING_APPROVAL );
        $working  = $this->statusChip( GoalStatus::IN_PROGRESS );

        $this->assertNotSame( $working, $awaiting,
            'a goal waiting on the coach must not look identical to one being worked on' );
        $this->assertStringContainsString( 'awaiting', $awaiting );
    }

    public function test_on_hold_is_visually_distinct_from_live_work(): void {
        $this->assertStringContainsString( 'onhold', $this->statusChip( GoalStatus::ON_HOLD ) );
    }

    public function test_completed_and_cancelled_keep_their_chips(): void {
        $this->assertStringContainsString( 'done',   $this->statusChip( GoalStatus::COMPLETED ) );
        $this->assertStringContainsString( 'missed', $this->statusChip( GoalStatus::CANCELLED ) );
    }

    public function test_an_unknown_status_reads_as_active(): void {
        $this->assertSame( 'active', $this->bucketFor( 'sabbatical' ),
            "an academy's own vocabulary should read as a goal to work on, not as a verdict" );
    }

    public function test_priority_chips_match_stored_values_not_lookup_labels(): void {
        $this->assertStringContainsString( 'high', $this->priorityChip( GoalPriority::HIGH ) );
        $this->assertStringContainsString( 'low',  $this->priorityChip( GoalPriority::LOW ) );

        // The Dutch labels an operator can rename in the lookups admin. These
        // never reach the column, and matching them is what #2909 was about.
        $this->assertSame( 'tt-goal-chip--priority', $this->priorityChip( 'hoog' ) );
        $this->assertSame( 'tt-goal-chip--priority', $this->priorityChip( 'laag' ) );
    }
}
