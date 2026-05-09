<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Kernel;
use TT\Infrastructure\Audit\AuditService;
use TT\Infrastructure\FeatureToggles\FeatureToggleService;

/**
 * FrontendAuditLogView — read-only audit-log browser (#0021).
 *
 * Server-rendered (no FrontendListTable / REST hydration). The audit
 * table is read-only by design and the dataset is small enough that
 * a plain server-side render with form-based filters is the right
 * shape — no extra REST surface to register, no JS coupling, no
 * need to add a new permission_callback.
 *
 * Capability: tt_view_settings. The wp-admin tab in
 * ConfigurationPage::tab_audit() inherits the page-level
 * tt_edit_settings; that's the right gate for the wp-admin path
 * (which permits writes elsewhere on the page) but for this
 * read-only frontend view, view-only is enough.
 *
 * Filters: action, entity_type (dropdowns of distinct values),
 * user_id (numeric — same shape as the wp-admin tab), date_from,
 * date_to. All optional. Pagination via `apage` (audit-log page) so
 * we don't collide with WordPress's reserved `paged` param.
 */
class FrontendAuditLogView extends FrontendViewBase {

    private const CAP       = 'tt_view_settings';
    private const PER_PAGE  = 50;

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( self::CAP ) ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view this section.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Audit log', 'talenttrack' ) );
        self::renderHeader( __( 'Audit log', 'talenttrack' ) );

        /** @var AuditService $audit */
        $audit = Kernel::instance()->container()->get( 'audit' );
        /** @var FeatureToggleService $toggles */
        $toggles = Kernel::instance()->container()->get( 'toggles' );

        if ( ! $toggles->isEnabled( 'audit_log' ) ) {
            ?>
            <div class="tt-flash tt-flash-warning" style="margin-bottom: var(--tt-sp-4);">
                <span style="flex:1;">
                    <strong><?php esc_html_e( 'Audit logging is disabled.', 'talenttrack' ); ?></strong>
                    <?php esc_html_e( 'Enable it under Configuration → Feature Toggles to start recording entries.', 'talenttrack' ); ?>
                </span>
            </div>
            <?php
        }

        // #0086 Workstream B Child 3 — tab switcher between the
        // generic audit table and the failed-logins aggregate view.
        // The aggregate view is `?tab=failed-logins`; everything else
        // falls through to the existing entry browser.
        $tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : '';
        self::renderTabs( $tab );

        if ( $tab === 'failed-logins' ) {
            self::renderFailedLoginsTab();
            return;
        }

        $filters = self::filtersFromQuery();
        $page    = isset( $_GET['apage'] ) ? max( 1, absint( $_GET['apage'] ) ) : 1;
        $offset  = ( $page - 1 ) * self::PER_PAGE;

        $entries_filters = $filters + [ 'offset' => $offset ];
        $entries = $audit->recent( self::PER_PAGE, $entries_filters );
        $total   = $audit->count( $filters );

        $actions      = $audit->distinctValues( 'action' );
        $entity_types = $audit->distinctValues( 'entity_type' );

