<?php
namespace TT\Modules\Comms\Recipient;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Identity\ContactResolver;
use TT\Infrastructure\Players\ParentChildResolver;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\AgeTier;
use TT\Modules\Comms\Domain\Recipient;

/**
 * RecipientResolver (#0066, #0042 enforcer) — translates a "who is this
 * message about" intent into the concrete `Recipient[]` the dispatcher
 * delivers to. This is the single point that enforces the youth-contact
 * rules from #0042 across every Comms use case; callers never need to
 * decide "should this go to the player or the parent" themselves.
 *
 * Rules per #0042:
 *
 *   - **U8-U10** (`AgeTier::U8_U10`): parent only. The player has no
 *     direct contact surface — phone/email reach is rare in this cohort.
 *     If no parent is linked, the resolver returns an empty list and
 *     the audit trail records "no reachable recipient" (the caller's
 *     UI should warn the operator before sending).
 *   - **U11-U12** (`AgeTier::U11_U12`): player primary (push / phone),
 *     parent fallback. The resolver returns BOTH so the dispatcher's
 *     channel-resolver can pick: push if the player has subscriptions,
 *     email to the parent otherwise.
 *   - **U12+** (`AgeTier::U12_PLUS`): player primary; parent NOT cc'd
 *     by default. Per spec note the 16-17 cohort "may be cc'd to parent
 *     depending on club policy" — the policy bit lands when the club
 *     setting exists. v1 leaves the parent off for U12+ unless the
 *     caller explicitly asks (see `forPlayerWithParents()`).
 *   - **Unknown** (no birthdate): player + parent both. Conservative
 *     default until the operator fills in the DOB.
 *
 * Coaches and staff don't go through this resolver — callers use
 * `Recipient::coach()` directly. The resolver only handles "message
 * about a player."
 *
 * Guardians are whoever `ParentChildResolver::guardiansOf()` says they
 * are (#3979): a released, archived or binned child's family is sent
 * nothing about them. The same rule holds for the player's own account
 * (#4088): a closed-out player resolves to nobody at all. The player is
 * loaded within the current club, so an id from another club resolves
 * to nobody too.
 *
 * Stateless.
 */
final class RecipientResolver {

    /**
     * Resolve the recipients for one player, applying the #0042 rules.
     *
     * @return Recipient[]
     */
    public function forPlayer( int $playerId ): array {
        if ( $playerId <= 0 ) return [];

        $player = self::loadPlayer( $playerId );
        if ( $player === null ) return [];

        $tier = AgeTier::forPlayer( $playerId );

        switch ( $tier ) {
            case AgeTier::U8_U10:
                return $this->parentsOf( $playerId, $player );

            case AgeTier::U11_U12:
                $recipients = [];
                $self = self::buildSelf( $player );
                if ( $self !== null ) $recipients[] = $self;
                foreach ( $this->parentsOf( $playerId, $player ) as $parent ) {
                    $recipients[] = $parent;
                }
                return $recipients;

            case AgeTier::U12_PLUS:
                $self = self::buildSelf( $player );
                return $self !== null ? [ $self ] : $this->parentsOf( $playerId, $player );

            case AgeTier::UNKNOWN:
            default:
                $recipients = [];
                $self = self::buildSelf( $player );
                if ( $self !== null ) $recipients[] = $self;
                foreach ( $this->parentsOf( $playerId, $player ) as $parent ) {
                    $recipients[] = $parent;
                }
                return $recipients;
        }
    }

    /**
     * Resolve recipients including ALL linked parents regardless of
     * tier — used by mass announcements and safeguarding broadcasts
     * (use cases 14 / 15) where every parent should receive the
     * message even when the player is U12+.
     *
     * @return Recipient[]
     */
    public function forPlayerWithParents( int $playerId ): array {
        if ( $playerId <= 0 ) return [];
        $player = self::loadPlayer( $playerId );
        if ( $player === null ) return [];

        $recipients = [];
        $self = self::buildSelf( $player );
        if ( $self !== null ) $recipients[] = $self;
        foreach ( $this->parentsOf( $playerId, $player ) as $parent ) {
            $recipients[] = $parent;
        }
        return $recipients;
    }

    /**
     * @param array<string,mixed> $player
     * @return Recipient[]
     */
    private function parentsOf( int $playerId, array $player ): array {
        // #3979 — the guardians come from `ParentChildResolver`, not the raw
        // pivot, so a released, archived or binned child's family is sent
        // nothing about them. The legacy guardian fields follow the same
        // rule: `loadPlayer()` already returned null for a closed-out
        // child, so they are only ever consulted for one who is not —
        // otherwise an empty guardian list would reach the family through
        // them.

        $uids = ParentChildResolver::guardiansOf( $playerId );
        if ( $uids === [] ) {
            // Fallback: legacy guardian fields on tt_players still in use.
            $email = (string) ( $player['guardian_email'] ?? '' );
            $phone = (string) ( $player['guardian_phone'] ?? '' );
            if ( $email === '' && $phone === '' ) return [];
            return [ Recipient::parent( 0, $playerId, $email, $phone, '' ) ];
        }

        $out = [];
        foreach ( $uids as $uid ) {
            $email  = (string) ( ContactResolver::emailForUser( $uid ) ?? '' );
            $phone  = (string) ( ContactResolver::phoneForUser( $uid ) ?? '' );
            $locale = (string) get_user_meta( $uid, 'locale', true );
            $out[]  = Recipient::parent( $uid, $playerId, $email, $phone, $locale );
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $player
     */
    private static function buildSelf( array $player ): ?Recipient {
        $uid = (int) ( $player['wp_user_id'] ?? 0 );
        if ( $uid <= 0 ) return null;
        $u = get_userdata( $uid );
        if ( ! $u ) return null;
        $email  = (string) ( ContactResolver::emailForUser( $uid ) ?? '' );
        $phone  = (string) ( ContactResolver::phoneForUser( $uid ) ?? '' );
        $locale = (string) get_user_meta( $uid, 'locale', true );
        return Recipient::self( $uid, $email, $phone, $locale );
    }

    /**
     * The player as a message subject, or null when there is nobody to
     * send to: an id outside the current club, or a released, archived or
     * binned player (#4088) — neither the player nor their family is
     * messaged about a player who has left.
     *
     * @return array<string,mixed>|null
     */
    private static function loadPlayer( int $playerId ): ?array {
        if ( ParentChildResolver::isClosedOut( $playerId ) ) return null;

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, wp_user_id, guardian_email, guardian_phone
                FROM {$wpdb->prefix}tt_players
                WHERE id = %d AND club_id = %d
                LIMIT 1",
            $playerId, CurrentClub::id()
        ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }
}
