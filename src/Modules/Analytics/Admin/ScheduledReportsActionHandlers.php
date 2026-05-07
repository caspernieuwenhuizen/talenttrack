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

    public static function init(): void {
        add_action( 'admin_post_' . self::ACTION_CREATE,  [ self::class, 'handleCreate' ] );
        add_action( 'admin_post_' . self::ACTION_PAUSE,   [ self::class, 'handlePause' ] );
        add_action( 'admin_post_' . self::ACTION_RESUME,  [ self::class, 'handleResume' ] );
        add_action( 'admin_post_' . self::ACTION_ARCHIVE, [ self::class, 'handleArchive' ] );
    }

    public static function handleCreate(): void {
        self::guard();
        check_admin_referer( self::ACTION_CREATE, 'tt_sched_nonce' );

        $name      = isset( $_POST['name'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) ) : '';
        $kpi_key   = isset( $_POST['kpi_key'] ) ? sanitize_key( (string) $_POST['kpi_key'] ) : '';
        $frequency = isset( $_POST['frequency'] ) ? sanitize_key( (string) $_POST['frequency'] ) : '';
        $rec_raw   = isset( $_POST['recipients'] ) ? (string) wp_unslash( (string) $_POST['recipients'] ) : '';

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
        ( new ScheduledReportsRepository() )->setStatus( $id, $new_status );
        self::redirectBack( $msg );
    }

    private static function guard(): void {
        if ( ! current_user_can( 'tt_view_analytics' ) ) {
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
