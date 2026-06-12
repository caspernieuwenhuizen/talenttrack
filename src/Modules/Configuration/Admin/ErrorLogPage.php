<?php
namespace TT\Modules\Configuration\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\ErrorLogRepository;
use TT\Infrastructure\Logging\Logger;

/**
 * ErrorLogPage — operator viewer for `tt_error_log` (#1360).
 *
 * Lists the most recent Logger error/warning entries with level + date
 * filters, so a head of academy can answer "why did saving fail for
 * coach X" without hosting-panel or SSH access to the PHP error log.
 *
 * Read-only — entries are written by `Logger` and pruned automatically
 * (the table is capped at ErrorLogRepository::MAX_ROWS rows). Gated by
 * `tt_view_audit_log`: the error log is the same kind of read-only
 * operator log surface as the audit log, just for failures instead of
 * domain events; minting a dedicated cap for it would add a matrix row
 * with the exact same holder set.
 */
class ErrorLogPage {

    private const PAGE_SIZE = 100;

    public static function render_page(): void {
        if ( ! current_user_can( 'tt_view_audit_log' ) && ! current_user_can( 'administrator' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }

        $level     = isset( $_GET['level'] ) ? sanitize_key( (string) wp_unslash( $_GET['level'] ) ) : '';
        $date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['date_from'] ) ) : '';
        $date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['date_to'] ) ) : '';

        $repo    = new ErrorLogRepository();
        $exists  = $repo->tableExists();
        $filters = [
            'level'     => $level,
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'limit'     => self::PAGE_SIZE,
        ];
        $rows  = $exists ? $repo->list( $filters ) : [];
        $total = $exists ? $repo->count( $filters ) : 0;

        $base_url      = admin_url( 'admin.php?page=tt-error-log' );
        $level_options = [
            ''                     => __( 'All levels', 'talenttrack' ),
            Logger::LEVEL_ERROR    => __( 'Errors only', 'talenttrack' ),
            Logger::LEVEL_WARNING  => __( 'Warnings only', 'talenttrack' ),
        ];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Error Log', 'talenttrack' ); ?></h1>
            <p class="description">
                <?php
                printf(
                    /* translators: %d: maximum number of retained log entries */
                    esc_html__( 'Recent plugin errors and warnings, captured at runtime. The newest %d entries are kept; older rows are pruned automatically.', 'talenttrack' ),
                    (int) ErrorLogRepository::MAX_ROWS
                );
                ?>
            </p>

            <?php if ( ! $exists ) : ?>
                <div class="notice notice-warning">
                    <p><?php esc_html_e( 'The error log table does not exist yet. Run the pending database migrations to create it.', 'talenttrack' ); ?></p>
                </div>
            <?php endif; ?>

            <form method="get" action="<?php echo esc_url( $base_url ); ?>" style="margin:8px 0 12px; font-size:13px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <input type="hidden" name="page" value="tt-error-log" />
                <label for="tt-error-log-level"><strong><?php esc_html_e( 'Level:', 'talenttrack' ); ?></strong></label>
                <select id="tt-error-log-level" name="level">
                    <?php foreach ( $level_options as $opt_key => $opt_label ) : ?>
                        <option value="<?php echo esc_attr( (string) $opt_key ); ?>" <?php selected( $level, $opt_key ); ?>><?php echo esc_html( (string) $opt_label ); ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="tt-error-log-from"><strong><?php esc_html_e( 'From:', 'talenttrack' ); ?></strong></label>
                <input type="date" id="tt-error-log-from" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" />
                <label for="tt-error-log-to"><strong><?php esc_html_e( 'To:', 'talenttrack' ); ?></strong></label>
                <input type="date" id="tt-error-log-to" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" />
                <button type="submit" class="button"><?php esc_html_e( 'Filter', 'talenttrack' ); ?></button>
            </form>

            <?php if ( $total > count( $rows ) ) : ?>
                <p class="description">
                    <?php
                    printf(
                        /* translators: 1: number of entries shown, 2: total matching entries */
                        esc_html__( 'Showing the newest %1$d of %2$d matching entries. Narrow the date range to see older ones.', 'talenttrack' ),
                        count( $rows ),
                        (int) $total
                    );
                    ?>
                </p>
            <?php endif; ?>

            <table class="widefat striped">
                <thead><tr>
                    <th style="width:160px;"><?php esc_html_e( 'Date', 'talenttrack' ); ?></th>
                    <th style="width:90px;"><?php esc_html_e( 'Level', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Message', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Context', 'talenttrack' ); ?></th>
                </tr></thead>
                <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="4"><?php esc_html_e( 'No log entries match the current filter.', 'talenttrack' ); ?></td></tr>
                <?php else : foreach ( $rows as $row ) :
                    $is_error = (string) $row->level === Logger::LEVEL_ERROR;
                    $context  = (string) ( $row->context ?? '' );
                    $decoded  = $context !== '' ? json_decode( $context, true ) : null;
                    $pretty   = is_array( $decoded )
                        ? (string) wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
                        : $context;
                    ?>
                    <tr>
                        <td><?php echo esc_html( (string) $row->created_at ); ?></td>
                        <td>
                            <span style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:12px;font-weight:600;<?php echo $is_error ? 'background:#fcebea;color:#b32d2e;' : 'background:#fcf9e8;color:#7c5a00;'; ?>">
                                <?php echo $is_error ? esc_html__( 'error', 'talenttrack' ) : esc_html__( 'warning', 'talenttrack' ); ?>
                            </span>
                        </td>
                        <td><code style="font-size:12px;"><?php echo esc_html( (string) $row->message ); ?></code></td>
                        <td>
                            <?php if ( $pretty !== '' ) : ?>
                                <details>
                                    <summary style="cursor:pointer;"><?php esc_html_e( 'Show details', 'talenttrack' ); ?></summary>
                                    <pre style="margin:6px 0 0;font-size:12px;white-space:pre-wrap;word-break:break-word;"><?php echo esc_html( $pretty ); ?></pre>
                                </details>
                            <?php else : ?>
                                <span style="color:#999;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
