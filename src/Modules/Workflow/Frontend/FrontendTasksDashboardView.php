<?php
namespace TT\Modules\Workflow\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Workflow\WorkflowModule;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendTasksDashboardView — HoD-tier overview of workflow activity.
 * Reachable at `?tt_view=tasks-dashboard`, gated by `tt_view_tasks_dashboard`.
 *
 * Three sections:
 *   1. Per-template totals: how many tasks created in the last 90 days,
 *      how many completed on time, completion rate.
 *   2. Per-coach completion rate: which coaches are keeping up with
 *      their tasks. Useful for the HoD's quarterly conversation.
 *   3. Currently-overdue tasks: a flat list with assignee + age, so the
 *      HoD can chase the right people without first hunting them down
 *      across team pages.
 */
class FrontendTasksDashboardView extends FrontendViewBase {

    public static function render( int $user_id ): void {
        $title = __( 'Tasks dashboard', 'talenttrack' );

        if ( ! current_user_can( 'tt_view_tasks_dashboard' ) ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'Your role does not have access to the tasks dashboard.', 'talenttrack' ) . '</p>';
            return;
        }
        self::enqueueAssets();
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( $title );
        self::renderHeader( $title );

        $per_template = self::perTemplateStats();
        $per_coach    = self::perCoachStats();
        $overdue      = self::overdueTasks();

