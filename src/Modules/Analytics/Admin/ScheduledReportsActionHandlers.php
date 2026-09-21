<?php
namespace TT\Modules\Analytics\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\KpiRegistry;
use TT\Modules\Analytics\ScheduledReportsRepository;

/**
 * ScheduledReportsActionHandlers — admin-post endpoints for the
 * scheduled-reports management view (#0083 Child 6).
 *
 * Four endpoints — create / pause / resume / archive. Edit is
 * deferred (operators pause + recreate). All gated on
 * `tt_view_analytics` (same cap as the management view; HoD + Admin).
 */
final class ScheduledReportsActionHandlers {

    public const ACTION_CREATE  = 'tt_scheduled_reports_create';
    public const ACTION_PAUSE   = 'tt_scheduled_reports_pause';
    public const ACTION_RESUME  = 'tt_scheduled_reports_resume';
    public const ACTION_ARCHIVE = 'tt_scheduled_reports_archive';
    public const ACTION_DELETE  = 'tt_scheduled_reports_delete';

    public static function init(): void {
        add_action( 'admin_post_' . self::ACTION_CREATE,  [ self::class, 'handleCreate' ] );
        add_action( 'admin_post_' . self::ACTION_PAUSE,   [ self::class, 'handlePause' ] );
        add_action( 'admin_post_' . self::ACTION_RESUME,  [ self::class, 'handleResume' ] );
        add_action( 'admin_post_' . self::ACTION_ARCHIVE, [ self::class, 'handleArchive' ] );
        add_action( 'admin_post_' . self::ACTION_DELETE,  [ self::class, 'handleDeletePermanent' ] );
    }

