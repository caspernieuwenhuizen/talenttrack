<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\AttendanceStatus;
use TT\Infrastructure\PlayerStatus\PlayerAttendanceCalculator;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\AttendanceFlagService;
use TT\Modules\Analytics\Reports\AttendanceRankingQuery;
use TT\Modules\Analytics\Reports\MinutesAuditQuery;
use TT\Modules\Configuration\LookupCanonicalSeeds;
use TT\Modules\Configuration\LookupTranslationSeeds;

/**
 * #4102 — Suspended is an attendance status, and it does not count against
 * the player's attendance score.
 *
 * A suspended player used to be recorded as a generic absence, which cost
 * them on the player status traffic light for something that is not an
 * attendance question.
 */
final class AttendanceSuspendedStatusTest extends WP_UnitTestCase {

    private string $p = '';
    private int $club = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
    }

    // ── the vocabulary ─────────────────────────────────────────────────

    public function test_suspended_is_a_canonical_status(): void {
        $this->assertSame( 'Suspended', AttendanceStatus::SUSPENDED );
        $this->assertContains( AttendanceStatus::SUSPENDED, AttendanceStatus::ALL );
        $this->assertTrue( AttendanceStatus::isValid( 'Suspended' ) );
        $this->assertSame( 'Suspended', AttendanceStatus::normalise( 'suspended' ) );
        $this->assertSame( 'Suspended', LabelTranslator::attendanceStatus( 'Suspended' ) );
    }

    public function test_the_seeds_carry_it_for_a_fresh_install(): void {
        $this->assertContains( 'Suspended', LookupCanonicalSeeds::canonicalFor( 'attendance_status' ) );

        $labels = LookupTranslationSeeds::map()['attendance_status']['Suspended'] ?? [];
        $this->assertSame( 'Geschorst', $labels['nl_NL'] ?? null );
        $this->assertSame( 'Suspendu', $labels['fr_FR'] ?? null );
        $this->assertSame( 'Gesperrt', $labels['de_DE'] ?? null );
        $this->assertSame( 'Sancionado', $labels['es_ES'] ?? null );
    }

    public function test_the_migration_seeds_the_lookup_once_with_its_dutch_label(): void {
        global $wpdb;
        $lookups = "{$this->p}tt_lookups";

        $existing = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$lookups} WHERE lookup_type = %s AND name = %s",
            'attendance_status', 'Suspended'
        ) );
        foreach ( (array) $existing as $id ) {
            $wpdb->delete( "{$this->p}tt_translations", [ 'entity_type' => 'lookup', 'entity_id' => (int) $id ] );
            $wpdb->delete( $lookups, [ 'id' => (int) $id ] );
        }

        $path = dirname( __DIR__, 2 ) . '/database/migrations/0294_seed_attendance_status_suspended.php';
        ( require $path )->up();
        ( require $path )->up();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, meta FROM {$lookups} WHERE lookup_type = %s AND name = %s AND COALESCE( club_id, 1 ) = %d",
            'attendance_status', 'Suspended', $this->club
        ) );
        $this->assertCount( 1, (array) $rows, 'idempotent: one row after two runs' );

        $meta = json_decode( (string) $rows[0]->meta, true );
        $this->assertSame( '#8a5300', is_array( $meta ) ? ( $meta['color'] ?? null ) : null );

        $dutch = $wpdb->get_var( $wpdb->prepare(
            "SELECT value FROM {$this->p}tt_translations
              WHERE entity_type = 'lookup' AND entity_id = %d AND field = 'name' AND locale = 'nl_NL'",
            (int) $rows[0]->id
        ) );
        $this->assertSame( 'Geschorst', $dutch );
    }

    // ── the attendance rule ────────────────────────────────────────────

    public function test_suspended_is_set_aside_like_injured_in_the_status_score(): void {
        $this->assertSame( 100.0, AttendanceFlagService::presentPctForStatus( 8, 10, 0, 0, 2 ) );
        $this->assertSame( 80.0, AttendanceFlagService::presentPct( 8, 10 ), 'every other surface keeps the full total' );
        $this->assertContains( 'suspended', AttendanceFlagService::MISSED_STATUSES );
        $this->assertTrue( AttendanceFlagService::isMissed( 'Suspended' ) );
        $this->assertSame( 3, AttendanceFlagService::missed( (object) [ 'absent' => 1, 'suspended' => 2 ] ) );
    }

    public function test_eight_present_and_two_suspended_scores_one_hundred(): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Suspended U15' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team,
            'first_name' => 'Red',
            'last_name'  => 'Card',
            'status'     => 'active',
        ] );
        $player = (int) $wpdb->insert_id;

        for ( $i = 1; $i <= 10; $i++ ) {
            $wpdb->insert( "{$this->p}tt_activities", [
                'club_id'             => $this->club,
                'team_id'             => $team,
                'title'               => 'Training ' . $i,
                'session_date'        => sprintf( '2020-02-%02d', $i ),
                'activity_type_key'   => 'training',
                'activity_status_key' => 'completed',
                'plan_state'          => 'completed',
            ] );
            $wpdb->insert( "{$this->p}tt_attendance", [
                'club_id'     => $this->club,
                'activity_id' => (int) $wpdb->insert_id,
                'player_id'   => $player,
                'status'      => $i <= 8 ? AttendanceStatus::PRESENT : AttendanceStatus::SUSPENDED,
                'is_guest'    => 0,
                'record_type' => 'actual',
            ] );
        }

        $score = ( new PlayerAttendanceCalculator() )->scoreFor( $player, '2020-01-01', '2020-12-31' );
        $this->assertSame( 10, $score['sessions'] );
        $this->assertSame( 2, $score['suspended'] );
        $this->assertSame( 100.0, $score['score'], 'a suspension does not cost the player' );

        $rows = ( new AttendanceRankingQuery() )->rows( '2020-01-01', '2020-12-31', $team );
        $this->assertCount( 1, $rows );
        $this->assertSame( 2, $rows[0]['suspended'], 'the reports get a Suspended count' );
        $this->assertSame( 80.0, $rows[0]['present_pct'], 'the reports keep the one rule' );
    }

    public function test_the_minutes_audit_reads_suspended_as_unavailable(): void {
        $this->assertTrue( MinutesAuditQuery::isMarkedUnavailable( AttendanceStatus::SUSPENDED, [ 'Present' ] ) );
        $this->assertTrue( MinutesAuditQuery::isMarkedUnavailable( null, [ AttendanceStatus::SUSPENDED ] ) );
    }
}
