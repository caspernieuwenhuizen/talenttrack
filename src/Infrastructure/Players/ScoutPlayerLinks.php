<?php
namespace TT\Infrastructure\Players;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\PlayerStatus;
use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ScoutPlayerLinks (#3566) — the single canonical answer to "which
 * players is this scout linked to?".
 *
 * A scout's matrix rows are written at `player` scope
 * (`config/authorization_seed.php`: `trial_cases`, `trial_inputs`,
 * `evaluations`, `media`). Until this class existed, `MatrixGate` knew
 * only two ways a user could hold `player` scope — being the player, or
 * being their guardian — so every one of those scout rows was a dead
 * grant: seeded, documented, and resolving to false.
 *
 * Two links count, settled on #3566:
 *
 *   1. an **active** seat on a trial case's panel (`tt_trial_case_staff`
 *      with `unassigned_at IS NULL`), resolved to the case's player;
 *   2. the scout's own assignment list (user meta `tt_scout_player_ids`).
 *
 * Cases promoted from prospects the scout discovered are deliberately
 * NOT a link: discovering a prospect is not standing on their panel.
 *
 * ## Why the meta read lives here
 *
 * `tt_scout_player_ids` was read raw in three places — the scout's own
 * "my players" view, the assigned-players dashboard widget, and the
 * access view that writes it. Three decoders of the same JSON is how
 * they drift, and one of them is now an authorization input, which is
 * the worst place for a private copy (CLAUDE.md §4). This class is the
 * reader; those three call it.
 *
 * Mirrors {@see ParentChildResolver}, which plays the same role for the
 * guardian branch: club-scoped, status-filtered, lifecycle-filtered, one
 * implementation. The two carry the same lifecycle rule on purpose (#3937)
 * — an archived or trashed player is out of both — so a reader does not
 * have to check which of the pair they are looking at.
 */
final class ScoutPlayerLinks {

    private const META_KEY = 'tt_scout_player_ids';

    /**
     * The roster statuses that keep a scout's link alive (#3928).
     *
     * `trial` is on this list because it is the status a panel seat
     * implies: a scout sitting on a trial case's panel is there to
     * assess a player who is, by definition, on trial. Narrowing to
     * `active` alone — the shorthand this class shipped with — made the
     * panel-seat route resolve an id and then filter it straight back
     * out again, so the branch #3566 exists for was dead for the one
     * population it was written for.
     *
     * The list is an allowlist rather than "anything but `released`" so
     * a future status is opted in deliberately. `inactive`, `released`
     * and `graduated` are all out: none of them is somebody a scout has
     * live work on, and a release ending the link is what #3476 settled
     * for the guardian branch and this one mirrors.
     *
     * @var list<string>
     */
    private const LINKED_STATUSES = [ PlayerStatus::ACTIVE, PlayerStatus::TRIAL ];

    /**
     * Every player this scout is linked to, by either route.
     *
     * Filters to the current club, to {@see LINKED_STATUSES}, and to the
     * `active` lifecycle — so a released player drops out of a scout's
     * scope exactly as they drop out of a guardian's (#3476 settled that
     * a release ends the link), and an archived or trashed player is out
     * along with them.
     *
     * @return list<int>
     */
    public static function playerIds( int $scout_user_id ): array {
        if ( $scout_user_id <= 0 ) return [];

        $ids = array_merge( self::panelPlayerIds( $scout_user_id ), self::assignedPlayerIds( $scout_user_id ) );
        if ( $ids === [] ) return [];

        return self::linkedOnly( array_values( array_unique( $ids ) ) );
    }

    /**
     * Is this scout linked to this player? The specific-target twin of
     * {@see playerIds()}, and the question `MatrixGate::userHasScope()`
     * asks on the `player` branch.
     */
    public static function isLinkedTo( int $scout_user_id, int $player_id ): bool {
        if ( $scout_user_id <= 0 || $player_id <= 0 ) return false;
        return in_array( $player_id, self::playerIds( $scout_user_id ), true );
    }

    /**
     * Does this scout hold any link at all? The any-scope twin, kept
     * separate so `userHasAnyScope()` can stop at the first hit instead
     * of building and filtering the whole list.
     */
    public static function hasAnyLink( int $scout_user_id ): bool {
        return self::playerIds( $scout_user_id ) !== [];
    }

    /**
     * Players whose trial case this scout sits on, with an active seat.
     *
     * `tt_trial_case_staff` carries no player of its own — it points at
     * a case — so the case is joined for both the player and the club.
     * An unassigned seat (`unassigned_at` set) ends the link the moment
     * it is written; nothing is retained for a panel the scout has left.
     *
     * @return list<int>
     */
    public static function panelPlayerIds( int $scout_user_id ): array {
        if ( $scout_user_id <= 0 ) return [];

        global $wpdb;
        $staff = $wpdb->prefix . 'tt_trial_case_staff';
        $cases = $wpdb->prefix . 'tt_trial_cases';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $staff ) ) !== $staff ) {
            return [];
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT c.player_id
               FROM {$staff} s
               INNER JOIN {$cases} c ON c.id = s.case_id
              WHERE s.user_id = %d
                AND s.unassigned_at IS NULL
                AND c.club_id = %d
                AND c.player_id > 0",
            $scout_user_id, CurrentClub::id()
        ) );

        return is_array( $rows ) ? array_values( array_filter( array_map( 'intval', $rows ) ) ) : [];
    }

    /**
     * The scout's own assignment list, decoded from user meta.
     *
     * The one decoder. Returns positive ints, de-duplicated, in the
     * order stored. It does **not** filter by status or club — the meta
     * is a bare id list with no tenancy of its own, so
     * {@see playerIds()} applies both when it combines the two routes.
     *
     * @return list<int>
     */
    public static function assignedPlayerIds( int $scout_user_id ): array {
        if ( $scout_user_id <= 0 ) return [];

        $raw = get_user_meta( $scout_user_id, self::META_KEY, true );
        if ( ! is_string( $raw ) || $raw === '' ) return [];

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) return [];

        $ids = array_map( 'intval', $decoded );
        return array_values( array_unique( array_filter( $ids, static fn( $i ) => $i > 0 ) ) );
    }

    /**
     * Narrow a list of player ids to those a link may survive on: in the
     * current club, on a status from {@see LINKED_STATUSES}, and not
     * archived or in the recycle bin.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    private static function linkedOnly( array $ids ): array {
        if ( $ids === [] ) return [];

        global $wpdb;
        $players   = $wpdb->prefix . 'tt_players';
        $in        = implode( ',', array_map( 'intval', $ids ) );
        $statuses  = self::LINKED_STATUSES;
        $status_ph = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
        $lifecycle = ArchiveRepository::filterClause( 'active' );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$players}
              WHERE id IN ({$in}) AND club_id = %d
                AND status IN ({$status_ph})
                AND {$lifecycle}",
            array_merge( [ CurrentClub::id() ], $statuses )
        ) );

        return is_array( $rows ) ? array_values( array_map( 'intval', $rows ) ) : [];
    }
}