    /**
     * #1808 — permanently delete a scheduled report (irreversible). Routes
     * through the referential-integrity framework (no children — just the
     * row). Gated by tt_edit_settings, above the view's tt_view_analytics.
     */
    public static function handleDeletePermanent(): void {
        if ( ! current_user_can( 'tt_edit_settings' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }
        check_admin_referer( self::ACTION_DELETE, 'tt_sched_nonce' );
        $id = isset( $_POST['schedule_id'] ) ? (int) $_POST['schedule_id'] : 0;
        if ( $id <= 0 ) self::redirectBack( 'schedule_invalid' );
        try {
            ( new \TT\Infrastructure\Archive\ArchiveRepository() )->deletePermanently( 'scheduled_report', [ $id ] );
        } catch ( \TT\Infrastructure\Archive\DeleteBlockedException $e ) {
            self::redirectBack( 'schedule_delete_blocked' );
        }
        self::redirectBack( 'schedule_deleted' );
    }

    public static function handleCreate(): void {
        self::guard();
        check_admin_referer( self::ACTION_CREATE, 'tt_sched_nonce' );

        $name      = isset( $_POST['name'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) ) : '';
        $kpi_key   = isset( $_POST['kpi_key'] ) ? sanitize_key( (string) $_POST['kpi_key'] ) : '';
        $frequency = isset( $_POST['frequency'] ) ? sanitize_key( (string) $_POST['frequency'] ) : '';
        $rec_raw   = isset( $_POST['recipients'] ) ? (string) wp_unslash( (string) $_POST['recipients'] ) : '';

        $report_key = isset( $_POST['report_key'] ) ? sanitize_key( (string) $_POST['report_key'] ) : '';
        if ( $report_key === ScheduledReportsRepository::REPORT_TEAM_MONTHLY ) {
            self::createTeamMonthly( $name, $rec_raw );
        }
        if ( $report_key === ScheduledReportsRepository::REPORT_PLAYER ) {
            self::createPlayerReport( $name, $rec_raw );
        }

        $valid_frequencies = [
            ScheduledReportsRepository::FREQUENCY_WEEKLY_MONDAY,
            ScheduledReportsRepository::FREQUENCY_MONTHLY_FIRST,
            ScheduledReportsRepository::FREQUENCY_SEASON_END,
        ];
        if ( $name === ''
             || KpiRegistry::find( $kpi_key ) === null
             || ! in_array( $frequency, $valid_frequencies, true )
        ) {
            self::redirectBack( 'schedule_invalid' );
        }

        $recipients = array_values( array_filter( array_map( 'trim', preg_split( "/[\r\n]+/", $rec_raw ) ?: [] ) ) );
        if ( empty( $recipients ) ) {
            self::redirectBack( 'schedule_invalid' );
        }

        ( new ScheduledReportsRepository() )->create(
            [
                'name'       => $name,
                'kpi_key'    => $kpi_key,
                'frequency'  => $frequency,
                'recipients' => $recipients,
                'format'     => 'csv',
            ],
            get_current_user_id()
        );

        self::redirectBack( 'schedule_created' );
    }

    /**
     * #3462 — a team monthly schedule. The composition is copied onto the
     * schedule; the window is always the month before the run. The creator
     * must be able to read the team's reports, because every run is rendered
     * as them.
     */
    private static function createTeamMonthly( string $name, string $rec_raw ): void {
        $composition = \TT\Modules\Analytics\Reports\TeamMonthlyReportComposition::normalise( [
            'team_id' => isset( $_POST['team_id'] ) ? absint( $_POST['team_id'] ) : 0,
            'layout'  => isset( $_POST['layout'] ) ? sanitize_key( (string) $_POST['layout'] ) : '',
            'blocks'  => isset( $_POST['blocks'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['blocks'] ) ) : '',
            'period'  => \TT\Modules\Analytics\Reports\TeamMonthlyReport::DEFAULT_PERIOD,
        ] );
        $recipients = array_values( array_filter( array_map( 'trim', preg_split( "/[\r\n]+/", $rec_raw ) ?: [] ) ) );

        if ( $name === ''
             || $recipients === []
             || $composition['team_id'] <= 0
             || ! \TT\Modules\Analytics\Reports\TeamReportAccess::canRead( get_current_user_id(), $composition['team_id'] )
        ) {
            self::redirectBack( 'schedule_invalid' );
        }

        ( new ScheduledReportsRepository() )->create(
            [
                'name'        => $name,
                'report_key'  => ScheduledReportsRepository::REPORT_TEAM_MONTHLY,
                'composition' => $composition,
                'frequency'   => ScheduledReportsRepository::FREQUENCY_MONTHLY_FIRST,
                'recipients'  => $recipients,
                'format'      => 'pdf',
            ],
            get_current_user_id()
        );

        self::redirectBack( 'schedule_created' );
    }

    /**
     * #3891 — a player report schedule, for a squad or one player. The
     * composition is copied onto the schedule; a squad target also carries
     * `team_id`. The creator must be able to read what it covers, because
     * every run is rendered as them.
     */
    private static function createPlayerReport( string $name, string $rec_raw ): void {
        $period = isset( $_POST['period'] ) ? sanitize_key( (string) $_POST['period'] ) : '';
        if ( ! in_array( $period, \TT\Modules\Analytics\Reports\ReportFilters::PERIODS, true ) ) {
            $period = \TT\Modules\Analytics\Reports\PlayerReportComposition::DEFAULT_PERIOD;
        }
        $composition = \TT\Modules\Analytics\Reports\PlayerReportComposition::normalise( [
            'player_id' => isset( $_POST['player_id'] ) ? absint( $_POST['player_id'] ) : 0,
            'layout'    => isset( $_POST['layout'] ) ? sanitize_key( (string) $_POST['layout'] ) : '',
            'blocks'    => isset( $_POST['blocks'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['blocks'] ) ) : '',
            'period'    => $period,
            // #3989 — the schedule keeps the evaluations detail too.
            'options'   => isset( $_POST['options'] ) ? wp_unslash( (string) $_POST['options'] ) : '', // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized -- normalised by the composition.
        ] );
        $recipients = array_values( array_filter( array_map( 'trim', preg_split( "/[\r\n]+/", $rec_raw ) ?: [] ) ) );
        $user_id    = get_current_user_id();
        $target     = isset( $_POST['target'] ) ? sanitize_key( (string) $_POST['target'] ) : 'player';
        $team_id    = $target === 'team' && isset( $_POST['team_id'] ) ? absint( $_POST['team_id'] ) : 0;

        $allowed = $team_id > 0
            ? \TT\Modules\Analytics\Reports\TeamReportAccess::canRead( $user_id, $team_id )
            : \TT\Modules\Analytics\Reports\PlayerReportAccess::canRead( $user_id, $composition['player_id'] );

        if ( $name === '' || $recipients === [] || ! $allowed ) {
            self::redirectBack( 'schedule_invalid' );
        }

        if ( $team_id > 0 ) {
            $composition['team_id'] = $team_id;
        }

        ( new ScheduledReportsRepository() )->create(
            [
                'name'        => $name,
                'report_key'  => ScheduledReportsRepository::REPORT_PLAYER,
                'composition' => $composition,
                'frequency'   => ScheduledReportsRepository::FREQUENCY_MONTHLY_FIRST,
                'recipients'  => $recipients,
                'format'      => 'pdf',
            ],
            $user_id
        );

        self::redirectBack( 'schedule_created' );
    }

    public static function handlePause(): void {
        self::changeStatus( self::ACTION_PAUSE, ScheduledReportsRepository::STATUS_PAUSED, 'schedule_paused' );
    }

    public static function handleResume(): void {
        self::changeStatus( self::ACTION_RESUME, ScheduledReportsRepository::STATUS_ACTIVE, 'schedule_resumed' );
    }

    public static function handleArchive(): void {
        self::changeStatus( self::ACTION_ARCHIVE, ScheduledReportsRepository::STATUS_ARCHIVED, 'schedule_archived' );
    }

    private static function changeStatus( string $nonce_action, string $new_status, string $msg ): void {
        self::guard();
        check_admin_referer( $nonce_action, 'tt_sched_nonce' );
        $id = isset( $_POST['schedule_id'] ) ? (int) $_POST['schedule_id'] : 0;
        if ( $id <= 0 ) self::redirectBack( 'schedule_invalid' );
        $repo = new ScheduledReportsRepository();
        $repo->setStatus( $id, $new_status );
        // #3462 — resuming is the answer to a stopped schedule; its reason
        // no longer describes it. The next run re-checks and says so again
        // if nothing was fixed.
        if ( $new_status === ScheduledReportsRepository::STATUS_ACTIVE ) {
            $repo->clearError( $id );
        }
        self::redirectBack( $msg );
    }

    /**
     * #3832 — the same pair the management view checks. A refusal on the
     * screen and an open write handler behind it is not a refusal; the
     * capability is true for a team-scoped analytics grant, and schedules
     * are academy-wide.
     */
    private static function guard(): void {
        $user_id = get_current_user_id();
        if ( ! current_user_can( 'tt_view_analytics' )
            || ! ( current_user_can( 'tt_edit_settings' )
                || \TT\Modules\Authorization\AllTeamsScope::canSeeClubWideAnalytics( $user_id ) )
        ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }
    }

    private static function redirectBack( string $msg ): void {
        $base = class_exists( '\\TT\\Shared\\Wizards\\WizardEntryPoint' )
            ? \TT\Shared\Wizards\WizardEntryPoint::dashboardBaseUrl()
            : home_url( '/' );
        wp_safe_redirect( add_query_arg(
            [ 'tt_view' => 'scheduled-reports', 'tt_msg' => $msg ],
            $base
        ) );
        exit;
    }
}
