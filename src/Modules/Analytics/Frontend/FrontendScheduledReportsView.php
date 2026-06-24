<?php
namespace TT\Modules\Analytics\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\KpiRegistry;
use TT\Modules\Analytics\ScheduledReportsRepository;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendScheduledReportsView — manage recurring email reports
 * (#0083 Child 6).
 *
 * Reachable at `?tt_view=scheduled-reports`. Cap-gated on
 * `tt_view_analytics` (Academy Admin + HoD by default — same as
 * the central analytics view, since scheduling is an analytics
 * authoring concern).
 *
 * License-gated via `LicenseGate::allows('scheduled_reports')` —
 * Standard or higher. Free-tier operators see a paywall notice
 * with the option to start a trial.
 *
 * Child 6 minimum-viable scope:
 *   - Create form: name + KPI + frequency + recipients (one email
 *     per line; role keys like `tt_head_dev` accepted).
 *   - List of existing schedules with name + KPI + frequency +
 *     next-run timestamp + status.
 *   - Pause / resume / archive actions on each row.
 *
 * **What's deferred** (per spec §`feat-reporting-export-and-schedule`):
 *   - Save-explorer-state-as-schedule (today only KPI-direct schedules).
 *   - XLSX / PDF format options.
 *   - Per-schedule edit form (operators paused + recreate today).
 */
class FrontendScheduledReportsView extends FrontendViewBase {

    /**
     * Enqueue the 2026 surface stylesheet (B3 restyle). Depends on the
     * app-chrome sheet so it inherits the brand + neutral tokens.
     */
    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-scheduled-reports',
            TT_PLUGIN_URL . 'assets/css/frontend-scheduled-reports.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
    }

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_view_analytics' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to manage scheduled reports.', 'talenttrack' ) . '</p>';
            return;
        }

        // License gate — Standard or higher.
        $license_ok = ! class_exists( '\\TT\\Modules\\License\\LicenseGate' )
                   || \TT\Modules\License\LicenseGate::allows( 'scheduled_reports' );

        FrontendBreadcrumbs::fromDashboard(
            __( 'Scheduled reports', 'talenttrack' ),
            [ FrontendBreadcrumbs::viewCrumb( 'analytics', __( 'Analytics', 'talenttrack' ) ) ]
        );
        self::renderHeader( __( 'Scheduled reports', 'talenttrack' ) );

        if ( ! $license_ok ) {
            if ( class_exists( '\\TT\\Modules\\License\\Admin\\UpgradeNudge' ) ) {
                echo \TT\Modules\License\Admin\UpgradeNudge::inline(
                    __( 'Scheduled reports', 'talenttrack' ),
                    'standard'
                );
            } else {
                echo '<p class="tt-notice">' . esc_html__( 'Scheduled reports are part of the Standard plan and above.', 'talenttrack' ) . '</p>';
            }
            return;
        }

        self::enqueueAssets();

        $tt_msg = isset( $_GET['tt_msg'] ) ? sanitize_key( (string) $_GET['tt_msg'] ) : '';
        if ( $tt_msg === 'schedule_created' ) {
            echo '<div class="tt-notice tt-notice-success">' . esc_html__( 'Schedule created.', 'talenttrack' ) . '</div>';
        } elseif ( $tt_msg === 'schedule_paused' ) {
            echo '<div class="tt-notice tt-notice-success">' . esc_html__( 'Schedule paused.', 'talenttrack' ) . '</div>';
        } elseif ( $tt_msg === 'schedule_resumed' ) {
            echo '<div class="tt-notice tt-notice-success">' . esc_html__( 'Schedule resumed.', 'talenttrack' ) . '</div>';
        } elseif ( $tt_msg === 'schedule_archived' ) {
            echo '<div class="tt-notice tt-notice-success">' . esc_html__( 'Schedule archived.', 'talenttrack' ) . '</div>';
        } elseif ( $tt_msg === 'schedule_deleted' ) {
            echo '<div class="tt-notice tt-notice-success">' . esc_html__( 'Schedule permanently deleted.', 'talenttrack' ) . '</div>';
        } elseif ( $tt_msg === 'schedule_delete_blocked' ) {
            echo '<div class="tt-notice tt-notice-error">' . esc_html__( 'The schedule could not be deleted because other records still reference it.', 'talenttrack' ) . '</div>';
        }

        echo '<p class="tt-sched-intro">'
            . esc_html__( 'Recurring email reports. Pick a KPI, a frequency, and recipients; the daily cron runs the export and emails it on schedule.', 'talenttrack' )
            . '</p>';

        self::renderCreateForm();
        self::renderScheduleList();
    }

    private static function renderCreateForm(): void {
        echo '<h3 class="tt-sched-heading">' . esc_html__( 'New schedule', 'talenttrack' ) . '</h3>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tt-sched-form">';
        wp_nonce_field( 'tt_scheduled_reports_create', 'tt_sched_nonce' );
        echo '<input type="hidden" name="action" value="tt_scheduled_reports_create">';

        echo '<label class="tt-sched-field">';
        echo '<span class="tt-sched-field__label">' . esc_html__( 'Name', 'talenttrack' ) . '</span>';
        echo '<input type="text" name="name" required maxlength="255" placeholder="' . esc_attr__( 'e.g. Weekly attendance digest', 'talenttrack' ) . '">';
        echo '</label>';

        echo '<label class="tt-sched-field">';
        echo '<span class="tt-sched-field__label">' . esc_html__( 'KPI', 'talenttrack' ) . '</span>';
        echo '<select name="kpi_key" required>';
        echo '<option value="">' . esc_html__( '— pick a KPI —', 'talenttrack' ) . '</option>';
        foreach ( KpiRegistry::all() as $key => $kpi ) {
            echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $kpi->label ) . '</option>';
        }
        echo '</select>';
        echo '</label>';

        echo '<label class="tt-sched-field">';
        echo '<span class="tt-sched-field__label">' . esc_html__( 'Frequency', 'talenttrack' ) . '</span>';
        echo '<select name="frequency" required>';
        echo '<option value="' . esc_attr( ScheduledReportsRepository::FREQUENCY_WEEKLY_MONDAY ) . '">' . esc_html__( 'Weekly (Monday morning)', 'talenttrack' ) . '</option>';
        echo '<option value="' . esc_attr( ScheduledReportsRepository::FREQUENCY_MONTHLY_FIRST ) . '">' . esc_html__( 'Monthly (first day)', 'talenttrack' ) . '</option>';
        echo '<option value="' . esc_attr( ScheduledReportsRepository::FREQUENCY_SEASON_END ) . '">' . esc_html__( 'Season end (1 July)', 'talenttrack' ) . '</option>';
        echo '</select>';
        echo '</label>';

        echo '<label class="tt-sched-field">';
        echo '<span class="tt-sched-field__label">' . esc_html__( 'Recipients', 'talenttrack' ) . '</span>';
        echo '<textarea name="recipients" rows="4" required placeholder="' . esc_attr__( "One per line — email addresses or WordPress role keys (e.g. tt_head_dev).", 'talenttrack' ) . '"></textarea>';
        echo '</label>';

        // CLAUDE.md §6 — Save + Cancel on a record-creating form. Cancel
        // returns to the schedules list (this same view); a tt_back hint on
        // the entry URL overrides that destination.
        $back       = BackLink::resolve();
        $cancel_url = $back !== null
            ? $back['url']
            : add_query_arg( 'tt_view', 'scheduled-reports', RecordLink::dashboardUrl() );
        echo FormSaveButton::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — helper escapes its own output.
            'label'      => __( 'Create schedule', 'talenttrack' ),
            'cancel_url' => $cancel_url,
        ] );
        echo '</form>';
    }

    private static function renderScheduleList(): void {
        $repo      = new ScheduledReportsRepository();
        $schedules = $repo->listForCurrentClub();

        echo '<h3 class="tt-sched-heading">' . esc_html__( 'Active and paused schedules', 'talenttrack' ) . '</h3>';
        if ( empty( $schedules ) ) {
            echo '<p class="tt-sched-empty">' . esc_html__( 'No schedules yet. Create one above.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<div class="tt-report-card tt-sched-tablewrap"><div class="tt-table-wrap">';
        echo '<table class="tt-table tt-sched-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Name', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'KPI', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Frequency', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Next run', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Actions', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( $schedules as $schedule ) {
            $kpi    = KpiRegistry::find( (string) $schedule['kpi_key'] );
            $label  = $kpi ? $kpi->label : (string) $schedule['kpi_key'];
            $status = (string) $schedule['status'];
            $chip   = 'tt-sched-chip';
            if ( $status === 'active' )  $chip .= ' tt-sched-chip--active';
            if ( $status === 'paused' )  $chip .= ' tt-sched-chip--paused';
            echo '<tr>';
            echo '<td>' . esc_html( (string) $schedule['name'] ) . '</td>';
            echo '<td>' . esc_html( $label ) . '</td>';
            echo '<td>' . esc_html( self::frequencyLabel( (string) $schedule['frequency'] ) ) . '</td>';
            echo '<td><time>' . esc_html( (string) $schedule['next_run_at'] ) . ' UTC</time></td>';
            echo '<td><span class="' . esc_attr( $chip ) . '">' . esc_html( ucfirst( $status ) ) . '</span></td>';
            echo '<td>';
            self::renderRowActions( (int) $schedule['id'], $status );
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div></div>';
    }

    private static function renderRowActions( int $id, string $status ): void {
        $form = static function ( string $action, string $label, int $sched_id, string $confirm = '', bool $danger = false ) {
            $onsubmit = $confirm !== '' ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : '';
            $btn_class = 'tt-sched-action' . ( $danger ? ' tt-sched-action--danger' : '' );
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . $onsubmit . '>';
            wp_nonce_field( $action, 'tt_sched_nonce' );
            echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
            echo '<input type="hidden" name="schedule_id" value="' . (int) $sched_id . '">';
            echo '<button type="submit" class="' . esc_attr( $btn_class ) . '">' . esc_html( $label ) . '</button>';
            echo '</form>';
        };

        echo '<div class="tt-sched-actions">';
        if ( $status === 'active' ) {
            $form( 'tt_scheduled_reports_pause', __( 'Pause', 'talenttrack' ), $id );
        } elseif ( $status === 'paused' ) {
            $form( 'tt_scheduled_reports_resume', __( 'Resume', 'talenttrack' ), $id );
        }
        if ( $status === 'archived' ) {
            // #1808 — referential-integrity permanent delete, only on
            // already-archived rows.
            $form(
                'tt_scheduled_reports_delete',
                __( 'Delete permanently', 'talenttrack' ),
                $id,
                __( 'Permanently delete this schedule? This cannot be undone.', 'talenttrack' ),
                true
            );
        } else {
            $form(
                'tt_scheduled_reports_archive',
                __( 'Archive', 'talenttrack' ),
                $id,
                __( 'Archive this schedule? It will stop running.', 'talenttrack' ),
                true
            );
        }
        echo '</div>';
    }

    private static function frequencyLabel( string $frequency ): string {
        // v3.110.213 (#845) — delegate to the repository's canonical
        // label which routes through LookupTranslator. Keeps the
        // pre-migration English fallback intact for installs that
        // haven't applied migration 0117 yet.
        return ScheduledReportsRepository::frequencyLabel( $frequency );
    }
}
