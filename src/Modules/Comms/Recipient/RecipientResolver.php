<?php
namespace TT\Modules\Comms\Recipient;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\AgeTier;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Invitations\PlayerParentsRepository;

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
 * Stateless. The constructor takes a repository for testability;
 * production code calls `forPlayer()` which lazily resolves a default
 * repo when none is supplied.
 */
final class RecipientResolver {

    private ?PlayerParentsRepository $parents;

    public function __construct( ?PlayerParentsRepository $parents = null ) {
        $this->parents = $parents;
    }

    /**
     * Resolve the recipients for one player, applying the #0042 rules.
     *
     * @return Recipient[]
     */
    public function forPlayer( int $playerId ): array {
        if ( $playerId <= 0 ) return [];

        $tier   = AgeTier::forPlayer( $playerId );
        $player = self::loadPlayer( $playerId );
        if ( $player === null ) return [];

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
        $repo = $this->parents ?? new PlayerParentsRepository();
        $rows = $repo->parentsForPlayer( $playerId );
        if ( ! is_array( $rows ) || $rows === [] ) {
            // Fallback: legacy guardian fields on tt_players still in use.
            $email = (string) ( $player['guardian_email'] ?? '' );
            $phone = (string) ( $player['guardian_phone'] ?? '' );
            if ( $email === '' && $phone === '' ) return [];
            return [ Recipient::parent( 0, $playerId, $email, $phone, '' ) ];
        }

        $out = [];
        foreach ( $rows as $row ) {
            $uid = (int) ( is_array( $row ) ? ( $row['parent_user_id'] ?? $row['user_id'] ?? 0 ) : 0 );
            if ( $uid <= 0 ) continue;
            $email  = (string) get_user_meta( $uid, 'billing_email', true );
            if ( $email === '' ) {
                $u = get_userdata( $uid );
                $email = $u ? (string) $u->user_email : '';
            }
            $phone  = (string) get_user_meta( $uid, 'tt_phone', true );
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
        $email  = (string) $u->user_email;
        $phone  = (string) get_user_meta( $uid, 'tt_phone', true );
        $locale = (string) get_user_meta( $uid, 'locale', true );
        return Recipient::self( $uid, $email, $phone, $locale );
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function loadPlayer( int $playerId ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, wp_user_id, guardian_email, guardian_phone
                FROM {$wpdb->prefix}tt_players
                WHERE id = %d
                LIMIT 1",
            $playerId
        ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }
}
