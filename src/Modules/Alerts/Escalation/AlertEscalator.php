<?php
namespace TT\Modules\Alerts\Escalation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Alerts\AlertRegistry;
use TT\Modules\Alerts\Contracts\EscalatingAlert;
use TT\Modules\Alerts\Policy\ClubAlertPolicy;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\WorkflowModule;

/**
 * AlertEscalator (#2635, epic #2629) — promotes a long-ignored alert into a
 * workflow task.
 *
 * The sentence the whole epic turns on: **an alert is ambient and
 * self-resolving; a task is assigned work with an owner and an audit trail.**
 * This class is where the first becomes the second, and it is deliberately
 * the only place that crosses between the two engines.
 *
 * ## Three rules, each protecting against a specific failure
 *
 * 1. **Once per occurrence, ever.** `escalated_task_id` is stamped on the
 *    row. Without it, an occurrence past its threshold would dispatch a
 *    fresh task on every hourly sweep, and one ignored alert would become a
 *    task queue nobody can clear.
 *
 * 2. **One-way.** Resolving the alert afterwards does not close the task.
 *    A task has an assignee and an audit trail; closing it behind their back
 *    breaks the thing tasks are for. The task's own form closes it. This
 *    asymmetry is the part people misremember, so it is stated here, in the
 *    interface, and in the docs.
 *
 * 3. **Bounded per run.** Shortening a club's threshold would otherwise
 *    escalate an entire backlog in one sweep — a hundred tasks landing at
 *    once because somebody moved a number from 14 to 7. The cap turns that
 *    into a gradual catch-up, and logs what it deferred.
 */
final class AlertEscalator {

    /**
     * Occurrences escalated per definition per run.
     *
     * Deliberately small. Escalation creates assigned work for a human, so
     * the cost of being slow is a task arriving an hour late; the cost of
     * being fast is somebody opening their inbox to fifty new tasks.
     */
    public const MAX_PER_RUN = 10;

    /** @var ClubAlertPolicy */
    private $policy;

    public function __construct( ?ClubAlertPolicy $policy = null ) {
        $this->policy = $policy ?? new ClubAlertPolicy();
    }

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_alert_occurrences';
    }

    /**
     * Escalate what is due across every registered definition that opts in.
     *
     * @return array<string,int> alert key => tasks created
     */
    public function runForCurrentClub(): array {
        $out = [];
        foreach ( AlertRegistry::all() as $key => $definition ) {
            if ( ! $definition instanceof EscalatingAlert ) continue;

            $spec = $definition->escalatesTo();
            if ( $spec === null ) continue;

            $template = (string) ( $spec['template_key'] ?? '' );
            if ( $template === '' ) continue;

            // Club policy wins over the definition's shipped default
            // (epic decision 13).
            $days = $this->policy->escalateAfterDays( $key );
            if ( $days === null ) $days = (int) ( $spec['after_days'] ?? 0 );
            if ( $days <= 0 ) continue;

            $created = $this->escalateDue( $key, $template, $days );
            if ( $created > 0 ) $out[ $key ] = $created;
        }
        return $out;
    }

    /**
     * Escalate one definition's overdue occurrences.
     *
     * Selection is on `first_seen_at`, not `last_seen_at`: the question is
     * "how long has this person had this problem", and `last_seen_at` moves
     * every hour the condition stays true, so it would never age past any
     * threshold at all.
     */
    private function escalateDue( string $alertKey, string $templateKey, int $days ): int {
        global $wpdb;
        $table  = $this->table();
        $cutoff = gmdate( 'Y-m-d H:i:s', (int) strtotime( "-{$days} days", current_time( 'timestamp' ) ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, recipient_user_id, subject_type, subject_id, player_id, payload_json
               FROM {$table}
              WHERE " . QueryHelpers::clubScopeWhere() . "
                AND alert_key = %s
                AND resolved_at IS NULL
                AND escalated_task_id IS NULL
                AND first_seen_at < %s
              ORDER BY first_seen_at ASC, id ASC
              LIMIT %d",
            $alertKey,
            $cutoff,
            self::MAX_PER_RUN + 1
        ) );
        $rows = is_array( $rows ) ? $rows : [];

        $deferred = count( $rows ) > self::MAX_PER_RUN;
        if ( $deferred ) {
            $rows = array_slice( $rows, 0, self::MAX_PER_RUN );
            error_log( sprintf(
                '[TalentTrack alerts] "%s" has more than %d occurrences due for escalation; escalating %d this run and the rest next. Usually means the club threshold was just shortened.',
                $alertKey,
                self::MAX_PER_RUN,
                self::MAX_PER_RUN
            ) );
        }

        $created = 0;
        foreach ( $rows as $row ) {
            $task_id = $this->dispatch( $templateKey, $row );
            if ( $task_id <= 0 ) continue;

            $wpdb->update(
                $table,
                [ 'escalated_task_id' => $task_id ],
                [ 'id' => (int) $row->id ],
                [ '%d' ],
                [ '%d' ]
            );
            $created++;
        }

        return $created;
    }

    /**
     * Dispatch one workflow task for an occurrence.
     *
     * Failure returns 0 and leaves `escalated_task_id` NULL, so the next
     * sweep tries again. Stamping regardless would mean a transient workflow
     * error silently costs the escalation forever — the alert stays open,
     * nobody is assigned it, and nothing ever says so.
     */
    private function dispatch( string $templateKey, object $row ): int {
        $payload = [];
        $raw     = (string) ( $row->payload_json ?? '' );
        if ( $raw !== '' ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) $payload = $decoded;
        }

        $context = new TaskContext(
            player_id:   isset( $row->player_id ) && $row->player_id !== null ? (int) $row->player_id : null,
            team_id:     isset( $payload['team_id'] ) ? (int) $payload['team_id'] : null,
            activity_id: (string) ( $row->subject_type ?? '' ) === 'activity' ? (int) $row->subject_id : null,
        );

        try {
            $task_ids = WorkflowModule::engine()->dispatch( $templateKey, $context );
        } catch ( \Throwable $e ) {
            error_log( sprintf(
                '[TalentTrack alerts] escalation of occurrence %d via template "%s" failed: %s',
                (int) $row->id,
                $templateKey,
                $e->getMessage()
            ) );
            return 0;
        }

        return ! empty( $task_ids ) ? (int) $task_ids[0] : 0;
    }
}
