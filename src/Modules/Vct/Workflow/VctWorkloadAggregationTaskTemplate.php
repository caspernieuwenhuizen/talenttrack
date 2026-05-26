<?php
namespace TT\Modules\Vct\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Vct\Repositories\VctAgeProfilesRepository;
use TT\Modules\Vct\Repositories\VctSessionBlocksRepository;
use TT\Modules\Vct\Repositories\VctSessionsRepository;
use TT\Modules\Vct\Repositories\VctWorkloadSnapshotsRepository;
use TT\Modules\Vct\Services\WorkloadCalculator;
use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\Forms\GoalSettingForm;
use TT\Modules\Workflow\Resolvers\LambdaResolver;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\TaskTemplate;

/**
 * VctWorkloadAggregationTaskTemplate (#0095 VCT-7 / #912).
 *
 * Server-side aggregation job — NOT a user-facing task. The Workflow
 * module's TaskTemplate contract is the SaaS-port chokepoint per
 * spec § Decisions log #1 (one scheduler abstraction; no
 * `wp_schedule_event` direct registrations). The template runs the
 * aggregation inside `expandTrigger()` and returns an empty array so
 * the engine creates zero user tasks.
 *
 * Cadence: daily at 02:00 (defaultSchedule returns the cron
 * expression `0 2 * * *`). The matching `tt_workflow_triggers` row
 * lands in migration 0127.
 *
 * What `expandTrigger()` does:
 *   1. Walk every `tt_vct_sessions` row with `status = 'completed'`
 *      in the trailing 28-day window.
 *   2. For each session, distribute the block-level load
 *      contributions to attending players via `tt_attendance`
 *      (Present = full credit; Absent / Excused / Injured = no
 *      contribution).
 *   3. Per (player_id, snapshot_date), recompute 24h / 7d / 28d
 *      rolling loads + ACWR (acute_7d / chronic_28d/4).
 *   4. Set `flag`:
 *        - `over_envelope` when 7d load exceeds the age profile's
 *          weekly envelope.
 *        - `acwr_high` when ACWR > 1.5 (or `acwr_low` when < 0.8).
 *   5. Upsert `tt_vct_workload_snapshots` via INSERT ... ON
 *      DUPLICATE KEY UPDATE — re-runs are idempotent and missed
 *      nights self-repair on the next 28-day pass.
 *
 * Per-player attendance attribution is the meaningful-load
 * guarantee per spec § Background work: a player who didn't attend
 * doesn't get the load contribution.
 */
class VctWorkloadAggregationTaskTemplate extends TaskTemplate {

    public const KEY = 'vct_workload_aggregation';

    public function key(): string { return self::KEY; }

    public function name(): string {
        return __( 'VCT workload aggregation', 'talenttrack' );
    }

    public function description(): string {
        return __(
            'Nightly server-side job that aggregates per-player VCT workload from completed VCT trainings over the trailing 28 days. Writes one snapshot per (player, date) into tt_vct_workload_snapshots. Creates no user tasks.',
            'talenttrack'
        );
    }

    public function defaultSchedule(): array {
        return [ 'type' => 'cron', 'expression' => '0 2 * * *' ];
    }

    public function defaultDeadlineOffset(): string {
        // Aggregation job creates no tasks; deadline is irrelevant.
        return '+1 day';
    }

    public function defaultAssignee(): AssigneeResolver {
        // No assignees; expandTrigger returns an empty list so the
        // resolver is never called. LambdaResolver-returning-empty
        // makes the contract explicit.
        return new LambdaResolver( static function ( TaskContext $ctx ): array {
            return [];
        } );
    }

    public function formClass(): string {
        // Never consumed at runtime — no tasks → no form ever rendered.
        // GoalSettingForm is a stable existing implementation we can
        // reference without inventing a stub class.
        return GoalSettingForm::class;
    }

    public function entityLinks(): array { return []; }

    /**
     * The aggregation runs here. Returns [] so the engine's task-create
     * loop is a no-op. The CronDispatcher's TaskContext argument is
     * ignored — we operate club-wide via CurrentClub::id() per the
     * codebase's tenancy convention.
     *
     * @return list<TaskContext>
     */
    public function expandTrigger( TaskContext $context ): array {
        $this->aggregate();
        return [];
    }

