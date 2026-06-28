<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Archive\AutoPurgeCron;
use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Audit\AuditService;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #2025 (epic #2018) — 30-day recycle-bin auto-purge.
 *
 * Security-critical: this is the unattended path that erases a minor's PII.
 * The tests run against the real schema (wp-env tests-cli) and assert every
 * safeguard the design review flagged:
 *
 *   #5 cascade eraser — a past-retention row is purged THROUGH the cascade,
 *      not a raw DELETE (asserted via the team cascade clearing team_id=0).
 *   blocked — a row the cascade fail-closes on is SKIPPED, left in the bin,
 *      counted, and the count is persisted for the bin view (never deleted).
 *   #8 per-club — a club-2 trashed row is NOT purged while sweeping club 1.
 *   #8 system actor — the audit row written by the unauthenticated sweep
 *      carries user_id = 0.
 *
 * Plus the retention boundary (a freshly-trashed row is NOT purged) and the
 * daily throttle (a second same-day sweep is a no-op).
 */
final class AutoPurgeCronTest extends WP_UnitTestCase {

    private string $p;
    private ArchiveRepository $repo;
    private ConfigService $config;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p      = $wpdb->prefix;
        $this->repo   = new ArchiveRepository();
        $this->config = new ConfigService();
        // Default to an admin so the seed helpers (which call the lifecycle
        // methods) pass the cap checks. The system-actor test logs OUT to
        // reproduce the real cron context.
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    // ---- cascade eraser + retention boundary ---------------------------

    public function test_a_past_retention_row_is_purged_via_the_cascade(): void {
        $team_id   = $this->insertTeam( 'Expired team' );
        $player_id = $this->insertPlayerOnTeam( 'Stays', $team_id );
        $this->binRow( 'tt_teams', $team_id, 40 ); // trashed 40 days ago > 30

        ( new AutoPurgeCron() )->runForCurrentClub();

        $this->assertNull( $this->row( 'tt_teams', $team_id ), 'the expired team is purged' );
        // The team cascade preserves the player (team_id reset to 0) — proves
        // the purge ran through GenericCascadeDeleter, not a bare row DELETE.
        $this->assertNotNull( $this->row( 'tt_players', $player_id ), 'the player survives the team purge' );
        $this->assertSame( 0, (int) $this->col( 'tt_players', 'team_id', $player_id ), 'player team_id reset to 0 by the cascade' );
    }

    public function test_a_freshly_trashed_row_is_not_purged(): void {
        $team_id = $this->insertTeam( 'Recent team' );
        $this->binRow( 'tt_teams', $team_id, 2 ); // trashed 2 days ago < 30

        $totals = ( new AutoPurgeCron() )->runForCurrentClub();

        $this->assertNotNull( $this->row( 'tt_teams', $team_id ), 'a row inside the retention window survives' );
        $this->assertSame( 0, $totals['purged'] );
    }

    // ---- blocked records are skipped + flagged, never deleted -----------

    public function test_a_blocked_row_is_skipped_counted_and_not_deleted(): void {
        // trial_track is block_only: it blocks on ANY referencing trial case.
        $track_id = $this->insertTrialTrack( 'Blocked template' );
        $this->insertTrialCaseOnTrack( $track_id ); // undeclared dependent
        $this->binRow( 'tt_trial_tracks', $track_id, 40 );

        $totals = ( new AutoPurgeCron() )->runForCurrentClub();

        $this->assertNotNull(
            $this->row( 'tt_trial_tracks', $track_id ),
            'a cascade-blocked row stays in the bin — never force-deleted'
        );
        $this->assertGreaterThanOrEqual( 1, $totals['blocked'], 'the blocked row is counted' );
        // The per-club blocked count is persisted for the bin view notice.
        $this->assertGreaterThanOrEqual(
            1,
            $this->config->getInt( AutoPurgeCron::BLOCKED_COUNT_CONFIG_KEY, 0 ),
            'blocked count is written to tt_config for FrontendRecycleBinView'
        );
    }

    // ---- per-club scoping (#8) -----------------------------------------

