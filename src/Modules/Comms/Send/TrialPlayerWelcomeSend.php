<?php
namespace TT\Modules\Comms\Send;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Comms\Dispatch\CommsDispatcher;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\Recipient\RecipientResolver;
use TT\Modules\Comms\Templates\TrialPlayerWelcomeTemplate;

/**
 * TrialPlayerWelcomeSend (#2605 Gate D) — use case 5's trigger.
 *
 * ## The trigger point
 *
 * `tt_trial_started` is fired by `TrialCasesRepository::create()`, which
 * #3130 made the single announcing point precisely so that a trial opened
 * through the REST controller, the trials-manage form, the new-player
 * wizard or the demo generator all behave identically. Listening on the
 * repository's hook rather than on any one screen is what keeps the
 * welcome from depending on which door the coach came through.
 *
 * ## What this message may promise
 *
 * A trial case carries a player, a track, a start date and an end date.
 * It has no location and no kit list, so the copy says a coach will
 * follow up with those rather than printing empty labels at a family —
 * see {@see TrialPlayerWelcomeTemplate} for why that is a deliberate
 * choice and not a shortcut.
 *
 * ## The demo generator
 *
 * `PipelineGenerator` fires `tt_trial_started` too, which is correct for
 * the journey timeline and would be wrong here — a demo install must not
 * mail anybody. That is handled where it belongs: the whole channel is a
 * no-op without real recipients, and per-template sending is the Gate B
 * switch's business (#2603), not this trigger's.
 */
final class TrialPlayerWelcomeSend {

    public static function init(): void {
        add_action( 'tt_trial_started', [ __CLASS__, 'handle' ], 10, 2 );
    }

    /**
     * Action-hook entry point. `do_action()` has nowhere to put a return
     * value; the obligation here is the audit trail, which
     * `CommsDispatcher` writes.
     */
    public static function handle( int $case_id, int $player_id = 0 ): void {
        self::send( $case_id, $player_id );
    }

    /**
     * @return CommsResult[]
     */
    public static function send( int $case_id, int $player_id = 0 ): array {
        if ( $case_id <= 0 ) return [];

        $case = self::loadCase( $case_id );
        if ( $case === null ) return [];

        if ( $player_id <= 0 ) {
            $player_id = (int) ( $case->player_id ?? 0 );
        }
        if ( $player_id <= 0 ) return [];

        return CommsDispatcher::dispatchSync(
            TrialPlayerWelcomeTemplate::KEY,
            self::payload( $case, $player_id ),
            ( new RecipientResolver() )->forPlayer( $player_id ),
            [
                'message_type'   => MessageType::TRIAL_PLAYER_WELCOME,
                'sender_user_id' => 0,
            ]
        );
    }

    /**
     * The two tokens the template declares.
     *
     * The date is rendered in the site's own format rather than the stored
     * ISO string: this is read by a parent, not by a developer.
     *
     * @return array<string, scalar|null>
     */
    private static function payload( object $case, int $player_id ): array {
        $date  = (string) ( $case->start_date ?? '' );
        $stamp = $date !== '' ? strtotime( $date ) : false;

        return [
            'player_name' => self::playerName( $player_id ),
            'start_date'  => $stamp !== false ? date_i18n( (string) get_option( 'date_format' ), $stamp ) : $date,
        ];
    }

    private static function loadCase( int $case_id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, player_id, start_date
               FROM {$wpdb->prefix}tt_trial_cases
              WHERE id = %d AND club_id = %d
              LIMIT 1",
            $case_id,
            CurrentClub::id()
        ) );
        return $row ?: null;
    }

    private static function playerName( int $player_id ): string {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT first_name, last_name FROM {$wpdb->prefix}tt_players WHERE id = %d AND club_id = %d LIMIT 1",
            $player_id,
            CurrentClub::id()
        ) );
        if ( ! $row ) return '';
        return trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) );
    }
}
