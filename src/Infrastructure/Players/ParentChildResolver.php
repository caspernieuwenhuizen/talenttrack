<?php
namespace TT\Infrastructure\Players;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Invitations\PlayerParentsRepository;

/**
 * ParentChildResolver (#1993) — the single canonical answer to
 * "which children does this parent have, and which one are they
 * looking at?".
 *
 * `tt_player_parents` (via PlayerParentsRepository) is the ONE live
 * source of the parent → child linkage. `tt_players.guardian_email`
 * is no longer queried here — it is demoted to an invite/seed hint
 * that *creates* a pivot row (see PlayerParentsRepository::link), never
 * a runtime linkage source. This keeps the dashboard child switcher,
 * the me-view authorization, and the KPI resolver in agreement: they
 * all read the same pivot, club-scoped (SaaS-ready per CLAUDE.md §4 —
 * the join is on `player_id`, not an email string).
 *
 * Business logic (which children, in what order, which one is the
 * default subject) lives here — not in any view or widget (§4). The
 * REST controllers and the PHP views both call into this layer so a
 * future SaaS front end gets the same answers.
 */
final class ParentChildResolver {

    /**
     * Active player records linked to this parent, most-recently-linked
     * first. The order is the multi-child default rule (#1991 / #1992):
     * the first entry is the child auto-selected when no explicit
     * `?player_id` is supplied.
     *
     * Filters to `status = 'active'` and the current club. Reads the
     * pivot only — a parent linked solely via the legacy guardian_email
     * column will not surface until re-linked (the accepted #1993
     * trade-off: no backfill).
     *
     * @return list<object> tt_players rows ordered most-recent link first.
     */
    public static function children( int $parent_user_id ): array {
        if ( $parent_user_id <= 0 ) return [];

        global $wpdb;
        $table = $wpdb->prefix . 'tt_player_parents';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return [];
        }

        // Most-recent link first (created_at DESC) so the default subject
        // is the latest child the parent was attached to. The pivot is the
        // authoritative linkage; the JOIN onto tt_players resolves the
        // child's record (name/photo) for the switcher and scoping.
        $players = $wpdb->prefix . 'tt_players';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.* FROM {$table} pp
               INNER JOIN {$players} p ON p.id = pp.player_id
              WHERE pp.parent_user_id = %d
                AND pp.club_id = %d
                AND p.status = 'active'
              ORDER BY pp.created_at DESC, pp.player_id DESC",
            $parent_user_id, CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * The default child subject for a parent who supplied no explicit
     * `?player_id` — the most-recently linked active child, or null when
     * the parent has no linked child. Single-child parents resolve to
     * that child; multi-child parents resolve to their most-recent and
     * the caller offers a switcher to change it.
     */
    public static function defaultChild( int $parent_user_id ): ?object {
        $children = self::children( $parent_user_id );
        return $children[0] ?? null;
    }

    /** Number of active children linked to this parent. */
    public static function childCount( int $parent_user_id ): int {
        return count( self::children( $parent_user_id ) );
    }

    /**
     * True when this user reaches the player surfaces **purely** as a
     * guardian, and the dashboard should therefore render the child-scoped
     * parent rail instead of the own-player rail.
     *
     * Two things disqualify them.
     *
     * Having their own player record — a player who is also linked as a
     * guardian is still a player on their own dashboard.
     *
     * And, since #3479, holding any staff persona. This used to ask only
     * about the player record, so a coach whose own child plays in the
     * academy — routine in youth football — matched, and `FrontendTileGrid`
     * returned early with the parent rail and none of their coaching tiles.
     * Under the default `classic` shell there is no sidebar to fall back on,
     * so they had no route to any coaching surface at all.
     *
     * The rule is that a staff seat wins: they get their staff dashboard and
     * reach their own child through the Players list like any other player.
     * Nothing here touches their parent-side *data* access — the seed grants
     * and `canViewPlayer()` are a separate path, and a coach-parent keeps
     * whatever the guardian link gives them.
     */
    public static function isParentViewer( int $user_id ): bool {
        if ( $user_id <= 0 ) return false;
        $own = QueryHelpers::get_player_for_user( $user_id );
        if ( $own && (int) $own->id > 0 ) return false;
        if ( self::holdsStaffPersona( $user_id ) ) return false;
        return self::childCount( $user_id ) > 0;
    }

    /**
     * #3479 — does this user hold a persona whose own dashboard they would
     * lose to the parent rail?
     *
     * Expressed as "anything but the two subject personas" rather than as a
     * list of staff keys, so a persona added later is staff by default —
     * the failure mode to avoid is a new seat silently inheriting the parent
     * rail, which is the bug this method exists to fix.
     */
    private static function holdsStaffPersona( int $user_id ): bool {
        if ( ! class_exists( '\\TT\\Modules\\Authorization\\PersonaResolver' ) ) return false;

        foreach ( \TT\Modules\Authorization\PersonaResolver::personasFor( $user_id ) as $persona ) {
            if ( $persona !== 'parent' && $persona !== 'player' ) return true;
        }
        return false;
    }

    /**
     * Convenience accessor reusing the canonical repository so callers
     * that only need the linked player IDs (not full records) don't
     * re-query.
     *
     * @return list<int>
     */
    public static function childIds( int $parent_user_id ): array {
        if ( $parent_user_id <= 0 ) return [];
        return ( new PlayerParentsRepository() )->playersForParent( $parent_user_id );
    }
}