    public function test_a_foreign_club_row_is_not_purged_when_sweeping_club_one(): void {
        // A club-2 row, expired, inserted pre-trashed.
        $foreign = $this->insertTeam( 'Club 2 expired', 2 );
        $this->binRow( 'tt_teams', $foreign, 40 );

        // runForCurrentClub() runs under CurrentClub::id() === 1 (no filter).
        $this->assertSame( 1, CurrentClub::id(), 'guard: current club is 1' );
        ( new AutoPurgeCron() )->runForCurrentClub();

        $this->assertNotNull(
            $this->row( 'tt_teams', $foreign ),
            'a club_id=2 row is never purged under club 1\'s sweep'
        );
    }

    // ---- system actor (#8): audit row user_id = 0 ----------------------

    public function test_the_sweep_writes_a_purged_audit_row_with_system_actor(): void {
        $team_id = $this->insertTeam( 'Audited expired' );
        $this->binRow( 'tt_teams', $team_id, 40 );

        // Reproduce the real cron context: no logged-in user. AuditService
        // stamps user_id from get_current_user_id(), so the system actor
        // must be 0 — the audit viewer can't imply a human pressed delete.
        wp_set_current_user( 0 );

        $audit        = new AuditService();
        $before_total = $audit->count( [ 'action' => 'team.purged' ] );
        $before_sys   = $this->countSystemPurges();
        ( new AutoPurgeCron() )->runForCurrentClub();

        $this->assertSame( $before_total + 1, $audit->count( [ 'action' => 'team.purged' ] ), 'the sweep writes team.purged' );
        $this->assertSame(
            $before_sys + 1,
            $this->countSystemPurges(),
            'the purged audit row carries the system actor (user_id = 0)'
        );
    }

    // ---- daily throttle -------------------------------------------------

    public function test_maybeRun_only_sweeps_once_per_day(): void {
        $first  = $this->insertTeam( 'First expired' );
        $this->binRow( 'tt_teams', $first, 40 );

        $cron = new AutoPurgeCron();
        $cron->maybeRun();
        $this->assertNull( $this->row( 'tt_teams', $first ), 'first run purges' );

        // A row expired AFTER the day's run should NOT be touched until tomorrow.
        $second = $this->insertTeam( 'Second expired' );
        $this->binRow( 'tt_teams', $second, 40 );
        $cron->maybeRun();
        $this->assertNotNull(
            $this->row( 'tt_teams', $second ),
            'a second same-day maybeRun() is a no-op (daily throttle)'
        );
    }

    // ---- helpers --------------------------------------------------------

    /**
     * Count team.purged audit rows carrying the system actor (user_id = 0).
     * AuditService::count() treats a user_id filter of 0 as "no filter"
     * (empty check), so query the table directly to assert the actor.
     */
    private function countSystemPurges(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->p}tt_audit_log WHERE action = 'team.purged' AND user_id = 0"
        );
    }

    private function insertTeam( string $name, int $club = null ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [
            'club_id' => $club ?? CurrentClub::id(),
            'name'    => $name,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayerOnTeam( string $name, int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => CurrentClub::id(),
            'first_name' => $name,
            'last_name'  => 'Player',
            'team_id'    => $team_id,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertTrialTrack( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_trial_tracks", [
            'club_id' => CurrentClub::id(),
            'slug'    => 'blocked-' . uniqid(),
            'name'    => $name,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertTrialCaseOnTrack( int $track_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_trial_cases", [
            'club_id'    => CurrentClub::id(),
            'player_id'  => 0,
            'track_id'   => $track_id,
            'start_date' => '2026-01-01',
            'end_date'   => '2026-02-01',
            'created_by' => get_current_user_id(),
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * Stamp a row archived + trashed, with trashed_at set $days_ago days in
     * the past so the retention cutoff catches (or doesn't catch) it.
     */
    private function binRow( string $table, int $id, int $days_ago ): void {
        global $wpdb;
        $when = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp', true ) - $days_ago * DAY_IN_SECONDS );
        $wpdb->update( "{$this->p}{$table}", [
            'archived_at' => $when,
            'archived_by' => get_current_user_id(),
            'trashed_at'  => $when,
            'trashed_by'  => get_current_user_id(),
        ], [ 'id' => $id ] );
    }

    /** @return string|null */
    private function col( string $table, string $column, int $id ) {
        global $wpdb;
        $v = $wpdb->get_var( $wpdb->prepare(
            "SELECT {$column} FROM {$this->p}{$table} WHERE id = %d", $id
        ) );
        return $v === null ? null : (string) $v;
    }

    private function row( string $table, int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$this->p}{$table} WHERE id = %d", $id
        ) );
    }
}
