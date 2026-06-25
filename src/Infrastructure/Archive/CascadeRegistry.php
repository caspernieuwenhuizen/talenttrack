<?php
namespace TT\Infrastructure\Archive;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CascadeRegistry (#1783) — declarative hard-delete plans for entities
 * that have an archive lifecycle but no bespoke cascade class.
 *
 * `player` and `person` keep their hand-written cascade services
 * (PlayerDeletionCascade / PersonDeletionCascade); they are NOT in this
 * registry. The entities here are driven generically by
 * GenericCascadeDeleter against the declaration below.
 *
 * Plan shape per entity:
 *   'table'        => the entity's own table (without prefix).
 *   'ref_columns'  => column names on OTHER tables that reference this
 *                     entity's id. The dependency scan finds every tt_*
 *                     table carrying one of these columns. Any referencing
 *                     table NOT listed under cascade/set_null BLOCKS the
 *                     delete (fail-closed).
 *   'cascade'      => [[bare_table, column], ...] owned children deleted
 *                     when the parent is deleted.
 *   'cascade_poly' => [[bare_table, type_col, id_col, type_value], ...]
 *                     polymorphic owned children (e.g. goal_links rows
 *                     whose link_type='evaluation'); not discoverable by
 *                     the ref_columns scan, so declared explicitly.
 *   'threads'      => thread_type value whose tt_thread_messages /
 *                     tt_thread_reads rows are owned by this entity, or null.
 *   'set_null'     => [[bare_table, column], ...] references cleared (not
 *                     deleted) — facts that outlive the record.
 *   'block_only'   => true to declare NO cascades (#1783 defers the full
 *                     cascades for team/activity — they BLOCK on any
 *                     dependent, which safely fixes the orphaning by
 *                     prevention until their plans are completed).
 */
final class CascadeRegistry {

    private const PLANS = [

        // Evaluation — owns its category ratings + any evidence links
        // pointing at it. A normal evaluation deletes cleanly; anything
        // else referencing it blocks.
        'evaluation' => [
            'table'        => 'tt_evaluations',
            'ref_columns'  => [ 'evaluation_id' ],
            'cascade'      => [ [ 'tt_eval_ratings', 'evaluation_id' ] ],
            'cascade_poly' => [ [ 'tt_goal_links', 'link_type', 'link_id', 'evaluation' ] ],
            'threads'      => null,
            'set_null'     => [],
            'block_only'   => false,
        ],

        // Goal — owns its links and its conversation thread. A workflow
        // task that spawned the goal is a fact: keep it, clear the link.
        'goal' => [
            'table'        => 'tt_goals',
            'ref_columns'  => [ 'goal_id', 'spawned_goal_id' ],
            'cascade'      => [ [ 'tt_goal_links', 'goal_id' ] ],
            'cascade_poly' => [],
            'threads'      => 'goal',
            'set_null'     => [ [ 'tt_workflow_tasks', 'spawned_goal_id' ] ],
            'block_only'   => false,
        ],

        // Tournament (#1784) — owns its matches, squad and per-match
        // assignments. Assignments hang off matches (no tournament_id of
        // their own), so they're a parent-keyed child removed first. A
        // linked activity outlives the tournament with its link cleared.
        'tournament' => [
            'table'        => 'tt_tournaments',
            'ref_columns'  => [ 'tournament_id' ],
            'children'     => [ [ 'tt_tournament_assignments', 'match_id', 'tt_tournament_matches', 'id', 'tournament_id' ] ],
            'cascade'      => [ [ 'tt_tournament_matches', 'tournament_id' ], [ 'tt_tournament_squad', 'tournament_id' ] ],
            'cascade_poly' => [],
            'threads'      => null,
            'set_null'     => [ [ 'tt_activities', 'tournament_id' ] ],
            'block_only'   => false,
        ],

        // Trial case (#1784) — owns its staff assignments, staff inputs
        // and extension audit trail. A workflow task or prospect that
        // points at the case is a fact: keep it, clear the link.
        'trial_case' => [
            'table'        => 'tt_trial_cases',
            'ref_columns'  => [ 'case_id', 'trial_case_id', 'promoted_to_trial_case_id' ],
            'cascade'      => [
                [ 'tt_trial_case_staff', 'case_id' ],
                [ 'tt_trial_case_staff_inputs', 'case_id' ],
                [ 'tt_trial_extensions', 'case_id' ],
            ],
            'cascade_poly' => [],
            'threads'      => null,
            'set_null'     => [
                [ 'tt_workflow_tasks', 'trial_case_id' ],
                [ 'tt_prospects', 'promoted_to_trial_case_id' ],
            ],
            'block_only'   => false,
        ],

        // Holiday (#1784) — standalone metadata; nothing references it,
        // so a permanent delete just removes the row.
        'holiday' => [
            'table'        => 'tt_holidays',
            'ref_columns'  => [],
            'cascade'      => [],
            'cascade_poly' => [],
            'threads'      => null,
            'set_null'     => [],
            'block_only'   => false,
        ],

        // Test training (#1784) — a workflow task that invited a player to
        // the session is a fact: keep it, clear the link.
        'test_training' => [
            'table'        => 'tt_test_trainings',
            'ref_columns'  => [ 'test_training_id' ],
            'cascade'      => [],
            'cascade_poly' => [],
            'threads'      => null,
            'set_null'     => [ [ 'tt_workflow_tasks', 'test_training_id' ] ],
            'block_only'   => false,
        ],

        // Trial track (#1784) — a template referenced by trial cases. A
        // track must NOT cascade-delete the cases that use it, so it blocks
        // while any trial case still references it (fail-closed).
        'trial_track' => [
            'table'        => 'tt_trial_tracks',
            'ref_columns'  => [ 'track_id' ],
            'cascade'      => [],
            'cascade_poly' => [],
            'threads'      => null,
            'set_null'     => [],
            'block_only'   => true,
        ],

        // VCT exercise (#1784) — owns its coaching points; a session block
        // that referenced it keeps existing with the exercise link cleared.
        // `exercise_id` is ambiguous (it also keys tt_exercises' children),
        // so the ref_columns are TABLE-QUALIFIED to the VCT tables only.
        'vct_exercise' => [
            'table'        => 'tt_vct_exercises',
            'ref_columns'  => [ [ 'tt_vct_coaching_points', 'exercise_id' ], [ 'tt_vct_session_blocks', 'exercise_id' ] ],
            'cascade'      => [ [ 'tt_vct_coaching_points', 'exercise_id' ] ],
            'cascade_poly' => [],
            'threads'      => null,
            'set_null'     => [ [ 'tt_vct_session_blocks', 'exercise_id' ] ],
            'block_only'   => false,
        ],

        // Custom widget (#1784) — standalone dashboard config; nothing
        // references it, so a permanent delete just removes the row.
        'custom_widget' => [
            'table'        => 'tt_custom_widgets',
            'ref_columns'  => [],
            'cascade'      => [],
            'cascade_poly' => [],
            'threads'      => null,
            'set_null'     => [],
            'block_only'   => false,
        ],

        // Injury (#1784) — a minor's medical record. Its journey-timeline
        // events are owned (polymorphic source_entity_type='injury') and
        // removed with it so a right-to-erasure delete actually erases.
        'injury' => [
            'table'        => 'tt_player_injuries',
            'ref_columns'  => [],
            'cascade'      => [],
            'cascade_poly' => [ [ 'tt_player_events', 'source_entity_type', 'source_entity_id', 'injury' ] ],
            'threads'      => null,
            'set_null'     => [],
            'block_only'   => false,
        ],

        // Scheduled report (#1808) — standalone recurring-export config;
        // nothing references it, so a permanent delete just removes the row.
        // (#1784 migration 0172 added archived_at/archived_by + backfilled
        // from the legacy status='archived' enum.)
        'scheduled_report' => [
            'table'        => 'tt_scheduled_reports',
            'ref_columns'  => [],
            'cascade'      => [],
            'cascade_poly' => [],
            'threads'      => null,
            'set_null'     => [],
            'block_only'   => false,
        ],

        // Team — deferred (#1784): block-only. A team carries player /
        // activity / match references (some denormalized); auto-cascading
        // those risks deleting player rows, so until the plan is verified
        // with a test harness, a team hard-delete simply refuses while
        // anything still references it.
        'team' => [
            'table'        => 'tt_teams',
            'ref_columns'  => [ 'team_id', 'player_team_id', 'activity_team_id', 'target_team_id', 'to_team_id' ],
            'cascade'      => [],
            'cascade_poly' => [],
            'threads'      => null,
            'set_null'     => [],
            'block_only'   => true,
        ],

        // Activity — deferred (#1784): block-only. Activities own
        // attendance / exercises / match rows; cascading them is left to
        // the follow-up (which also routes ActivitiesRestController's own
        // delete path through this framework).
        'activity' => [
            'table'        => 'tt_activities',
            // The legacy session-keyed FK is intentionally not listed here;
            // activity is block-only until #1784 builds its full cascade
            // (which discovers legacy columns from information_schema).
            'ref_columns'  => [ 'activity_id', 'related_activity_id', 'vct_session_id' ],
            'cascade'      => [],
            'cascade_poly' => [],
            'threads'      => null,
            'set_null'     => [],
            'block_only'   => true,
        ],

        // Measurement definition (#1856) — the schema a test hangs off.
        // Blocks while any session, result or target still references it;
        // the operator archives/deletes those first. Fail-closed.
        'measurement_definition' => [
            'table'        => 'tt_measurement_definitions',
            'ref_columns'  => [ 'definition_id' ],
            'cascade'      => [],
            'cascade_poly' => [],
            'threads'      => null,
            'set_null'     => [],
            'block_only'   => true,
        ],

        // Measurement session (#1856) — owns the results recorded against
        // it; deleting the session removes those values.
        'measurement_session' => [
            'table'        => 'tt_measurement_sessions',
            'ref_columns'  => [ 'measurement_session_id' ],
            'cascade'      => [ [ 'tt_measurement_results', 'measurement_session_id' ] ],
            'cascade_poly' => [],
            'threads'      => null,
            'set_null'     => [],
            'block_only'   => false,
        ],

        // Measurement target (#1856) — a per-age band; nothing references
        // it, deletes cleanly.
        'measurement_target' => [
            'table'        => 'tt_measurement_targets',
            'ref_columns'  => [ 'target_id' ],
            'cascade'      => [],
            'cascade_poly' => [],
            'threads'      => null,
            'set_null'     => [],
            'block_only'   => false,
        ],

        // Measurement result (#1856) — a leaf value, owns nothing.
        'measurement_result' => [
            'table'        => 'tt_measurement_results',
            'ref_columns'  => [ 'result_id' ],
            'cascade'      => [],
            'cascade_poly' => [],
            'threads'      => null,
            'set_null'     => [],
            'block_only'   => false,
        ],
    ];

    /** Whether this entity is driven by the generic deleter. */
    public static function has( string $entity ): bool {
        return isset( self::PLANS[ $entity ] );
    }

    /** @return array<string,mixed>|null */
    public static function plan( string $entity ): ?array {
        return self::PLANS[ $entity ] ?? null;
    }
}