        self::renderFilterForm( $filters, $actions, $entity_types );
        self::renderSummary( $total, $page, $filters );
        self::renderTable( $entries );
        self::renderPagination( $total, $page, $filters );
    }

    private static function renderTabs( string $active ): void {
        $base = self::baseUrl();
        $view = isset( $_GET['tt_view'] ) ? sanitize_key( (string) $_GET['tt_view'] ) : '';
        $entries_url = $view !== '' ? add_query_arg( 'tt_view', $view, $base ) : $base;
        $failed_url  = add_query_arg( 'tab', 'failed-logins', $entries_url );

        $entries_active = $active !== 'failed-logins';
        $failed_active  = $active === 'failed-logins';

        ?>
        <nav class="tt-audit-tabs" style="display:flex; gap:8px; margin-bottom: var(--tt-sp-3, 12px); border-bottom: 1px solid var(--tt-line, #e0e0e0);">
            <a href="<?php echo esc_url( $entries_url ); ?>" class="tt-audit-tab<?php echo $entries_active ? ' is-active' : ''; ?>" style="padding: 10px 14px; text-decoration: none; color: <?php echo $entries_active ? 'var(--tt-ink, #1a1a1a)' : 'var(--tt-muted, #6a6d66)'; ?>; border-bottom: 2px solid <?php echo $entries_active ? 'var(--tt-accent, #5b8def)' : 'transparent'; ?>; font-weight: <?php echo $entries_active ? '600' : '400'; ?>; min-height: 48px; display: inline-flex; align-items: center;">
                <?php esc_html_e( 'All entries', 'talenttrack' ); ?>
            </a>
            <a href="<?php echo esc_url( $failed_url ); ?>" class="tt-audit-tab<?php echo $failed_active ? ' is-active' : ''; ?>" style="padding: 10px 14px; text-decoration: none; color: <?php echo $failed_active ? 'var(--tt-ink, #1a1a1a)' : 'var(--tt-muted, #6a6d66)'; ?>; border-bottom: 2px solid <?php echo $failed_active ? 'var(--tt-accent, #5b8def)' : 'transparent'; ?>; font-weight: <?php echo $failed_active ? '600' : '400'; ?>; min-height: 48px; display: inline-flex; align-items: center;">
                <?php esc_html_e( 'Failed logins', 'talenttrack' ); ?>
            </a>
        </nav>
        <?php
    }

    /**
     * #0086 Workstream B Child 3 — failed-logins aggregate view.
     *
     * Renders four panels: 30-day total, daily breakdown for the last
     * 30 days, top-10 attempted usernames in the window, top-10 source
     * IPs in the window. No automatic lockout — visibility only per
     * spec ("lockout becomes a v2 enhancement once we see real volume").
     */
    private static function renderFailedLoginsTab(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_audit_log';

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        if ( ! $exists ) {
            echo '<p class="tt-notice">' . esc_html__( 'Audit log table not found. Run migrations to enable failed-login tracking.', 'talenttrack' ) . '</p>';
            return;
        }

        $club_id = \TT\Infrastructure\Tenancy\CurrentClub::id();
        $since   = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );

        // Total volume in 30-day window.
        $total_30d = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE action = %s AND club_id = %d AND created_at >= %s",
            'login_fail', $club_id, $since
        ) );

        // Volume in 7-day window — surfaces a recent uptick.
        $since_7d = gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) );
        $total_7d = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE action = %s AND club_id = %d AND created_at >= %s",
            'login_fail', $club_id, $since_7d
        ) );

        // Daily breakdown — last 30 days.
        $daily = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(created_at) AS day, COUNT(*) AS n
             FROM $table
             WHERE action = %s AND club_id = %d AND created_at >= %s
             GROUP BY DATE(created_at)
             ORDER BY day DESC",
            'login_fail', $club_id, $since
        ) );

        // Top-10 attempted usernames — extracted from the payload JSON.
        // JSON_EXTRACT works on MySQL 5.7+ and MariaDB 10.2.3+ which is
        // a higher floor than WordPress's official support, but this is
        // the operator-facing failed-logins view; an older host falls
        // back to the count below and the per-IP list still works.
        $top_users = [];
        $top_ips   = [];
        if ( $wpdb->get_var( "SELECT JSON_EXTRACT('{\"a\":1}', '$.a')" ) !== null ) {
            $top_users = $wpdb->get_results( $wpdb->prepare(
                "SELECT JSON_UNQUOTE(JSON_EXTRACT(payload, '$.username')) AS username, COUNT(*) AS n
                 FROM $table
                 WHERE action = %s AND club_id = %d AND created_at >= %s
                 GROUP BY username
                 ORDER BY n DESC
                 LIMIT 10",
                'login_fail', $club_id, $since
            ) );
        }

        $top_ips = $wpdb->get_results( $wpdb->prepare(
            "SELECT ip_address, COUNT(*) AS n
             FROM $table
             WHERE action = %s AND club_id = %d AND created_at >= %s AND ip_address <> ''
             GROUP BY ip_address
             ORDER BY n DESC
             LIMIT 10",
            'login_fail', $club_id, $since
        ) );

        ?>
        <div style="display:grid; grid-template-columns: 1fr; gap: var(--tt-sp-4, 16px);">

            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--tt-sp-3, 12px);">
                <div class="tt-stat-card" style="padding: var(--tt-sp-3, 12px); background: var(--tt-bg-soft, #f6f7f8); border-radius: 4px;">
                    <div style="font-size: 12px; text-transform: uppercase; color: var(--tt-muted, #6a6d66); letter-spacing: 0.5px;"><?php esc_html_e( 'Last 7 days', 'talenttrack' ); ?></div>
                    <div style="font-size: 28px; font-weight: 600; line-height: 1.2;"><?php echo (int) $total_7d; ?></div>
                    <div style="font-size: 13px; color: var(--tt-muted, #6a6d66);"><?php esc_html_e( 'Failed login attempts', 'talenttrack' ); ?></div>
                </div>
                <div class="tt-stat-card" style="padding: var(--tt-sp-3, 12px); background: var(--tt-bg-soft, #f6f7f8); border-radius: 4px;">
                    <div style="font-size: 12px; text-transform: uppercase; color: var(--tt-muted, #6a6d66); letter-spacing: 0.5px;"><?php esc_html_e( 'Last 30 days', 'talenttrack' ); ?></div>
                    <div style="font-size: 28px; font-weight: 600; line-height: 1.2;"><?php echo (int) $total_30d; ?></div>
                    <div style="font-size: 13px; color: var(--tt-muted, #6a6d66);"><?php esc_html_e( 'Failed login attempts', 'talenttrack' ); ?></div>
                </div>
            </div>

            <?php if ( $total_30d === 0 ) : ?>
                <p class="tt-notice"><?php esc_html_e( 'No failed login attempts recorded in the last 30 days.', 'talenttrack' ); ?></p>
            <?php else : ?>
                <div style="display:grid; grid-template-columns: 1fr; gap: var(--tt-sp-4, 16px);">
                    <div>
                        <h3 style="margin: 0 0 var(--tt-sp-2, 8px); font-size: 16px;"><?php esc_html_e( 'Daily breakdown — last 30 days', 'talenttrack' ); ?></h3>
                        <table class="tt-table" style="width:100%; font-size: 13px;">
                            <thead><tr>
                                <th><?php esc_html_e( 'Day', 'talenttrack' ); ?></th>
                                <th style="text-align:right;"><?php esc_html_e( 'Attempts', 'talenttrack' ); ?></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ( $daily as $row ) : ?>
                                <tr>
                                    <td><?php echo esc_html( (string) $row->day ); ?></td>
                                    <td style="text-align:right; font-family: monospace;"><?php echo (int) $row->n; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ( ! empty( $top_users ) ) : ?>
                        <div>
                            <h3 style="margin: 0 0 var(--tt-sp-2, 8px); font-size: 16px;"><?php esc_html_e( 'Top attempted usernames', 'talenttrack' ); ?></h3>
                            <table class="tt-table" style="width:100%; font-size: 13px;">
                                <thead><tr>
                                    <th><?php esc_html_e( 'Username', 'talenttrack' ); ?></th>
                                    <th style="text-align:right;"><?php esc_html_e( 'Attempts', 'talenttrack' ); ?></th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ( $top_users as $row ) : ?>
                                    <tr>
                                        <td style="font-family: monospace; font-size: 12px;"><?php echo esc_html( (string) ( $row->username ?? '—' ) ); ?></td>
                                        <td style="text-align:right; font-family: monospace;"><?php echo (int) $row->n; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <?php if ( ! empty( $top_ips ) ) : ?>
                        <div>
                            <h3 style="margin: 0 0 var(--tt-sp-2, 8px); font-size: 16px;"><?php esc_html_e( 'Top source IPs', 'talenttrack' ); ?></h3>
                            <table class="tt-table" style="width:100%; font-size: 13px;">
                                <thead><tr>
                                    <th><?php esc_html_e( 'IP address', 'talenttrack' ); ?></th>
                                    <th style="text-align:right;"><?php esc_html_e( 'Attempts', 'talenttrack' ); ?></th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ( $top_ips as $row ) : ?>
                                    <tr>
                                        <td style="font-family: monospace; font-size: 12px;"><?php echo esc_html( (string) $row->ip_address ); ?></td>
                                        <td style="text-align:right; font-family: monospace;"><?php echo (int) $row->n; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <p style="font-size: 12px; color: var(--tt-muted, #6a6d66); margin: 0;">
                        <?php esc_html_e( 'No automatic lockout in v1 — this view exists to surface volume so the operator can act when an unusual pattern emerges. Lockout is a v2 enhancement once real volume is observed.', 'talenttrack' ); ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @return array<string, mixed>
     */
    private static function filtersFromQuery(): array {
        $f = [];
        if ( ! empty( $_GET['f_action'] ) )      $f['action']      = sanitize_text_field( wp_unslash( (string) $_GET['f_action'] ) );
        if ( ! empty( $_GET['f_entity_type'] ) ) $f['entity_type'] = sanitize_text_field( wp_unslash( (string) $_GET['f_entity_type'] ) );
        if ( ! empty( $_GET['f_user_id'] ) )     $f['user_id']     = absint( $_GET['f_user_id'] );
        if ( ! empty( $_GET['f_date_from'] ) )   $f['date_from']   = sanitize_text_field( wp_unslash( (string) $_GET['f_date_from'] ) );
        if ( ! empty( $_GET['f_date_to'] ) )     $f['date_to']     = sanitize_text_field( wp_unslash( (string) $_GET['f_date_to'] ) );
        return $f;
    }

    /**
     * @param array<string, mixed> $filters
     * @param string[]             $actions
     * @param string[]             $entity_types
     */
    private static function renderFilterForm( array $filters, array $actions, array $entity_types ): void {
        $sel_action = (string) ( $filters['action']      ?? '' );
        $sel_entity = (string) ( $filters['entity_type'] ?? '' );
        $sel_user   = (string) ( $filters['user_id']     ?? '' );
        $sel_from   = (string) ( $filters['date_from']   ?? '' );
        $sel_to     = (string) ( $filters['date_to']     ?? '' );
        ?>
        <form method="get" class="tt-audit-filters" style="display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end; margin-bottom:var(--tt-sp-3, 12px);">
            <?php
            // Preserve the dashboard router's tt_view param so submitting
            // the filter doesn't kick the user back to the tile grid.
            if ( ! empty( $_GET['tt_view'] ) ) :
                ?><input type="hidden" name="tt_view" value="<?php echo esc_attr( sanitize_key( (string) $_GET['tt_view'] ) ); ?>" /><?php
            endif; ?>

            <div class="tt-field" style="flex:1 1 180px;">
                <label class="tt-field-label" for="tt-audit-f-action"><?php esc_html_e( 'Action', 'talenttrack' ); ?></label>
                <select id="tt-audit-f-action" name="f_action" class="tt-input">
                    <option value=""><?php esc_html_e( '— Any —', 'talenttrack' ); ?></option>
                    <?php foreach ( $actions as $a ) : ?>
                        <option value="<?php echo esc_attr( $a ); ?>" <?php selected( $sel_action, $a ); ?>><?php echo esc_html( $a ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="tt-field" style="flex:1 1 140px;">
                <label class="tt-field-label" for="tt-audit-f-entity"><?php esc_html_e( 'Entity', 'talenttrack' ); ?></label>
                <select id="tt-audit-f-entity" name="f_entity_type" class="tt-input">
                    <option value=""><?php esc_html_e( '— Any —', 'talenttrack' ); ?></option>
                    <?php foreach ( $entity_types as $e ) : ?>
                        <option value="<?php echo esc_attr( $e ); ?>" <?php selected( $sel_entity, $e ); ?>><?php echo esc_html( $e ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="tt-field" style="flex:0 0 100px;">
                <label class="tt-field-label" for="tt-audit-f-user"><?php esc_html_e( 'User #', 'talenttrack' ); ?></label>
                <input id="tt-audit-f-user" type="number" min="0" name="f_user_id" value="<?php echo esc_attr( $sel_user ); ?>" class="tt-input" />
            </div>
            <div class="tt-field" style="flex:0 0 140px;">
                <label class="tt-field-label" for="tt-audit-f-from"><?php esc_html_e( 'From', 'talenttrack' ); ?></label>
                <input id="tt-audit-f-from" type="date" name="f_date_from" value="<?php echo esc_attr( $sel_from ); ?>" class="tt-input" />
            </div>
            <div class="tt-field" style="flex:0 0 140px;">
                <label class="tt-field-label" for="tt-audit-f-to"><?php esc_html_e( 'To', 'talenttrack' ); ?></label>
                <input id="tt-audit-f-to" type="date" name="f_date_to" value="<?php echo esc_attr( $sel_to ); ?>" class="tt-input" />
            </div>
            <div class="tt-field" style="flex:0 0 auto; align-self:flex-end;">
                <button type="submit" class="tt-btn tt-btn-primary"><?php esc_html_e( 'Filter', 'talenttrack' ); ?></button>
                <a href="<?php echo esc_url( self::clearUrl() ); ?>" class="tt-btn tt-btn-secondary"><?php esc_html_e( 'Clear', 'talenttrack' ); ?></a>
            </div>
        </form>
        <?php
    }

    /**
     * @param array<string, mixed> $filters
     */
    private static function renderSummary( int $total, int $page, array $filters ): void {
        $first = $total === 0 ? 0 : ( ( $page - 1 ) * self::PER_PAGE ) + 1;
        $last  = min( $total, $page * self::PER_PAGE );
        echo '<p class="tt-audit-summary" style="color: var(--tt-muted, #6a6d66); font-size: 13px; margin: 0 0 var(--tt-sp-2, 8px);">';
        if ( $total === 0 ) {
            esc_html_e( 'No audit entries match your filters.', 'talenttrack' );
        } else {
            printf(
                /* translators: 1: first row index, 2: last row index, 3: total count */
                esc_html__( 'Showing %1$d–%2$d of %3$d entries.', 'talenttrack' ),
                $first, $last, $total
            );
        }
        if ( ! empty( $filters ) ) {
            echo ' <a href="' . esc_url( self::clearUrl() ) . '">' . esc_html__( 'Clear filters', 'talenttrack' ) . '</a>';
        }
        echo '</p>';
    }

    /** @param object[] $entries */
    private static function renderTable( array $entries ): void {
        if ( empty( $entries ) ) return;
        ?>
        <div class="tt-table-wrap" style="overflow-x:auto;">
            <table class="tt-table tt-audit-table" style="width:100%; font-size: 13px;">
                <thead><tr>
                    <th style="white-space:nowrap;"><?php esc_html_e( 'When', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'User', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Action', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Entity', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'IP', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Payload', 'talenttrack' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $entries as $e ) :
                    $when   = (string) ( $e->created_at ?? '' );
                    $user   = trim( (string) ( $e->user_name ?? '' ) );
                    $userId = (int)  ( $e->user_id   ?? 0 );
                    $action = (string) ( $e->action  ?? '' );
                    $etype  = (string) ( $e->entity_type ?? '' );
                    $eid    = (int)  ( $e->entity_id ?? 0 );
                    $ip     = (string) ( $e->ip_address ?? '' );
                    $payload= (string) ( $e->payload ?? '' );
                    ?>
                    <tr>
                        <td style="white-space:nowrap; font-family: monospace; font-size: 12px;"><?php echo esc_html( $when ); ?></td>
                        <td>
                            <?php
                            if ( $user !== '' ) {
                                echo esc_html( $user );
                            } elseif ( $userId > 0 ) {
                                printf( '#%d', $userId );
                            } else {
                                echo '<em style="color: var(--tt-muted, #6a6d66);">' . esc_html__( '(system)', 'talenttrack' ) . '</em>';
                            }
                            ?>
                        </td>
                        <td><code><?php echo esc_html( $action ); ?></code></td>
                        <td>
                            <?php
                            if ( $etype !== '' ) {
                                echo esc_html( $etype );
                                if ( $eid > 0 ) echo ' <span style="color: var(--tt-muted, #6a6d66);">#' . (int) $eid . '</span>';
                            } else {
                                echo '—';
                            }
                            ?>
                        </td>
                        <td style="font-family: monospace; font-size: 12px; color: var(--tt-muted, #6a6d66);"><?php echo esc_html( $ip ); ?></td>
                        <td style="font-family: monospace; font-size: 11px; max-width: 360px; overflow-wrap: anywhere;"><?php echo esc_html( $payload ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * @param array<string, mixed> $filters
     */
    private static function renderPagination( int $total, int $page, array $filters ): void {
        $pages = (int) max( 1, ceil( $total / self::PER_PAGE ) );
        if ( $pages <= 1 ) return;

        $base = self::baseUrl();
        $qs   = self::filterQueryArgs( $filters );

        $prev = $page > 1     ? add_query_arg( $qs + [ 'apage' => $page - 1 ], $base ) : '';
        $next = $page < $pages ? add_query_arg( $qs + [ 'apage' => $page + 1 ], $base ) : '';
        ?>
        <nav class="tt-audit-pagination" style="display:flex; gap:12px; align-items:center; margin-top: var(--tt-sp-3, 12px); font-size: 13px;">
            <?php if ( $prev ) : ?>
                <a class="tt-btn tt-btn-secondary" href="<?php echo esc_url( $prev ); ?>">&larr; <?php esc_html_e( 'Newer', 'talenttrack' ); ?></a>
            <?php else : ?>
                <span class="tt-btn tt-btn-secondary" style="opacity: 0.5; pointer-events: none;">&larr; <?php esc_html_e( 'Newer', 'talenttrack' ); ?></span>
            <?php endif; ?>
            <span><?php printf(
                /* translators: 1: current page, 2: total pages */
                esc_html__( 'Page %1$d of %2$d', 'talenttrack' ),
                $page, $pages
            ); ?></span>
            <?php if ( $next ) : ?>
                <a class="tt-btn tt-btn-secondary" href="<?php echo esc_url( $next ); ?>"><?php esc_html_e( 'Older', 'talenttrack' ); ?> &rarr;</a>
            <?php else : ?>
                <span class="tt-btn tt-btn-secondary" style="opacity: 0.5; pointer-events: none;"><?php esc_html_e( 'Older', 'talenttrack' ); ?> &rarr;</span>
            <?php endif; ?>
        </nav>
        <?php
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, string|int>
     */
    private static function filterQueryArgs( array $filters ): array {
        $out = [];
        foreach ( [ 'action', 'entity_type', 'user_id', 'date_from', 'date_to' ] as $k ) {
            if ( ! empty( $filters[ $k ] ) ) {
                $out[ 'f_' . $k ] = is_int( $filters[ $k ] ) ? (int) $filters[ $k ] : (string) $filters[ $k ];
            }
        }
        return $out;
    }

    private static function baseUrl(): string {
        $current = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
        return remove_query_arg(
            [ 'apage', 'f_action', 'f_entity_type', 'f_user_id', 'f_date_from', 'f_date_to' ],
            $current ?: home_url( '/' )
        );
    }

    private static function clearUrl(): string {
        $base = self::baseUrl();
        // Keep the tt_view param so we don't fall back to the tile grid.
        $view = isset( $_GET['tt_view'] ) ? sanitize_key( (string) $_GET['tt_view'] ) : '';
        return $view !== '' ? add_query_arg( 'tt_view', $view, $base ) : $base;
    }
}