    /**
     * The actual work. Public so the trigger can also be called
     * imperatively from a debug surface or one-off backfill.
     */
    public function aggregate(): void {
        global $wpdb;
        $club_id = CurrentClub::id();

        $today        = current_time( 'Y-m-d' );
        $window_start = gmdate( 'Y-m-d', strtotime( '-28 days', strtotime( $today ) ) );

        $sessions_repo   = new VctSessionsRepository();
        $blocks_repo     = new VctSessionBlocksRepository();
        $snapshots_repo  = new VctWorkloadSnapshotsRepository();
        $age_profiles    = new VctAgeProfilesRepository();
        $calculator      = new WorkloadCalculator();

        // Collect every completed VCT training in the trailing window.
        $vct_sessions_table = $wpdb->prefix . 'tt_vct_sessions';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, team_id, activity_id, session_date, age_group, total_load
               FROM {$vct_sessions_table}
              WHERE club_id = %d
                AND status = 'completed'
                AND session_date BETWEEN %s AND %s",
            $club_id, $window_start, $today
        ), ARRAY_A );
        if ( ! is_array( $rows ) || empty( $rows ) ) return;

        // Per-player per-date load contributions. Keyed by
        // [player_id][date] => accumulated load.
        $per_player_by_date = [];

        $attendance_table = $wpdb->prefix . 'tt_attendance';

        foreach ( $rows as $vct ) {
            $vct_id      = (int)    $vct['id'];
            $activity_id = isset( $vct['activity_id'] ) ? (int) $vct['activity_id'] : 0;
            $date        = (string) $vct['session_date'];
            $blocks      = $blocks_repo->listForSession( $vct_id );
            $session_load = $calculator->sessionLoad( $blocks );
            if ( $session_load <= 0 ) continue;

            $present_player_ids = [];
            if ( $activity_id > 0 ) {
                $present_player_ids = (array) $wpdb->get_col( $wpdb->prepare(
                    "SELECT player_id FROM {$attendance_table}
                      WHERE club_id = %d
                        AND activity_id = %d
                        AND status = 'Present'",
                    $club_id, $activity_id
                ) );
            }

            foreach ( $present_player_ids as $pid ) {
                $pid = (int) $pid;
                if ( $pid <= 0 ) continue;
                $per_player_by_date[ $pid ][ $date ] = ( $per_player_by_date[ $pid ][ $date ] ?? 0 ) + $session_load;
            }
        }

        // For each player, compute rolling loads + ACWR + flag and upsert.
        foreach ( $per_player_by_date as $player_id => $dates ) {
            // Resolve player's team + age_group for the envelope check.
            $player_meta = $wpdb->get_row( $wpdb->prepare(
                "SELECT team_id FROM {$wpdb->prefix}tt_players WHERE id = %d AND club_id = %d LIMIT 1",
                $player_id, $club_id
            ), ARRAY_A );
            $team_id = is_array( $player_meta ) ? (int) ( $player_meta['team_id'] ?? 0 ) : 0;

            $age_group = $this->ageGroupForTeam( $team_id );
            $profile   = $age_group !== null ? $age_profiles->findByAgeGroup( $age_group ) : null;
            $envelope  = $profile !== null ? (int) $profile['weekly_load_envelope'] : 0;

            // Build the entry list for the calculator's rolling helpers.
            $entries = [];
            foreach ( $dates as $d => $load ) {
                $entries[] = [ 'date' => (string) $d, 'load' => (int) $load ];
            }

            foreach ( $dates as $snapshot_date => $load_24h ) {
                $load_7d  = $calculator->rollingLoad( $entries, $snapshot_date, 7 );
                $load_28d = $calculator->rollingLoad( $entries, $snapshot_date, 28 );

                // Chronic-load denominator is the 28-day average per week
                // (= 28-day total / 4) per spec § dispatch step 3.
                $chronic_weekly = (int) round( $load_28d / 4 );
                $acwr = $calculator->acwr( $load_7d, $chronic_weekly );

                $flag = $calculator->flagForAcwr( $acwr );
                if ( $flag === null && $envelope > 0 && $load_7d > $envelope ) {
                    $flag = 'over_envelope';
                }

                $snapshots_repo->upsert(
                    (int) $player_id,
                    (string) $snapshot_date,
                    (int) $load_24h,
                    $load_7d,
                    $load_28d,
                    $acwr,
                    $flag
                );
            }
        }
    }

    /**
     * Resolve the age group for a team via the team's age_group column.
     * Returns the canonical age_group string (e.g. 'U13') or null.
     */
    private function ageGroupForTeam( int $team_id ): ?string {
        if ( $team_id <= 0 ) return null;
        global $wpdb;
        $tag = $wpdb->get_var( $wpdb->prepare(
            "SELECT age_group FROM {$wpdb->prefix}tt_teams WHERE id = %d AND club_id = %d LIMIT 1",
            $team_id, CurrentClub::id()
        ) );
        return $tag !== null && $tag !== '' ? (string) $tag : null;
    }
}
