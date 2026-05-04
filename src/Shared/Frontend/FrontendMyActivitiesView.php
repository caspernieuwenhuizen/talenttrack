<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LabelTranslator;
use TT\Infrastructure\Query\QueryHelpers;

/**
 * FrontendMyActivitiesView — the "My sessions" tile destination.
 *
 * Lists activities attended by the logged-in player, most-recent
 * first. Filterable by status, date range, and free-text search
 * (matches activity title + notes). Filter pattern matches the
 * coach-side evaluation list so the UX is consistent.
 */
class FrontendMyActivitiesView extends FrontendViewBase {

    public static function render( object $player ): void {
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( $id > 0 ) {
            // v3.92.1 — breadcrumb on the detail sub-view; renderDetail
            // doesn't add its own.
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
                __( 'Activity detail', 'talenttrack' ),
                [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'my-activities', __( 'My activities', 'talenttrack' ) ) ]
            );
            self::renderDetail( $player, $id );
            return;
        }

        self::enqueueAssets();
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'My activities', 'talenttrack' ) );
        self::renderHeader( __( 'My activities', 'talenttrack' ) );

        $filters = self::filtersFromQuery();
        $statuses = QueryHelpers::get_lookup_names( 'attendance_status' );

        self::renderFilters( $filters, $statuses );
        self::renderTable( $player, $filters );
    }

    /**
     * Single-activity detail reachable via `?tt_view=my-activities&id=N`.
     * Shows the activity (date, title, opponent, type), the player's
     * attendance for it, and any notes — closing the "see more"
     * gap from the profile + activities list (#0061).
     */
    private static function renderDetail( object $player, int $activity_id ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT a.*, t.name AS team_name
               FROM {$p}tt_activities a
               LEFT JOIN {$p}tt_teams t ON a.team_id = t.id
              WHERE a.id = %d
              LIMIT 1",
            $activity_id
        ) );

        if ( ! $row ) {
            self::renderHeader( __( 'Activity not found', 'talenttrack' ) );
            echo '<p><em>' . esc_html__( 'That activity is no longer available.', 'talenttrack' ) . '</em></p>';
            return;
        }

        $att = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}tt_attendance
              WHERE activity_id = %d AND ( player_id = %d OR guest_player_id = %d )
              LIMIT 1",
            $activity_id, (int) $player->id, (int) $player->id
        ) );

        self::enqueueAssets();
        $title = (string) \TT\Modules\Translations\TranslationLayer::render( (string) ( $row->title ?: '' ) );
        if ( $title === '' ) $title = __( 'Activity', 'talenttrack' );
        self::renderHeader( $title );

        // v3.92.5 — was a flat `<dl class="tt-profile-dl">` with no card
        // chrome, no badge for attendance status, and no visual grouping.
        // Pilot operator: "the display page of an activity is visually
        // not very appealing." Mirroring the goal-detail pattern
        // (`tt-goal-detail` wrapper + meta row with badges + body) so the
        // two surfaces feel consistent.
        $session_date = (string) ( $row->session_date ?: '' );
        $opponent     = (string) ( $row->opponent ?? '' );
        $location     = (string) ( $row->location ?? '' );
        $team_name    = (string) ( $row->team_name ?? '' );
        $att_status   = $att ? (string) ( $att->status ?? '' ) : '';
        $att_notes    = $att && ! empty( $att->notes ) ? (string) $att->notes : '';
        $type_key     = (string) ( $row->activity_type_key ?? '' );
        $att_status_lower = strtolower( $att_status );
        $att_status_class = $att_status_lower === 'present'
            ? 'tt-status-completed'
            : ( $att_status_lower === 'absent' ? 'tt-status-pending' : '' );
        ?>
        <article class="tt-activity-detail">
            <p class="tt-activity-detail-meta">
                <?php if ( $session_date !== '' ) : ?>
                    <span class="tt-due"><?php esc_html_e( 'Date:', 'talenttrack' ); ?> <?php echo esc_html( $session_date ); ?></span>
                <?php endif; ?>
                <?php if ( $team_name !== '' ) : ?>
                    <span class="tt-meta-chip"><?php esc_html_e( 'Team:', 'talenttrack' ); ?> <strong><?php echo esc_html( $team_name ); ?></strong></span>
                <?php endif; ?>
                <?php if ( $opponent !== '' ) : ?>
                    <span class="tt-meta-chip"><?php esc_html_e( 'Opponent:', 'talenttrack' ); ?> <strong><?php echo esc_html( $opponent ); ?></strong></span>
                <?php endif; ?>
                <?php if ( $location !== '' ) : ?>
                    <span class="tt-meta-chip"><?php esc_html_e( 'Location:', 'talenttrack' ); ?> <strong><?php echo esc_html( $location ); ?></strong></span>
                <?php endif; ?>
                <?php if ( $type_key !== '' ) : ?>
                    <span class="tt-status-badge"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $type_key ) ) ); ?></span>
                <?php endif; ?>
                <?php if ( $att_status !== '' ) : ?>
                    <span class="tt-status-badge <?php echo esc_attr( $att_status_class ); ?>">
                        <?php
                        echo esc_html( sprintf(
                            /* translators: %s = attendance status label (Present / Absent / Late / etc.) */
                            __( 'Your attendance: %s', 'talenttrack' ),
                            LabelTranslator::attendanceStatus( $att_status )
                        ) );
                        ?>
                    </span>
                <?php endif; ?>
            </p>

            <?php if ( $att_notes !== '' ) : ?>
                <section class="tt-activity-detail-body">
                    <h3 style="margin:0 0 var(--tt-sp-2); font-size:1rem;"><?php esc_html_e( 'Notes from your coach', 'talenttrack' ); ?></h3>
                    <p><?php echo esc_html( \TT\Modules\Translations\TranslationLayer::render( $att_notes ) ); ?></p>
                </section>
            <?php endif; ?>
        </article>
        <?php
    }

    /** @return array<string,mixed> */
    private static function filtersFromQuery(): array {
        $f = [];
        if ( ! empty( $_GET['f_status'] ) )    $f['status']    = sanitize_text_field( wp_unslash( (string) $_GET['f_status'] ) );
        if ( ! empty( $_GET['f_date_from'] ) ) $f['date_from'] = sanitize_text_field( wp_unslash( (string) $_GET['f_date_from'] ) );
        if ( ! empty( $_GET['f_date_to'] ) )   $f['date_to']   = sanitize_text_field( wp_unslash( (string) $_GET['f_date_to'] ) );
        if ( ! empty( $_GET['f_search'] ) )    $f['search']    = sanitize_text_field( wp_unslash( (string) $_GET['f_search'] ) );
        return $f;
    }

    /**
     * @param array<string,mixed> $filters
     * @param string[]            $statuses
     */
    private static function renderFilters( array $filters, array $statuses ): void {
        $sel_status = (string) ( $filters['status']    ?? '' );
        $sel_from   = (string) ( $filters['date_from'] ?? '' );
        $sel_to     = (string) ( $filters['date_to']   ?? '' );
        $sel_search = (string) ( $filters['search']    ?? '' );
        ?>
        <form method="get" style="display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end; margin-bottom:12px;">
            <input type="hidden" name="tt_view" value="my-activities" />

            <div class="tt-field" style="flex:1 1 220px;">
                <label class="tt-field-label" for="tt-mya-search"><?php esc_html_e( 'Search', 'talenttrack' ); ?></label>
                <input id="tt-mya-search" type="search" name="f_search" value="<?php echo esc_attr( $sel_search ); ?>" class="tt-input" placeholder="<?php esc_attr_e( 'Title or notes…', 'talenttrack' ); ?>" />
            </div>
            <div class="tt-field" style="flex:0 0 160px;">
                <label class="tt-field-label" for="tt-mya-status"><?php esc_html_e( 'Status', 'talenttrack' ); ?></label>
                <select id="tt-mya-status" name="f_status" class="tt-input">
                    <option value=""><?php esc_html_e( 'Any', 'talenttrack' ); ?></option>
                    <?php foreach ( $statuses as $s ) : ?>
                        <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $sel_status, $s ); ?>><?php echo esc_html( LabelTranslator::attendanceStatus( $s ) ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="tt-field" style="flex:0 0 140px;">
                <label class="tt-field-label" for="tt-mya-from"><?php esc_html_e( 'From', 'talenttrack' ); ?></label>
                <input id="tt-mya-from" type="date" name="f_date_from" value="<?php echo esc_attr( $sel_from ); ?>" class="tt-input" />
            </div>
            <div class="tt-field" style="flex:0 0 140px;">
                <label class="tt-field-label" for="tt-mya-to"><?php esc_html_e( 'To', 'talenttrack' ); ?></label>
                <input id="tt-mya-to" type="date" name="f_date_to" value="<?php echo esc_attr( $sel_to ); ?>" class="tt-input" />
            </div>
            <div class="tt-field" style="flex:0 0 auto; align-self:flex-end;">
                <button type="submit" class="tt-btn tt-btn-primary"><?php esc_html_e( 'Filter', 'talenttrack' ); ?></button>
                <a href="<?php echo esc_url( remove_query_arg( [ 'f_status', 'f_date_from', 'f_date_to', 'f_search' ] ) ); ?>" class="tt-btn tt-btn-secondary"><?php esc_html_e( 'Clear', 'talenttrack' ); ?></a>
            </div>
        </form>
        <?php
    }

    /**
     * @param array<string,mixed> $filters
     */
    private static function renderTable( object $player, array $filters ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $where  = '(a.player_id = %d OR a.guest_player_id = %d)';
        $params = [ (int) $player->id, (int) $player->id ];

        if ( ! empty( $filters['status'] ) ) {
            $where   .= ' AND a.status = %s';
            $params[] = (string) $filters['status'];
        }
        if ( ! empty( $filters['date_from'] ) ) {
            $df = self::parseDate( (string) $filters['date_from'] );
            if ( $df !== '' ) {
                $where   .= ' AND s.session_date >= %s';
                $params[] = $df;
            }
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $dt = self::parseDate( (string) $filters['date_to'] );
            if ( $dt !== '' ) {
                $where   .= ' AND s.session_date <= %s';
                $params[] = $dt;
            }
        }
        if ( ! empty( $filters['search'] ) ) {
            $like = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
            $where   .= ' AND ( s.title LIKE %s OR a.notes LIKE %s )';
            $params[] = $like;
            $params[] = $like;
        }

        // GROUP BY a.id is defensive: the v3.32+ guest→player promotion
        // flow occasionally left stale duplicate rows (one with
        // guest_player_id, one with player_id pointing to the same user).
        // The OR clause matches both, so we group on the attendance id to
        // emit a single row per attendance regardless of which column fired.
        $sql = "SELECT a.*, s.title AS session_title, s.session_date
                  FROM {$p}tt_attendance a
                  LEFT JOIN {$p}tt_activities s ON a.activity_id = s.id
                 WHERE $where
                 GROUP BY a.id
                 ORDER BY s.session_date DESC, a.id DESC";

        $att = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );

        if ( empty( $att ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No attendance records match these filters.', 'talenttrack' ) . '</p>';
            return;
        }
        // v3.92.5 — was rendering a `tt-table tt-table-sortable` table
        // which doesn't pick up the same chrome the admin-side coach
        // activity lists use (those use `FrontendListTable::render`,
        // which emits `tt-list-table-table` inside `tt-list-table-wrap`).
        // Operator on pilot install: "my activities table list is not
        // like other table lists; align". Quick alignment: switch to
        // the shared list-table classes + wrap so the fonts, padding,
        // hover, and zebra striping match. Full migration to
        // `FrontendListTable::render` requires a per-player REST scope
        // on /activities — out of scope; tracked as a follow-up.
        ?>
        <div class="tt-list-table-wrap">
            <table class="tt-list-table-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Date', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Activity', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Notes', 'talenttrack' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $base_url = remove_query_arg( [ 'id' ] );
                    foreach ( $att as $a ) :
                        $status_lower = strtolower( (string) $a->status );
                        $cls = $status_lower === 'present'
                            ? 'tt-att-present'
                            : ( $status_lower === 'absent' ? 'tt-att-absent' : 'tt-att-other' );
                        $detail_url = add_query_arg( 'id', (int) $a->activity_id, $base_url );
                        ?>
                        <tr class="<?php echo esc_attr( $cls ); ?> tt-row-clickable" data-tt-href="<?php echo esc_url( $detail_url ); ?>">
                            <td><a class="tt-row-link" href="<?php echo esc_url( $detail_url ); ?>"><?php echo esc_html( (string) $a->session_date ); ?></a></td>
                            <td><a class="tt-row-link" href="<?php echo esc_url( $detail_url ); ?>"><?php echo esc_html( \TT\Modules\Translations\TranslationLayer::render( (string) $a->session_title ) ); ?></a></td>
                            <td><?php echo esc_html( LabelTranslator::attendanceStatus( (string) $a->status ) ); ?></td>
                            <td><?php
                                $notes = (string) ( $a->notes ?: '' );
                                echo $notes !== '' ? esc_html( \TT\Modules\Translations\TranslationLayer::render( $notes ) ) : '—';
                            ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function parseDate( string $raw ): string {
        $raw = trim( $raw );
        if ( $raw === '' ) return '';
        $d = \DateTime::createFromFormat( 'Y-m-d', $raw );
        return ( $d && $d->format( 'Y-m-d' ) === $raw ) ? $raw : '';
    }
}
