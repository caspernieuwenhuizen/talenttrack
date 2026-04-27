<?php
namespace TT\Modules\Workflow\Forms;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Contracts\FormInterface;

/**
 * QuarterlyHoDReviewForm — live-data form. Renders an aggregate
 * snapshot of the past quarter at render time (evaluations done,
 * goals set, sessions logged, tasks completion rate per template) and
 * three free-text fields: what worked, what didn't, focus for next
 * quarter.
 *
 * Live-data means nothing is frozen on the task at trigger time — the
 * snapshot reflects the moment the HoD sits down to write the review,
 * which is exactly what they want.
 */
class QuarterlyHoDReviewForm implements FormInterface {

    public function render( array $task ): string {
        $existing = self::decodeResponse( $task );
        $stats = self::quarterlyStats();

        ob_start();
        ?>
        <div style="background:#fff; border:1px solid #e5e7ea; border-radius:8px; padding:16px;">
            <h3 style="margin: 0 0 12px; font-size: 16px;"><?php esc_html_e( 'Past quarter at a glance', 'talenttrack' ); ?></h3>
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 18px;">
                <tbody>
                    <tr>
                        <td style="padding: 6px 0; color:#5b6e75;"><?php esc_html_e( 'Evaluations recorded', 'talenttrack' ); ?></td>
                        <td style="padding: 6px 0; text-align: right; font-weight: 600;"><?php echo esc_html( (string) $stats['evaluations'] ); ?></td>
                    </tr>
                    <?php foreach ( $stats['by_type'] as $type_row ) : ?>
                        <tr>
                            <td style="padding: 6px 0; color:#5b6e75;">
                                <?php echo esc_html( sprintf(
                                    /* translators: %s: activity type label (e.g. Games / Trainings / Other) */
                                    __( '%s logged', 'talenttrack' ),
                                    $type_row['label']
                                ) ); ?>
                            </td>
                            <td style="padding: 6px 0; text-align: right; font-weight: 600;"><?php echo esc_html( (string) $type_row['count'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td style="padding: 6px 0; color:#5b6e75;"><?php esc_html_e( 'Goals set', 'talenttrack' ); ?></td>
                        <td style="padding: 6px 0; text-align: right; font-weight: 600;"><?php echo esc_html( (string) $stats['goals'] ); ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 6px 0; color:#5b6e75;"><?php esc_html_e( 'Tasks completed on time', 'talenttrack' ); ?></td>
                        <td style="padding: 6px 0; text-align: right; font-weight: 600;"><?php echo esc_html( $stats['tasks_label'] ); ?></td>
                    </tr>
                </tbody>
            </table>

            <p style="margin: 16px 0 6px;">
                <label for="tt-hod-worked" style="font-weight: 600;"><?php esc_html_e( 'What worked', 'talenttrack' ); ?></label>
            </p>
            <p>
                <textarea id="tt-hod-worked" name="what_worked" rows="3" style="width: 100%;"
                          <?php echo self::completedAttr( $task ); ?>><?php
                    echo esc_textarea( (string) ( $existing['what_worked'] ?? '' ) );
                ?></textarea>
            </p>

            <p style="margin: 16px 0 6px;">
                <label for="tt-hod-didnt" style="font-weight: 600;"><?php esc_html_e( 'What didn\'t', 'talenttrack' ); ?></label>
            </p>
            <p>
                <textarea id="tt-hod-didnt" name="what_didnt" rows="3" style="width: 100%;"
                          <?php echo self::completedAttr( $task ); ?>><?php
                    echo esc_textarea( (string) ( $existing['what_didnt'] ?? '' ) );
                ?></textarea>
            </p>

            <p style="margin: 16px 0 6px;">
                <label for="tt-hod-focus" style="font-weight: 600;"><?php esc_html_e( 'Focus for next quarter', 'talenttrack' ); ?></label>
            </p>
            <p>
                <textarea id="tt-hod-focus" name="focus_next" rows="3" style="width: 100%;"
                          <?php echo self::completedAttr( $task ); ?>><?php
                    echo esc_textarea( (string) ( $existing['focus_next'] ?? '' ) );
                ?></textarea>
            </p>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function validate( array $raw, array $task ): array {
        $errors = [];
        $any = trim( (string) ( $raw['what_worked'] ?? '' ) ) !== ''
            || trim( (string) ( $raw['what_didnt'] ?? '' ) ) !== ''
            || trim( (string) ( $raw['focus_next'] ?? '' ) ) !== '';
        if ( ! $any ) {
            $errors['__form'] = __( 'Please write something in at least one field before submitting.', 'talenttrack' );
        }
        return $errors;
    }

    public function serializeResponse( array $raw, array $task ): array {
        return [
            'what_worked' => sanitize_textarea_field( (string) ( $raw['what_worked'] ?? '' ) ),
            'what_didnt'  => sanitize_textarea_field( (string) ( $raw['what_didnt'] ?? '' ) ),
            'focus_next'  => sanitize_textarea_field( (string) ( $raw['focus_next'] ?? '' ) ),
            'snapshot'    => self::quarterlyStats(),
        ];
    }

    /**
     * Quarterly aggregates. Counts cover the 90 days preceding now.
     *
     * #0050: activity counts are now grouped by activity_type_key with
     * one row per active type. Order matches the lookup's sort_order so
     * the layout stays stable. Admin-added types appear automatically.
     *
     * @return array{
     *   evaluations:int, by_type: array<int, array{key:string, label:string, count:int}>,
     *   goals:int, tasks_completed:int, tasks_total:int, tasks_label:string
     * }
     */
    private static function quarterlyStats(): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $threshold = gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) );
        $threshold_date = gmdate( 'Y-m-d', time() - ( 90 * DAY_IN_SECONDS ) );

        $evaluations = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_evaluations WHERE archived_at IS NULL AND eval_date >= %s",
            $threshold_date
        ) );

        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT activity_type_key AS k, COUNT(*) AS c
               FROM {$p}tt_activities
              WHERE archived_at IS NULL AND session_date >= %s
              GROUP BY activity_type_key",
            $threshold_date
        ) );
        $counts_by_key = [];
        foreach ( $rows as $row ) {
            $counts_by_key[ (string) $row->k ] = (int) $row->c;
        }

        $by_type = [];
        foreach ( \TT\Infrastructure\Query\QueryHelpers::get_lookups( 'activity_type' ) as $type_row ) {
            $key = (string) $type_row->name;
            $by_type[] = [
                'key'   => $key,
                'label' => \TT\Infrastructure\Query\LookupTranslator::name( $type_row ),
                'count' => $counts_by_key[ $key ] ?? 0,
            ];
            unset( $counts_by_key[ $key ] );
        }
        // Defensive: if an activity row carries a key with no matching
        // lookup row (admin deleted the row despite the lock, or a stray
        // legacy value), surface it as a literal-key bucket so totals
        // still reconcile.
        foreach ( $counts_by_key as $orphan_key => $orphan_count ) {
            $by_type[] = [
                'key'   => $orphan_key,
                'label' => $orphan_key !== '' ? $orphan_key : __( '(unknown)', 'talenttrack' ),
                'count' => $orphan_count,
            ];
        }

        $goals = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_goals WHERE archived_at IS NULL AND created_at >= %s",
            $threshold
        ) );

        $tasks_table = $p . 'tt_workflow_tasks';
        $tasks_total = 0;
        $tasks_completed = 0;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tasks_table ) ) === $tasks_table ) {
            $tasks_total = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$tasks_table} WHERE created_at >= %s",
                $threshold
            ) );
            $tasks_completed = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$tasks_table}
                 WHERE created_at >= %s AND status = 'completed' AND completed_at IS NOT NULL AND completed_at <= due_at",
                $threshold
            ) );
        }
        $label = $tasks_total > 0
            ? sprintf( '%d / %d (%d%%)', $tasks_completed, $tasks_total, (int) round( ( $tasks_completed / $tasks_total ) * 100 ) )
            : __( 'no tasks yet', 'talenttrack' );

        return [
            'evaluations'     => $evaluations,
            'by_type'         => $by_type,
            'goals'           => $goals,
            'tasks_completed' => $tasks_completed,
            'tasks_total'     => $tasks_total,
            'tasks_label'     => $label,
        ];
    }

    /** @param array<string,mixed> $task */
    private static function decodeResponse( array $task ): array {
        $raw = (string) ( $task['response_json'] ?? '' );
        if ( $raw === '' ) return [];
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /** @param array<string,mixed> $task */
    private static function completedAttr( array $task ): string {
        return ( (string) ( $task['status'] ?? '' ) ) === 'completed' ? 'disabled' : '';
    }
}