        ?>
        <style>
            .tt-tdash-section { margin-bottom: 28px; }
            .tt-tdash-section h2 { font-size: 16px; margin: 0 0 10px; color: #1a1d21; }
            .tt-tdash-table { width: 100%; border-collapse: collapse; background:#fff; border:1px solid #e5e7ea; border-radius: 8px; overflow: hidden; }
            .tt-tdash-table th, .tt-tdash-table td { padding: 10px 12px; text-align: left; font-size: 13px; }
            .tt-tdash-table thead th { background: #f6f7f8; color: #5b6e75; font-weight: 600; border-bottom: 1px solid #e5e7ea; }
            .tt-tdash-table tbody tr + tr td { border-top: 1px solid #f1f3f4; }
            .tt-tdash-table .tt-num { text-align: right; font-variant-numeric: tabular-nums; }
            .tt-tdash-pct-good { color: #2c8a2c; font-weight: 600; }
            .tt-tdash-pct-mid { color: #c9962a; font-weight: 600; }
            .tt-tdash-pct-bad { color: #b32d2e; font-weight: 600; }
            .tt-tdash-empty { color: #5b6e75; font-style: italic; padding: 12px 0; }
        </style>

        <div class="tt-tdash-section">
            <h2><?php esc_html_e( 'By template — last 90 days', 'talenttrack' ); ?></h2>
            <?php if ( empty( $per_template ) ) : ?>
                <p class="tt-tdash-empty"><?php esc_html_e( 'No tasks created in the last 90 days.', 'talenttrack' ); ?></p>
            <?php else : ?>
                <table class="tt-tdash-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Template', 'talenttrack' ); ?></th>
                            <th class="tt-num"><?php esc_html_e( 'Created', 'talenttrack' ); ?></th>
                            <th class="tt-num"><?php esc_html_e( 'Completed on time', 'talenttrack' ); ?></th>
                            <th class="tt-num"><?php esc_html_e( 'Rate', 'talenttrack' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $per_template as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( $row['template_label'] ); ?></td>
                                <td class="tt-num"><?php echo esc_html( (string) $row['created'] ); ?></td>
                                <td class="tt-num"><?php echo esc_html( (string) $row['on_time'] ); ?></td>
                                <td class="tt-num <?php echo esc_attr( self::rateClass( $row['rate'] ) ); ?>">
                                    <?php echo esc_html( self::ratePct( $row['rate'] ) ); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="tt-tdash-section">
            <h2><?php esc_html_e( 'By coach — last 90 days', 'talenttrack' ); ?></h2>
            <?php if ( empty( $per_coach ) ) : ?>
                <p class="tt-tdash-empty"><?php esc_html_e( 'No coach-assigned tasks in the last 90 days.', 'talenttrack' ); ?></p>
            <?php else : ?>
                <table class="tt-tdash-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Coach', 'talenttrack' ); ?></th>
                            <th class="tt-num"><?php esc_html_e( 'Assigned', 'talenttrack' ); ?></th>
                            <th class="tt-num"><?php esc_html_e( 'Completed on time', 'talenttrack' ); ?></th>
                            <th class="tt-num"><?php esc_html_e( 'Rate', 'talenttrack' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $per_coach as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( $row['coach_name'] ); ?></td>
                                <td class="tt-num"><?php echo esc_html( (string) $row['assigned'] ); ?></td>
                                <td class="tt-num"><?php echo esc_html( (string) $row['on_time'] ); ?></td>
                                <td class="tt-num <?php echo esc_attr( self::rateClass( $row['rate'] ) ); ?>">
                                    <?php echo esc_html( self::ratePct( $row['rate'] ) ); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="tt-tdash-section">
            <h2><?php esc_html_e( 'Currently overdue', 'talenttrack' ); ?></h2>
            <?php if ( empty( $overdue ) ) : ?>
                <p class="tt-tdash-empty"><?php esc_html_e( 'No overdue tasks. Nicely done.', 'talenttrack' ); ?></p>
            <?php else : ?>
                <table class="tt-tdash-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Template', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Assignee', 'talenttrack' ); ?></th>
                            <th class="tt-num"><?php esc_html_e( 'Days overdue', 'talenttrack' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $overdue as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( $row['template_label'] ); ?></td>
                                <td><?php echo esc_html( $row['assignee_name'] ); ?></td>
                                <td class="tt-num"><?php echo esc_html( (string) $row['days'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @return array<int, array{template_key:string, template_label:string, created:int, on_time:int, rate:?float}>
     */
    private static function perTemplateStats(): array {
        global $wpdb;
        $threshold = gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT template_key,
                    COUNT(*) AS created,
                    SUM( CASE WHEN status = 'completed' AND completed_at IS NOT NULL AND completed_at <= due_at THEN 1 ELSE 0 END ) AS on_time
             FROM {$wpdb->prefix}tt_workflow_tasks
             WHERE created_at >= %s AND club_id = %d
             GROUP BY template_key
             ORDER BY created DESC",
            $threshold, CurrentClub::id()
        ), ARRAY_A );
        if ( ! is_array( $rows ) ) return [];

        $registry = WorkflowModule::registry();
        $out = [];
        foreach ( $rows as $r ) {
            $key = (string) $r['template_key'];
            $template = $registry->get( $key );
            $created = (int) $r['created'];
            $on_time = (int) $r['on_time'];
            $out[] = [
                'template_key'   => $key,
                'template_label' => $template ? $template->name() : $key,
                'created'        => $created,
                'on_time'        => $on_time,
                'rate'           => $created > 0 ? ( $on_time / $created ) : null,
            ];
        }
        return $out;
    }

    /**
     * @return array<int, array{coach_name:string, assigned:int, on_time:int, rate:?float}>
     */
    private static function perCoachStats(): array {
        global $wpdb;
        $threshold = gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.assignee_user_id,
                    COUNT(*) AS assigned,
                    SUM( CASE WHEN t.status = 'completed' AND t.completed_at IS NOT NULL AND t.completed_at <= t.due_at THEN 1 ELSE 0 END ) AS on_time
             FROM {$wpdb->prefix}tt_workflow_tasks t
             INNER JOIN {$wpdb->users} u ON t.assignee_user_id = u.ID
             INNER JOIN {$wpdb->usermeta} um ON um.user_id = u.ID AND um.meta_key = %s
             WHERE t.created_at >= %s
               AND t.club_id = %d
               AND ( um.meta_value LIKE %s OR um.meta_value LIKE %s )
             GROUP BY t.assignee_user_id
             ORDER BY assigned DESC",
            $wpdb->prefix . 'capabilities',
            $threshold,
            CurrentClub::id(),
            '%tt_coach%',
            '%tt_head_dev%'
        ), ARRAY_A );
        if ( ! is_array( $rows ) ) return [];

        $out = [];
        foreach ( $rows as $r ) {
            $user = get_userdata( (int) $r['assignee_user_id'] );
            $assigned = (int) $r['assigned'];
            $on_time  = (int) $r['on_time'];
            $out[] = [
                'coach_name' => $user ? $user->display_name : sprintf( __( 'User #%d', 'talenttrack' ), (int) $r['assignee_user_id'] ),
                'assigned'   => $assigned,
                'on_time'    => $on_time,
                'rate'       => $assigned > 0 ? ( $on_time / $assigned ) : null,
            ];
        }
        return $out;
    }

    /**
     * @return array<int, array{template_label:string, assignee_name:string, days:int}>
     */
    private static function overdueTasks(): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.template_key, t.assignee_user_id, t.due_at
             FROM {$wpdb->prefix}tt_workflow_tasks t
             WHERE t.status IN ('open','in_progress','overdue')
               AND t.due_at < UTC_TIMESTAMP()
               AND t.club_id = %d
             ORDER BY t.due_at ASC
             LIMIT 25",
            CurrentClub::id()
        ), ARRAY_A );
        if ( ! is_array( $rows ) ) return [];
        $registry = WorkflowModule::registry();
        $now = time();
        $out = [];
        foreach ( $rows as $r ) {
            $template = $registry->get( (string) $r['template_key'] );
            $user = get_userdata( (int) $r['assignee_user_id'] );
            $due_ts = strtotime( (string) $r['due_at'] );
            $days = $due_ts !== false ? (int) floor( ( $now - $due_ts ) / DAY_IN_SECONDS ) : 0;
            $out[] = [
                'template_label' => $template ? $template->name() : (string) $r['template_key'],
                'assignee_name'  => $user ? $user->display_name : sprintf( __( 'User #%d', 'talenttrack' ), (int) $r['assignee_user_id'] ),
                'days'           => max( 0, $days ),
            ];
        }
        return $out;
    }

    private static function rateClass( ?float $rate ): string {
        if ( $rate === null ) return '';
        if ( $rate >= 0.85 ) return 'tt-tdash-pct-good';
        if ( $rate >= 0.6 )  return 'tt-tdash-pct-mid';
        return 'tt-tdash-pct-bad';
    }

    private static function ratePct( ?float $rate ): string {
        if ( $rate === null ) return '—';
        return ( (int) round( $rate * 100 ) ) . '%';
    }
}
