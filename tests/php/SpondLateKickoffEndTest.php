<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Activities\Services\ActivityTimeWindow;
use TT\Modules\MatchPrep\Services\MatchLengthResolver;
use TT\Modules\Spond\CredentialsManager;
use TT\Modules\Spond\SpondSync;

/**
 * #4529 — a Spond match with no end time gets kick-off + 105 minutes, and
 * a late kick-off wraps past midnight instead of stopping at 23:59. The
 * planner prints count an end of exactly 00:00 as midnight.
 *
 * The import runs end to end, with the Spond login and feed answered
 * through `pre_http_request`.
 */
final class SpondLateKickoffEndTest extends WP_UnitTestCase {

    private const UID = 'spond-ev-4529';

    /** @var int */
    private $team = 0;

    /** @var string */
    private $day = '';

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $wpdb->hide_errors();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [
            'club_id'        => 1,
            'name'           => 'JO19-1',
            'spond_group_id' => 'grp-4529',
        ] );
        $this->team = (int) $wpdb->insert_id;
        CredentialsManager::save( 'coach@example.test', 'secret' );
        $this->day = gmdate( 'Y-m-d', time() + 10 * DAY_IN_SECONDS );
        add_filter( 'pre_http_request', [ $this, 'fakeSpond' ], 10, 3 );
    }

    public function tear_down(): void {
        remove_filter( 'pre_http_request', [ $this, 'fakeSpond' ], 10 );
        parent::tear_down();
    }

    /**
     * @param mixed               $pre
     * @param array<string,mixed> $args
     * @return mixed
     */
    public function fakeSpond( $pre, $args, string $url ) {
        if ( strpos( $url, '/auth2/login' ) !== false ) {
            return $this->response( [ 'accessToken' => [ 'token' => 'test-token' ] ] );
        }
        if ( strpos( $url, '/sponds/' ) !== false ) {
            // A match with a kick-off and no end, as Spond often sends one.
            return $this->response( [ [
                'id'             => self::UID,
                'heading'        => 'JO19-1 - vv Hedel',
                'startTimestamp' => $this->day . 'T22:30:00.000Z',
                'matchEvent'     => true,
            ] ] );
        }
        return $pre;
    }

    public function test_a_late_kickoff_without_an_end_is_stored_past_midnight(): void {
        $this->assertSame( 'ok', SpondSync::syncTeam( $this->team )['status'] ?? '' );

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT start_time, end_time FROM {$wpdb->prefix}tt_activities WHERE external_id = %s",
            self::UID
        ) );
        $this->assertNotNull( $row, 'the match was imported' );

        $start = (string) $row->start_time;
        $end   = (string) $row->end_time;
        $this->assertSame( '00:15:00', $end, 'kick-off + 105 minutes, wrapped past midnight' );
        $this->assertSame( 105, ActivityTimeWindow::minutes( $start, $end ), 'the duration reads 105 minutes' );
        $this->assertSame( 105, MatchLengthResolver::scheduledMinutes( $start, $end ) );
    }

    public function test_both_planner_prints_count_a_midnight_end(): void {
        foreach ( [ \TT\Modules\Export\Exporters\TeamPlanningPdfExporter::class, \TT\Modules\Planning\Print\TeamPlannerWeeklyPrintable::class ] as $class ) {
            $label = new \ReflectionMethod( $class, 'durationLabel' );
            $label->setAccessible( true );
            $this->assertSame( '1h', $label->invoke( null, '23:00:00', '00:00:00' ), $class . ' counts an end at exactly midnight' );
            $this->assertSame( '1h45', $label->invoke( null, '22:30:00', '00:15:00' ), $class . ' counts the wrapped Spond default' );
            $this->assertSame( '', $label->invoke( null, '00:00:00', '01:00:00' ), $class . ' still reads a 00:00 start as no time' );
        }
    }

    public function test_the_planner_pdf_range_shows_a_midnight_end(): void {
        $this->assertRanges( \TT\Modules\Export\Exporters\TeamPlanningPdfExporter::class );
    }

    public function test_the_weekly_planner_print_range_shows_a_midnight_end(): void {
        $this->assertRanges( \TT\Modules\Planning\Print\TeamPlannerWeeklyPrintable::class );
    }

    private function assertRanges( string $class ): void {
        $range = new \ReflectionMethod( $class, 'timeRange' );
        $range->setAccessible( true );
        $label = new \ReflectionMethod( $class, 'durationLabel' );
        $label->setAccessible( true );

        $this->assertSame( '23:00–00:00', $range->invoke( null, '23:00:00', '00:00:00', '' ), 'the range keeps a midnight end' );
        $this->assertSame( '1h', $label->invoke( null, '23:00:00', '00:00:00' ), 'next to its one-hour duration' );
        $this->assertSame( '18:30–20:00', $range->invoke( null, '18:30:00', '20:00:00', '' ), 'a same-day range is unchanged' );
        $this->assertSame( '', $range->invoke( null, '00:00:00', '01:00:00', '' ), 'a 00:00 start is still no time' );
        $this->assertSame( '20:00', $range->invoke( null, '', '', '20:00:00' ), 'the kick-off still stands in for a missing start' );
    }

    /**
     * @param mixed $body
     * @return array<string,mixed>
     */
    private function response( $body ): array {
        return [
            'headers'  => [],
            'body'     => (string) wp_json_encode( $body ),
            'response' => [ 'code' => 200, 'message' => 'OK' ],
            'cookies'  => [],
            'filename' => null,
        ];
    }
}
