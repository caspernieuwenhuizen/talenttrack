<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\PersonaDashboard\Widgets\HodWeekRecapWidget;

/**
 * #4843 — the head of development's week recap counts the trial decisions
 * made this week.
 *
 * It queried `decided_at`, a column `tt_trial_cases` does not have (the
 * column is `decision_made_at`), so the count was always missing and wpdb
 * logged an error that the widget titles test silenced.
 */
final class HodWeekRecapDecisionsTest extends WP_UnitTestCase {

    public function test_a_decision_made_this_week_is_counted(): void {
        global $wpdb;
        $club = (int) CurrentClub::id();
        $now  = gmdate( 'Y-m-d H:i:s' );

        foreach ( [ $now, gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ), null ] as $i => $decided ) {
            $wpdb->insert( "{$wpdb->prefix}tt_trial_cases", [
                'club_id'          => $club,
                'player_id'        => 9100 + $i,
                'track_id'         => 1,
                'start_date'       => gmdate( 'Y-m-d', time() - 40 * DAY_IN_SECONDS ),
                'end_date'         => gmdate( 'Y-m-d' ),
                'status'           => $decided === null ? 'open' : 'decided',
                'decision'         => $decided === null ? null : 'admit',
                'decision_made_at' => $decided,
                'created_by'       => 1,
            ] );
            $this->assertGreaterThan( 0, (int) $wpdb->insert_id, 'fixture: the trial case was written' );
        }

        $since = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
        $wpdb->last_error = '';
        $counts = $this->collect( $since, $club );

        $this->assertSame( '', $wpdb->last_error, 'the recap query raises no database error' );
        $this->assertSame( 1, $counts['trial_decisions'], 'only the decision made inside the window counts' );
    }

    /** @return array<string, int|null> */
    private function collect( string $since, int $club_id ): array {
        $method = new \ReflectionMethod( HodWeekRecapWidget::class, 'collect' );
        $method->setAccessible( true );
        return (array) $method->invoke( null, $since, $club_id );
    }
}
