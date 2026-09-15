<?php
namespace TT\Modules\Comms\Send;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Comms\CommsService;
use TT\Modules\Comms\Dispatch\CommsDispatcher;
use TT\Modules\Comms\Domain\CommsRequest;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\Recipient\RecipientResolver;

/**
 * SafeguardingBroadcastSender (#3423, epic #3384) — the send behind the
 * one message an academy's families cannot refuse.
 *
 * `MessageType::SAFEGUARDING_BROADCAST` has been operational since the
 * module shipped: `optOutable()` excludes it, quiet hours do not hold it,
 * and *My settings* tells every parent, today, that safeguarding messages
 * cannot be switched off. Everything was in place except a way to send
 * one — which made that sentence a promise the product could not keep.
 *
 * ## Who may send one
 *
 * `tt_send_safeguarding_broadcast`, held by the WordPress administrator
 * and the Academy admin role and by nobody else out of the box. Reusing
 * `tt_send_email` was the obvious shortcut and is the wrong answer: every
 * coach holds it, and a message that reaches every family and cannot be
 * refused is not the same act as emailing one parent. An academy whose
 * safeguarding lead is not an academy admin grants them the capability —
 * a deliberate, recorded widening rather than a default.
 *
 * ## What it reaches
 *
 * Academy-wide, or one team. A safeguarding concern is often about one
 * squad, and a message nobody can refuse should reach the smallest set of
 * families it genuinely concerns; sending a second one when the concern
 * turns out to be wider costs less than having reached everybody the
 * first time. The caller must say which — there is no implicit default,
 * because the implicit default would be "everyone".
 *
 * Recipients are every active player's family and, where they are old
 * enough to hold an account, the player: `forPlayerWithParents()` rather
 * than the age-tiered `forPlayer()`, because for this message a parent is
 * never dropped on the grounds that their child is 15.
 *
 * **One message per person.** A parent with two children in the academy
 * receives one copy, not two. The log row it leaves is attached to
 * whichever of their children resolved first, which is the one place this
 * departs from the player-centric shape of every other send — and it is
 * the right departure: the message is about the academy, not about a
 * child, and sending it twice to the same address helps nobody.
 *
 * Everything dispatches through `CommsService`, like every other send, so
 * `tt_comms_log` stays the single record of what the academy sent.
 */
final class SafeguardingBroadcastSender {

    /** The capability that gates composing and sending one. */
    public const CAP = 'tt_send_safeguarding_broadcast';

    public const SCOPE_ACADEMY = 'academy';
    public const SCOPE_TEAM    = 'team';

    public const TEMPLATE_KEY = 'safeguarding_broadcast';

    /**
     * Everyone a broadcast with this audience would reach, deduplicated.
     *
     * @return Recipient[]
     */
    public function audience( string $scope, int $teamId = 0 ): array {
        $players = $this->playerIds( $scope, $teamId );
        if ( $players === [] ) return [];

        $resolver = new RecipientResolver();
        $out      = [];

        foreach ( $players as $playerId ) {
            foreach ( $resolver->forPlayerWithParents( $playerId ) as $recipient ) {
                $key = self::identityKey( $recipient );
                if ( $key === '' || isset( $out[ $key ] ) ) continue;
                $out[ $key ] = $recipient;
            }
        }

        return array_values( $out );
    }

    /**
     * How many people the broadcast would reach. The number the confirm
     * step shows, so it is counted rather than estimated.
     */
    public function recipientCount( string $scope, int $teamId = 0 ): int {
        return count( $this->audience( $scope, $teamId ) );
    }

    /**
     * Send it.
     *
     * @return CommsResult[] one per recipient; empty when the audience
     *                       resolved to nobody, which the caller reports
     *                       rather than treating as a send.
     */
    public function send( string $subject, string $body, string $scope, int $teamId = 0 ): array {
        $recipients = $this->audience( $scope, $teamId );
        if ( $recipients === [] ) return [];

        return CommsDispatcher::dispatchSync(
            self::TEMPLATE_KEY,
            [
                'broadcast_subject' => $subject,
                'broadcast_body'    => $body,
                'sender_name'       => self::senderName(),
                'sender_role'       => __( 'Safeguarding', 'talenttrack' ),
            ],
            $recipients,
            [ 'message_type' => MessageType::SAFEGUARDING_BROADCAST ]
        );
    }

    /**
     * Dry run of the same send, for the confirm step's warnings.
     *
     * @return CommsResult[]
     */
    public function preflight( string $scope, int $teamId = 0 ): array {
        $recipients = $this->audience( $scope, $teamId );
        if ( $recipients === [] ) return [];

        return ( new CommsService() )->preflight( new CommsRequest(
            self::TEMPLATE_KEY,
            MessageType::SAFEGUARDING_BROADCAST,
            CurrentClub::id(),
            get_current_user_id(),
            $recipients
        ) );
    }

    /** Normalise a caller-supplied scope; anything unrecognised is team-less academy scope. */
    public static function sanitizeScope( string $raw ): string {
        return $raw === self::SCOPE_TEAM ? self::SCOPE_TEAM : self::SCOPE_ACADEMY;
    }

    /**
     * The players whose families the broadcast reaches.
     *
     * Archived and trashed players are excluded through the shared
     * `filterClause()` — a family whose child left the academy last season
     * is not on the distribution list for a concern about this one.
     *
     * @return list<int>
     */
    private function playerIds( string $scope, int $teamId ): array {
        global $wpdb;
        $table  = $wpdb->prefix . 'tt_players';
        $active = ArchiveRepository::filterClause( 'active' );
        $club   = QueryHelpers::clubScopeWhere();

        if ( $scope === self::SCOPE_TEAM ) {
            if ( $teamId <= 0 ) return [];
            $rows = $wpdb->get_col( $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT id FROM {$table} WHERE {$club} AND {$active} AND team_id = %d ORDER BY id ASC",
                $teamId
            ) );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_col(
                "SELECT id FROM {$table} WHERE {$club} AND {$active} ORDER BY id ASC"
            );
        }

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $id ) {
            $id = (int) $id;
            if ( $id > 0 ) $out[] = $id;
        }
        return $out;
    }

    /**
     * What makes two resolved recipients the same person: the account
     * where there is one, otherwise the address the message would go to.
     * A parent with no account and no contact details has no identity to
     * deduplicate on and no way to be reached, so they are dropped here
     * rather than becoming an unreachable row per child.
     */
    private static function identityKey( Recipient $recipient ): string {
        if ( $recipient->userId > 0 ) return 'u:' . $recipient->userId;
        $email = strtolower( trim( $recipient->emailAddress ) );
        if ( $email !== '' ) return 'e:' . $email;
        $phone = trim( $recipient->phoneE164 );
        if ( $phone !== '' ) return 'p:' . $phone;
        return '';
    }

    private static function senderName(): string {
        $name = trim( (string) wp_get_current_user()->display_name );
        return $name !== '' ? $name : __( 'The academy', 'talenttrack' );
    }
}
