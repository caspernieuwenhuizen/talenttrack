<?php
namespace TT\Modules\Analytics\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\KpiRegistry;
use TT\Modules\Analytics\ScheduledReportsRepository;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
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

        $tt_msg = isset( $_GET['tt_msg'] ) ? sanitize_key( (string) $_GET['tt_msg'] ) : '';
        if ( $tt_msg === 'schedule_created' ) {
            echo '<div class="tt-notice tt-notice-success">' . esc_html__( 'Schedule created.', 'talenttrack' ) . '</div>';
        } elseif ( $tt_msg === 'schedule_paused' ) {
            echo '<div class="tt-notice tt-notice-success">' . esc_html__( 'Schedule paused.', 'talenttrack' ) . '</div>';
        } elseif ( $tt_msg === 'schedule_resumed' ) {
            echo '<div class="tt-notice tt-notice-success">' . esc_html__( 'Schedule resumed.', 'talenttrack' ) . '</div>';
        } elseif ( $tt_msg === 'schedule_archived' ) {
            echo '<div class="tt-notice tt-notice-success">' . esc_html__( 'Schedule archived.', 'talenttrack' ) . '</div>';
        }

        echo '<p style="max-width:760px; color:#5b6e75;">'
            . esc_html__( 'Recurring email reports. Pick a KPI, a frequency, and recipients; the daily cron runs the export and emails it on schedule.', 'talenttrack' )
            . '</p>';

        self::renderCreateForm();
        self::renderScheduleList();
    }

    private static function renderCreateForm(): void {
        echo '<h3 style="margin-top:32px;">' . esc_html__( 'New schedule', 'talenttrack' ) . '</h3>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:760px; padding:16px; background:#fafafa; border:1px solid #ddd;">';
        wp_nonce_field( 'tt_scheduled_reports_create', 'tt_sched_nonce' );
        echo '<input type="hidden" name="action" value="tt_scheduled_reports_create">';

        echo '<label style="display:block; margin-bottom:12px;">';
        echo '<span style="display:block; font-weight:600; margin-bottom:4px;">' . esc_html__( 'Name', 'talenttrack' ) . '</span>';
        echo '<input type="text" name="name" required maxlength="255" style="width:100%; padding:6px 8px;" placeholder="' . esc_attr__( 'e.g. Weekly attendance digest', 'talenttrack' ) . '">';
        echo '</label>';

        echo '<label style="display:block; margin-bottom:12px;">';
        echo '<span style="display:block; font-weight:600; margin-bottom:4px;">' . esc_html__( 'KPI', 'talenttrack' ) . '</span>';
        echo '<select name="kpi_key" required style="width:100%; padding:6px 8px;">';
        echo '<option value="">' . esc_html__( '— pick a KPI —', 'talenttrack' ) . '</option>';
        foreach ( KpiRegistry::all() as $key => $kpi ) {
            echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $kpi->label ) . '</option>';
        }
        echo '</select>';
        echo '</label>';

        echo '<label style="display:block; margin-bottom:12px;">';
        echo '<span style="display:block; font-weight:600; margin-bottom:4px;">' . esc_html__( 'Frequency', 'talenttrack' ) . '</span>';
        echo '<select name="frequency" required style="width:100%; padding:6px 8px;">';
        echo '<option value="' . esc_attr( ScheduledReportsRepository::FREQUENCY_WEEKLY_MONDAY ) . '">' . esc_html__( 'Weekly (Monday morning)', 'talenttrack' ) . '</option>';
        echo '<option value="' . esc_attr( ScheduledReportsRepository::FREQUENCY_MONTHLY_FIRST ) . '">' . esc_html__( 'Monthly (first day)', 'talenttrack' ) . '</option>';
        echo '<option value="' . esc_attr( ScheduledReportsRepository::FREQUENCY_SEASON_END ) . '">' . esc_html__( 'Season end (1 July)', 'talenttrack' ) . '</option>';
        echo '</select>';
        echo '</label>';

        echo '<label style="display:block; margin-bottom:12px;">';
        echo '<span style="display:block; font-weight:600; margin-bottom:4px;">' . esc_html__( 'Recipients', 'talenttrack' ) . '</span>';
        echo '<textarea name="recipients" rows="4" required style="width:100%; padding:6px 8px;" placeholder="' . esc_attr__( "One per line — email addresses or WordPress role keys (e.g. tt_head_dev).", 'talenttrack' ) . '"></textarea>';
        echo '</label>';

        echo '<button type="submit" class="tt-button tt-button-primary">' . esc_html__( 'Create schedule', 'talenttrack' ) . '</button>';
        echo '</form>';
    }

    private static function renderScheduleList(): void {
        $repo      = new ScheduledReportsRepository();
        $schedules = $repo->listForCurrentClub();

        echo '<h3 style="margin-top:32px;">' . esc_html__( 'Active and paused schedules', 'talenttrack' ) . '</h3>';
        if ( empty( $schedules ) ) {
            echo '<p style="color:#5b6e75;">' . esc_html__( 'No schedules yet. Create one above.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<table class="widefat striped" style="max-width:960px;">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Name', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'KPI', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Frequency', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Next run', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Actions', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( $schedules as $schedule ) {
            $kpi   = KpiRegistry::find( (string) $schedule['kpi_key'] );
            $label = $kpi ? $kpi->label : (string) $schedule['kpi_key'];
            echo '<tr>';
            echo '<td>' . esc_html( (string) $schedule['name'] ) . '</td>';
            echo '<td>' . esc_html( $label ) . '</td>';
            echo '<td>' . esc_html( self::frequencyLabel( (string) $schedule['frequency'] ) ) . '</td>';
            echo '<td><time>' . esc_html( (string) $schedule['next_run_at'] ) . ' UTC</time></td>';
            echo '<td>' . esc_html( ucfirst( (string) $schedule['status'] ) ) . '</td>';
            echo '<td>';
            self::renderRowActions( (int) $schedule['id'], (string) $schedule['status'] );
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function renderRowActions( int $id, string $status ): void {
        $form = static function ( string $action, string $label, int $sched_id, string $confirm = '' ) {
            $onsubmit = $confirm !== '' ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : '';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline; margin-right:6px;"' . $onsubmit . '>';
            wp_nonce_field( $action, 'tt_sched_nonce' );
            echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
            echo '<input type="hidden" name="schedule_id" value="' . (int) $sched_id . '">';
            echo '<button type="submit" class="tt-button-link">' . esc_html( $label ) . '</button>';
            echo '</form>';
        };

        if ( $status === 'active' ) {
            $form( 'tt_scheduled_reports_pause', __( 'Pause', 'talenttrack' ), $id );
        } elseif ( $status === 'paused' ) {
            $form( 'tt_scheduled_reports_resume', __( 'Resume', 'talenttrack' ), $id );
        }
        $form(
            'tt_scheduled_reports_archive',
            __( 'Archive', 'talenttrack' ),
            $id,
            __( 'Archive this schedule? It will stop running.', 'talenttrack' )
        );
    }

    private static function frequencyLabel( string $frequency ): string {
        switch ( $frequency ) {
            case ScheduledReportsRepository::FREQUENCY_WEEKLY_MONDAY: return __( 'Weekly (Monday)', 'talenttrack' );
            case ScheduledReportsRepository::FREQUENCY_MONTHLY_FIRST: return __( 'Monthly (1st)', 'talenttrack' );
            case ScheduledReportsRepository::FREQUENCY_SEASON_END:    return __( 'Season end', 'talenttrack' );
        }
        return $frequency;
    }
}
