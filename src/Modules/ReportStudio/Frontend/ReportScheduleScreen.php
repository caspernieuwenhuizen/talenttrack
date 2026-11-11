<?php
namespace TT\Modules\ReportStudio\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Cron\ScheduledReportsRunner;
use TT\Modules\Analytics\Reports\ReportFilters;
use TT\Modules\Analytics\ScheduledReportsRepository;
use TT\Modules\ReportStudio\Domain\ReportReader;
use TT\Modules\ReportStudio\Domain\ReportSchedules;
use TT\Modules\ReportStudio\Domain\ReportStartRules;
use TT\Modules\ReportStudio\Domain\StudioError;
use TT\Modules\ReportStudio\Repositories\ReportsRepository;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * ReportScheduleScreen (#4963, epic #4564) — a report's schedules, and the
 * form that adds or changes one, on the Studio's own route:
 *
 *   ?tt_view=report-studio&id=N&schedules=1    the report's schedules
 *   ?tt_view=report-studio&id=N&schedule=new   a new schedule
 *   ?tt_view=report-studio&id=N&schedule=S     schedule S, changed in place
 *
 * Save model B: Save + Cancel, Cancel back to the report or to `tt_back`.
 * Wizard plan: exemption — a schedule is four fields on an existing,
 * published report; there is no new record to walk someone through (the
 * owner's answer on #4963).
 *
 * The form composes; every rule is `ReportSchedules`'. It posts to
 * `admin-post.php`, which calls the same service the REST routes call. What
 * the recipients would get is shown before Save, from the same service a
 * run uses, and refreshed from `GET /reports/{id}/schedules/preview` as the
 * recipients change.
 *
 * @phpstan-import-type Report from ReportsRepository
 */
final class ReportScheduleScreen {

    public const ACTION = 'tt_report_schedule_save';

    public static function init(): void {
        add_action( 'admin_post_' . self::ACTION, [ self::class, 'handleSave' ] );
    }

    /** Is this request one of the schedule screens? */
    public static function asked(): bool {
        return isset( $_GET['schedules'] ) || isset( $_GET['schedule'] );
    }

    /**
     * @param Report $report
     */
    public static function render( array $report, string $report_name ): void {
        $reader = ReportReader::for( get_current_user_id() );
        $asked  = isset( $_GET['schedule'] ) ? sanitize_key( wp_unslash( (string) $_GET['schedule'] ) ) : '';

        wp_enqueue_style( 'tt-report-schedules', TT_PLUGIN_URL . 'assets/css/frontend-report-schedules.css', [], TT_VERSION );

        if ( $asked === '' ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Schedules', 'talenttrack' ), self::crumbs( $report, $report_name, false ) );
            self::renderList( $report, $reader );
            return;
        }

        $schedule = null;
        if ( $asked !== 'new' ) {
            $found = ReportSchedules::findForReader( absint( $asked ), $reader );
            if ( $found instanceof StudioError || $found['report']['id'] !== $report['id'] ) {
                FrontendBreadcrumbs::fromDashboard( __( 'Schedule not found', 'talenttrack' ), self::crumbs( $report, $report_name, true ) );
                echo '<p class="tt-notice">' . esc_html( $found instanceof StudioError ? $found->message : __( 'There is no such schedule.', 'talenttrack' ) ) . '</p>';
                return;
            }
            $schedule = $found['schedule'];
        }

        FrontendBreadcrumbs::fromDashboard(
            $schedule !== null ? (string) $schedule['name'] : __( 'New schedule', 'talenttrack' ),
            self::crumbs( $report, $report_name, true )
        );
        self::renderForm( $report, $reader, $schedule );
    }

    /**
     * @param Report $report
     */
    private static function renderList( array $report, ReportReader $reader ): void {
        self::notice();
        echo '<div class="tt-rsch">';
        echo '<h1 class="tt-fview-title">' . esc_html__( 'Schedules', 'talenttrack' ) . '</h1>';

        $refusal = ReportSchedules::refusal( $report );
        if ( $refusal !== null ) {
            echo '<p class="tt-notice">' . esc_html( $refusal ) . '</p>';
        } else {
            echo '<p><a class="tt-btn tt-btn-primary" href="' . esc_url( BackLink::appendTo( self::url( $report, [ 'schedule' => 'new' ] ) ) ) . '">' . esc_html__( 'Add schedule', 'talenttrack' ) . '</a></p>';
        }

        $rows = ReportSchedules::listFor( $report, $reader );
        if ( $rows === [] ) {
            echo '<p class="tt-rsch-empty">' . esc_html__( 'This report is not sent on a schedule yet.', 'talenttrack' ) . '</p>';
            echo '</div>';
            return;
        }
        echo '<ul class="tt-rsch-list">';
        foreach ( $rows as $schedule ) {
            echo '<li class="tt-rsch-item">';
            echo '<a class="tt-rsch-link" href="' . esc_url( BackLink::appendTo( self::url( $report, [ 'schedule' => (string) $schedule['id'] ] ) ) ) . '">';
            echo '<span class="tt-rsch-name">' . esc_html( (string) $schedule['name'] ) . '</span>';
            echo '<span class="tt-rsch-meta">' . esc_html( ScheduledReportsRepository::frequencyLabel( (string) $schedule['frequency'] ) . ' · ' . ScheduledReportsRepository::statusLabel( (string) $schedule['status'] ) ) . '</span>';
            if ( ! empty( $schedule['last_error'] ) ) echo '<span class="tt-rsch-error">' . esc_html( (string) $schedule['last_error'] ) . '</span>';
            echo '</a></li>';
        }
        echo '</ul></div>';
    }

    /**
     * @param Report                   $report
     * @param array<string,mixed>|null $schedule
     */
    private static function renderForm( array $report, ReportReader $reader, ?array $schedule ): void {
        self::notice();
        echo '<div class="tt-rsch">';
        echo '<h1 class="tt-fview-title">' . esc_html( $schedule !== null ? __( 'Change the schedule', 'talenttrack' ) : __( 'Schedule this report', 'talenttrack' ) ) . '</h1>';

        $refusal = ReportSchedules::refusal( $report );
        if ( $refusal !== null && $schedule === null ) {
            echo '<p class="tt-notice">' . esc_html( $refusal ) . '</p></div>';
            return;
        }

        $composition = $schedule !== null && is_array( $schedule['composition'] ?? null ) ? $schedule['composition'] : [ 'report_id' => $report['id'], 'period' => ReportSchedules::DEFAULT_PERIOD ];
        $self_entry  = ScheduledReportsRunner::ACCOUNT_PREFIX . $reader->userId;
        $chosen      = $schedule !== null ? array_map( 'strval', (array) $schedule['recipients'] ) : [ $self_entry ];
        $publishes   = $reader->publishesReports();

        echo '<p class="tt-rsch-intro">' . esc_html__( 'The schedule sends the report\'s published version as a PDF, laid out in rows. One copy goes to everyone, so it holds only what every recipient may read. Each recipient is checked again on every run.', 'talenttrack' ) . '</p>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tt-rsch-form" data-tt-rsch-form>';
        wp_nonce_field( self::ACTION, 'tt_rsch_nonce' );
        echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
        echo '<input type="hidden" name="report_id" value="' . esc_attr( (string) $report['id'] ) . '">';
        echo '<input type="hidden" name="schedule_id" value="' . esc_attr( (string) ( $schedule['id'] ?? 0 ) ) . '">';
        $back = BackLink::currentValue();
        if ( $back !== '' ) echo '<input type="hidden" name="tt_back" value="' . esc_attr( $back ) . '">';

        echo '<div class="tt-field">';
        echo '<label class="tt-field-label" for="tt-rsch-name">' . esc_html__( 'Name', 'talenttrack' ) . '</label>';
        echo '<input type="text" id="tt-rsch-name" class="tt-input" name="name" maxlength="' . esc_attr( (string) ReportStartRules::MAX_NAME ) . '" autocomplete="off" value="' . esc_attr( (string) ( $schedule['name'] ?? $report['name'] ) ) . '">';
        echo '</div>';

        // Recipients: accounts and staff roles only.
        echo '<fieldset class="tt-rsch-choices" data-tt-rsch-recipients>';
        echo '<legend>' . esc_html__( 'Who receives it', 'talenttrack' ) . '</legend>';
        if ( ! $publishes ) {
            echo '<label class="tt-rsch-option" for="tt-rsch-me"><input type="checkbox" id="tt-rsch-me" name="recipients[]" value="' . esc_attr( $self_entry ) . '"' . checked( in_array( $self_entry, $chosen, true ), true, false ) . '> <span>' . esc_html_x( 'Me', 'schedule recipient: the person scheduling', 'talenttrack' ) . '</span></label>';
            echo '<p class="tt-field-hint">' . esc_html__( 'You may schedule this report for yourself. Sending it to others needs the right to publish reports.', 'talenttrack' ) . '</p>';
        } else {
            $roles = ReportSchedules::roleOptions();
            if ( $roles !== [] ) {
                echo '<p class="tt-rsch-sub">' . esc_html__( 'Roles', 'talenttrack' ) . '</p>';
                foreach ( $roles as $key => $label ) {
                    $id = 'tt-rsch-role-' . $key;
                    echo '<label class="tt-rsch-option" for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="recipients[]" value="' . esc_attr( $key ) . '"' . checked( in_array( $key, $chosen, true ), true, false ) . '> <span>' . esc_html( $label ) . '</span></label>';
                }
            }
            $people = ReportSchedules::accountOptions( $report );
            echo '<div class="tt-field">';
            echo '<label class="tt-field-label" for="tt-rsch-people">' . esc_html__( 'People', 'talenttrack' ) . '</label>';
            echo '<select id="tt-rsch-people" class="tt-input tt-rsch-people" name="recipients[]" multiple size="' . esc_attr( (string) min( 8, max( 3, count( $people ) ) ) ) . '" aria-describedby="tt-rsch-people-hint">';
            foreach ( $people as $entry => $name ) {
                echo '<option value="' . esc_attr( $entry ) . '"' . selected( in_array( $entry, $chosen, true ), true, false ) . '>' . esc_html( $name ) . '</option>';
            }
            echo '</select>';
            echo '<p class="tt-field-hint" id="tt-rsch-people-hint">' . esc_html__( 'Only people who may read this report are listed. Someone without an account needs one first.', 'talenttrack' ) . '</p>';
            echo '</div>';
        }
        echo '</fieldset>';

        echo '<div class="tt-field">';
        echo '<label class="tt-field-label" for="tt-rsch-frequency">' . esc_html__( 'How often', 'talenttrack' ) . '</label>';
        echo '<select id="tt-rsch-frequency" class="tt-input" name="frequency">';
        $frequency = (string) ( $schedule['frequency'] ?? ScheduledReportsRepository::FREQUENCY_MONTHLY_FIRST );
        foreach ( ReportSchedules::FREQUENCIES as $value ) {
            echo '<option value="' . esc_attr( $value ) . '"' . selected( $frequency, $value, false ) . '>' . esc_html( ScheduledReportsRepository::frequencyLabel( $value ) ) . '</option>';
        }
        echo '</select></div>';

        echo '<div class="tt-field">';
        if ( $report['period_mode'] === ReportStartRules::PERIOD_ON_OPEN ) {
            echo '<label class="tt-field-label" for="tt-rsch-period">' . esc_html__( 'Period it covers', 'talenttrack' ) . '</label>';
            echo '<select id="tt-rsch-period" class="tt-input" name="period" data-tt-rsch-period>';
            $period = is_string( $composition['period'] ?? null ) && $composition['period'] !== '' ? (string) $composition['period'] : ReportSchedules::DEFAULT_PERIOD;
            foreach ( ReportFilters::periodLabels() as $value => $label ) {
                if ( ! in_array( $value, ReportFilters::PERIODS, true ) ) continue;
                echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( $period, (string) $value, false ) . '>' . esc_html( (string) $label ) . '</option>';
            }
            echo '</select>';
        } else {
            echo '<p class="tt-field-label">' . esc_html__( 'Period it covers', 'talenttrack' ) . '</p>';
            echo '<p class="tt-rsch-fixed">' . esc_html__( 'The report\'s own period.', 'talenttrack' ) . '</p>';
        }
        echo '</div>';

        echo '<p class="tt-field-label">' . esc_html__( 'Format', 'talenttrack' ) . '</p>';
        echo '<p class="tt-rsch-fixed">' . esc_html__( 'PDF, laid out in rows.', 'talenttrack' ) . '</p>';

        // What these recipients get, before Save.
        $preview = ReportSchedules::preview( $report, $chosen, $schedule !== null ? (int) $schedule['created_by'] : $reader->userId, $composition );
        echo '<section class="tt-rsch-preview" aria-live="polite" data-tt-rsch-preview>';
        self::renderPreview( $preview );
        echo '</section>';

        $cancel = add_query_arg( [ 'tt_view' => FrontendReportStudioView::SLUG, 'id' => $report['id'] ], RecordLink::dashboardUrl() );
        echo FormSaveButton::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — helper escapes its own output.
            'label'      => $schedule !== null ? __( 'Save', 'talenttrack' ) : __( 'Schedule report', 'talenttrack' ),
            'cancel_url' => $cancel,
        ] );
        echo '</form></div>';

        wp_enqueue_script( 'tt-report-schedules', TT_PLUGIN_URL . 'assets/js/frontend-report-schedules.js', [], TT_VERSION, true );
        wp_localize_script( 'tt-report-schedules', 'TTReportSchedules', [
            'preview' => esc_url_raw( rest_url( 'talenttrack/v1/reports/' . $report['id'] . '/schedules/preview' ) ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
            'i18n'    => [
                'gets'      => __( 'What they get', 'talenttrack' ),
                'leftOut'   => __( 'Left out, so everyone may read the PDF', 'talenttrack' ),
                'none'      => __( 'Nothing: no block is one every recipient may read.', 'talenttrack' ),
                /* translators: %d: number of accounts that receive the PDF */
                'receiversOne'  => _n( 'Sent to %d account.', 'Sent to %d accounts.', 1, 'talenttrack' ),
                /* translators: %d: number of accounts that receive the PDF */
                'receiversMany' => _n( 'Sent to %d account.', 'Sent to %d accounts.', 2, 'talenttrack' ),
                'failed'    => __( 'What they get could not be worked out. Save, and the schedule list shows it.', 'talenttrack' ),
            ],
        ] );
    }

    /**
     * @param array{blocks: list<array{id:string, label:string}>, left_out: list<array{id:string, label:string, reason:string}>, receivers: int, left_out_recipients: int, message: string} $preview
     */
    private static function renderPreview( array $preview ): void {
        echo '<h2 class="tt-rsch-preview-title">' . esc_html__( 'What they get', 'talenttrack' ) . '</h2>';
        if ( $preview['message'] !== '' ) {
            echo '<p class="tt-notice">' . esc_html( $preview['message'] ) . '</p>';
            return;
        }
        /* translators: %d: number of accounts that receive the PDF */
        echo '<p class="tt-rsch-receivers">' . esc_html( sprintf( _n( 'Sent to %d account.', 'Sent to %d accounts.', $preview['receivers'], 'talenttrack' ), $preview['receivers'] ) ) . '</p>';
        if ( $preview['blocks'] === [] ) {
            echo '<p class="tt-notice">' . esc_html__( 'Nothing: no block is one every recipient may read.', 'talenttrack' ) . '</p>';
        } else {
            echo '<ul class="tt-rsch-blocks">';
            foreach ( $preview['blocks'] as $block ) echo '<li>' . esc_html( $block['label'] ) . '</li>';
            echo '</ul>';
        }
        if ( $preview['left_out'] !== [] ) {
            echo '<h3 class="tt-rsch-preview-sub">' . esc_html__( 'Left out, so everyone may read the PDF', 'talenttrack' ) . '</h3>';
            echo '<ul class="tt-rsch-left">';
            foreach ( $preview['left_out'] as $left ) {
                echo '<li><span class="tt-rsch-left-name">' . esc_html( $left['label'] ) . '</span> <span class="tt-rsch-left-why">' . esc_html( $left['reason'] ) . '</span></li>';
            }
            echo '</ul>';
        }
    }

    /** The form's save, through the service the REST routes call. */
    public static function handleSave(): void {
        check_admin_referer( self::ACTION, 'tt_rsch_nonce' );
        if ( ! current_user_can( 'tt_build_reports' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );

        $reader      = ReportReader::for( get_current_user_id() );
        $report_id   = isset( $_POST['report_id'] ) ? absint( wp_unslash( (string) $_POST['report_id'] ) ) : 0;
        $schedule_id = isset( $_POST['schedule_id'] ) ? absint( wp_unslash( (string) $_POST['schedule_id'] ) ) : 0;
        $report      = ( new ReportsRepository() )->find( $report_id );
        if ( $report === null ) wp_die( esc_html__( 'There is no such report.', 'talenttrack' ) );

        $raw = isset( $_POST['recipients'] ) && is_array( $_POST['recipients'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['recipients'] ) ) : [];
        $in  = [
            'name'       => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '',
            'recipients' => array_values( $raw ),
            'frequency'  => isset( $_POST['frequency'] ) ? sanitize_key( wp_unslash( (string) $_POST['frequency'] ) ) : '',
        ];
        if ( isset( $_POST['period'] ) ) $in['period'] = sanitize_key( wp_unslash( (string) $_POST['period'] ) );

        if ( $schedule_id > 0 ) {
            $found  = ReportSchedules::findForReader( $schedule_id, $reader );
            $result = $found instanceof StudioError ? $found : ReportSchedules::update( $found, $in, $reader );
            $back   = [ 'schedule' => (string) $schedule_id ];
        } else {
            $result = ReportSchedules::create( $report, $in, $reader );
            $back   = [ 'schedule' => 'new' ];
        }

        if ( $result instanceof StudioError ) {
            wp_safe_redirect( self::url( $report, $back + [ 'tt_rsch_error' => rawurlencode( $result->message ) ] ) );
            exit;
        }
        wp_safe_redirect( self::url( $report, [ 'schedules' => '1', 'tt_rsch_msg' => 'saved' ] ) );
        exit;
    }

    /** A saved schedule, or why the form was refused. */
    private static function notice(): void {
        if ( isset( $_GET['tt_rsch_msg'] ) ) {
            echo '<p class="tt-notice tt-notice-success" role="status">' . esc_html__( 'The schedule is saved. It sends from its next run.', 'talenttrack' ) . '</p>';
        }
        if ( isset( $_GET['tt_rsch_error'] ) ) {
            echo '<p class="tt-notice tt-notice-error" role="alert">' . esc_html( sanitize_text_field( rawurldecode( wp_unslash( (string) $_GET['tt_rsch_error'] ) ) ) ) . '</p>';
        }
    }

    /**
     * @param Report               $report
     * @param array<string,string> $args
     */
    private static function url( array $report, array $args ): string {
        return add_query_arg( [ 'tt_view' => FrontendReportStudioView::SLUG, 'id' => $report['id'] ] + $args, RecordLink::dashboardUrl() );
    }

    /**
     * Dashboard › Reports › Report Studio › the report (› Schedules).
     *
     * @param Report $report
     * @return list<array{label:string,url:string}>
     */
    private static function crumbs( array $report, string $report_name, bool $with_list ): array {
        $crumbs = [
            FrontendBreadcrumbs::viewCrumb( 'reports', __( 'Reports', 'talenttrack' ) ),
            FrontendBreadcrumbs::viewCrumb( FrontendReportStudioView::SLUG, __( 'Report Studio', 'talenttrack' ) ),
            FrontendBreadcrumbs::viewCrumb( FrontendReportStudioView::SLUG, $report_name, [ 'id' => $report['id'] ] ),
        ];
        if ( $with_list ) {
            $crumbs[] = FrontendBreadcrumbs::viewCrumb( FrontendReportStudioView::SLUG, __( 'Schedules', 'talenttrack' ), [ 'id' => $report['id'], 'schedules' => '1' ] );
        }
        return $crumbs;
    }
}
