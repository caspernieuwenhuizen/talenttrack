<?php
namespace TT\Modules\Comms\Send;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Activities\Repositories\ActivitiesRepository;
use TT\Modules\Comms\Dispatch\CommsDispatcher;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\Recipient\RecipientResolver;
use TT\Modules\Comms\Templates\TrainingCancelledTemplate;

/**
 * TrainingCancelledSend (#3081, #2605 Gate D) — use case 1's trigger.
 *
 * Listens on `tt_activity_cancelled`, which `ActivitiesRepository` fires
 * from both of its cancellation write paths. The template shipped at
 * v3.110.18 naming a hook nobody raised, so the message could never send;
 * this is the half that was missing.
 *
 * Recipients come from the activity's planned roster, each player run
 * through `RecipientResolver` so the #0042 youth-contact rules decide
 * parent-or-player rather than this class doing it. A parent with two
 * children on the same roster is deduplicated — the copy is about the
 * activity, not about a named child, so two identical emails would only
 * read as a bug.
 *
 * Whether anything actually leaves the building is the template switch's
 * business (#2603) and the opt-out registry's; this class only says that
 * the cancellation happened.
 */
final class TrainingCancelledSend {

    public static function init(): void {
        add_action( 'tt_activity_cancelled', [ __CLASS__, 'handle' ], 10, 1 );
    }

    /**
     * @return CommsResult[] Discarded by the action hook; returned so a
     *                       test (or a future sync caller) can read the
     *                       per-recipient outcome.
     */
    public static function handle( int $activity_id ): array {
        if ( $activity_id <= 0 ) return [];

        $repo     = new ActivitiesRepository();
        $activity = $repo->findByIdIncludingArchived( $activity_id );
        if ( $activity === null ) return [];

        return CommsDispatcher::dispatchSync(
            TrainingCancelledTemplate::KEY,
            self::payload( $activity ),
            self::recipients( $repo, $activity_id ),
            [
                'message_type' => MessageType::TRAINING_CANCELLED,
                // A system event, not a person clicking send. The audit
                // row records the club, not an operator.
                'sender_user_id' => 0,
                // A cancelled training is time-critical; MessageType
                // already bypasses quiet hours for this type, and the
                // flag makes the intent explicit at the call site.
                'urgent' => true,
            ]
        );
    }

    /**
     * The three tokens the template declares.
     *
     * @return array<string, scalar|null>
     */
    private static function payload( object $activity ): array {
        $date = (string) ( $activity->session_date ?? '' );
        $stamp = $date !== '' ? strtotime( $date ) : false;

        return [
            'activity_title' => (string) ( $activity->title ?? '' ),
            'date'           => $stamp !== false ? date_i18n( (string) get_option( 'date_format' ), $stamp ) : $date,
            'team_name'      => (string) ( $activity->team_name ?? '' ),
        ];
    }

    /**
     * One recipient list for the whole activity, deduplicated across the
     * roster.
     *
     * @return Recipient[]
     */
    private static function recipients( ActivitiesRepository $repo, int $activity_id ): array {
        $resolver = new RecipientResolver();
        $out      = [];
        $seen     = [];

        foreach ( $repo->plannedRosterForActivity( $activity_id ) as $row ) {
            $player_id = (int) ( $row->player_id ?? 0 );
            if ( $player_id <= 0 ) continue;

            foreach ( $resolver->forPlayer( $player_id ) as $recipient ) {
                // A linked account dedupes on its user id; the legacy
                // guardian-fields fallback has no account, so it dedupes
                // on whatever address it does have.
                $key = $recipient->userId > 0
                    ? 'u:' . $recipient->userId
                    : 'c:' . strtolower( $recipient->emailAddress ) . '|' . $recipient->phoneE164;
                if ( isset( $seen[ $key ] ) ) continue;
                $seen[ $key ] = true;
                $out[] = $recipient;
            }
        }

        return $out;
    }
}
