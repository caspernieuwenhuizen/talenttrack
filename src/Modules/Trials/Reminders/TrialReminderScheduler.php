<?php
namespace TT\Modules\Trials\Reminders;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\Dispatch\CommsDispatcher;
use TT\Modules\Comms\Templates\TrialInputReminderTemplate;
use TT\Modules\Trials\Repositories\TrialCaseStaffRepository;
use TT\Modules\Trials\Repositories\TrialCasesRepository;
use TT\Modules\Trials\Repositories\TrialStaffInputsRepository;

/**
 * Daily reminder cron — emails assigned staff who haven't submitted
 * input on cases ending in 7 days, 3 days, or already past end_date.
 *
 * WP-cron is unreliable on low-traffic sites; the manual "Send
 * reminders now" button on the editor calls `run()` directly and shows
 * the outcome per recipient.
 *
 * Per-(case,user,bucket) tracking lives in `wp_usermeta` so a user
 * gets at most one email per bucket per case.
 *
 * Delivery goes through Comms (#2604), which means the bucket is stamped
 * only on a send that actually left — a reminder held for quiet hours is
 * left unstamped so tomorrow's run tries it again, rather than being
 * marked done and never sent.
 */
final class TrialReminderScheduler {

    public const HOOK = 'tt_trial_send_reminders';

    public static function init(): void {
        add_action( self::HOOK, [ self::class, 'dispatch' ] );

        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( strtotime( 'tomorrow 06:00' ) ?: time() + 3600, 'daily', self::HOOK );
        }
    }

    /** Cron entry point. Returns how many reminders actually went out. */
    public static function dispatch(): int {
        return count( array_filter(
            self::run(),
            static fn ( CommsResult $result ): bool => $result->isSuccess()
        ) );
    }

    /**
     * Run the sweep and hand back one result per attempted recipient, so
     * the "Send reminders now" button can say what happened to each.
     *
     * @return CommsResult[]
     */
    public static function run(): array {
        $cases_repo = new TrialCasesRepository();
        $staff_repo = new TrialCaseStaffRepository();
        $inputs     = new TrialStaffInputsRepository();

        $today = gmdate( 'Y-m-d' );
        $cases = $cases_repo->listEndingBetween(
            gmdate( 'Y-m-d', strtotime( '-30 days' ) ?: time() ),
            gmdate( 'Y-m-d', strtotime( '+30 days' ) ?: time() )
        );

        $results = [];
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

                $attempt = self::sendReminder( $user_id, $case, $end_ts );
                foreach ( $attempt as $result ) {
                    $results[] = $result;
                    // Stamped on a send that left, not on one Comms held
                    // back. A quiet-hours defer stays unstamped so the next
                    // run picks it up again.
                    if ( $result->isSuccess() ) {
                        update_user_meta( $user_id, $meta_key, time() );
                    }
                }
            }
        }
        return $results;
    }

    /**
     * @return CommsResult[]
     */
    private static function sendReminder( int $user_id, object $case, int $end_ts ): array {
        $email = \TT\Infrastructure\Identity\ContactResolver::emailForUser( $user_id );
        if ( $email === null || $email === '' ) return [];

        $player    = QueryHelpers::get_player( (int) $case->player_id );
        $player_id = (int) $case->player_id;
        $name      = $player ? QueryHelpers::player_display_name( $player ) : '#' . $player_id;

        $case_url = add_query_arg( [
            'tt_view' => 'trial-case', 'id' => (int) $case->id, 'tab' => 'inputs',
        ], \TT\Shared\Frontend\Components\RecordLink::dashboardUrl() );

        $end_date = wp_date( (string) QueryHelpers::get_config( 'date_format', 'Y-m-d' ), $end_ts );

        $recipient = new Recipient(
            $user_id,
            Recipient::KIND_COACH,
            $player_id,
            $email,
            '',
            (string) get_user_meta( $user_id, 'locale', true )
        );

        return CommsDispatcher::dispatchSync(
            TrialInputReminderTemplate::KEY,
            [
                'player_name' => $name,
                'end_date'    => $end_date,
                'case_url'    => $case_url,
                'club_name'   => get_bloginfo( 'name' ) ?: __( 'The club', 'talenttrack' ),
            ],
            [ $recipient ],
            [
                'message_type'   => MessageType::TRIAL_INPUT_REMINDER,
                'sender_user_id' => 0,
            ]
        );
    }
}
