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
