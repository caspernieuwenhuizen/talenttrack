<?php
namespace TT\Modules\Trials\Reminders;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Trials\Repositories\TrialCaseStaffRepository;
use TT\Modules\Trials\Repositories\TrialCasesRepository;
use TT\Modules\Trials\Repositories\TrialStaffInputsRepository;

/**
 * Daily reminder cron — emails assigned staff who haven't submitted
 * input on cases ending in 7 days, 3 days, or already past end_date.
 *
 * WP-cron is unreliable on low-traffic sites; the manual "Send
 * reminders now" button on the editor calls dispatch() directly.
 *
 * Per-(case,user,bucket) tracking lives in `wp_usermeta` so a user
 * gets at most one email per bucket per case.
 */
final class TrialReminderScheduler {

    public const HOOK = 'tt_trial_send_reminders';

    public static function init(): void {
        add_action( self::HOOK, [ self::class, 'dispatch' ] );

        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( strtotime( 'tomorrow 06:00' ) ?: time() + 3600, 'daily', self::HOOK );
        }
    }

    public static function dispatch(): int {
        $cases_repo = new TrialCasesRepository();
        $staff_repo = new TrialCaseStaffRepository();
        $inputs     = new TrialStaffInputsRepository();

        $today = gmdate( 'Y-m-d' );
        $cases = $cases_repo->listEndingBetween(
            gmdate( 'Y-m-d', strtotime( '-30 days' ) ?: time() ),
            gmdate( 'Y-m-d', strtotime( '+30 days' ) ?: time() )
        );

        $sent = 0;
        foreach ( $cases as $case ) {
            $end_ts   = strtotime( (string) $case->end_date );
            $today_ts = strtotime( $today );
            if ( ! $end_ts || ! $today_ts ) continue;

            $days_remaining = (int) floor( ( $end_ts - $today_ts ) / 86400 );

            $bucket = null;
            if ( $days_remaining === 7 )      $bucket = 't-7';
            elseif ( $days_remaining === 3 )  $bucket = 't-3';
            elseif ( $days_remaining <= 0 )   $bucket = 't-0';
            if ( ! $bucket ) continue;

            $assigned = $staff_repo->listForCase( (int) $case->id );
            foreach ( $assigned as $row ) {
                $user_id = (int) $row->user_id;
                $existing = $inputs->findForCaseUser( (int) $case->id, $user_id );
                if ( $existing && $existing->submitted_at ) continue;

                $meta_key = 'tt_trial_reminder_' . (int) $case->id . '_' . $bucket;
                if ( get_user_meta( $user_id, $meta_key, true ) ) continue;

                if ( self::sendReminderEmail( $user_id, $case, $days_remaining ) ) {
                    update_user_meta( $user_id, $meta_key, time() );
                    $sent++;
                }
            }
        }
        return $sent;
    }

    private static function sendReminderEmail( int $user_id, object $case, int $days_remaining ): bool {
        $user = get_userdata( $user_id );
        if ( ! $user || ! $user->user_email ) return false;

        $player = QueryHelpers::get_player( (int) $case->player_id );
        $name   = $player ? QueryHelpers::player_display_name( $player ) : '#' . (int) $case->player_id;

        $case_url = add_query_arg( [
            'tt_view' => 'trial-case', 'id' => (int) $case->id, 'tab' => 'inputs',
        ], home_url( '/' ) );

        $subject = sprintf(
            /* translators: 1: player name, 2: days remaining (negative if past) */
            __( 'Trial input needed: %1$s (%2$d days)', 'talenttrack' ),
            $name,
            $days_remaining
        );
        $body = sprintf(
            __( "Hi %1\$s,\n\nThe trial period for %2\$s is ending. Your input on the case is still needed.\n\nGo to the case here: %3\$s\n\nThanks,\n%4\$s", 'talenttrack' ),
            $user->display_name,
            $name,
            $case_url,
            get_bloginfo( 'name' ) ?: __( 'The club', 'talenttrack' )
        );

        return (bool) wp_mail( $user->user_email, $subject, $body );
    }
}
