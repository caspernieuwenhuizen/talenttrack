<?php
namespace TT\Infrastructure\Players;

use TT\Shared\Tiles\TileRegistry;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ParentDashboardTiles (#2081, revising #1992) — the child-scoped tile set
 * a parent persona sees on the legacy dashboard grid.
 *
 * #2081 replaces the hardcoded 5-tile curation with a MIRROR of the
 * player's own "Me"-group tiles, resolved through the normal registry so
 * the parent surface inherits module + `player_*` feature gating
 * automatically. Whatever Me-tile a player sees (and whatever is switched
 * off for players) is exactly what the parent sees for that child — one
 * mental model, not two divergent ones (CLAUDE.md §1).
 *
 * The *gating* inheritance ("off for players = off for parents") is not a
 * parallel list maintained here: it falls out of
 * `TileRegistry::tilesForUserGrouped()`, because every Me-tile carries a
 * `feature => 'player_*'` gate and a parent-aware `cap_callback`
 * (CoreSurfaceRegistration ~223-345). A linked parent passes those callbacks,
 * so the registry already yields the same Me-tiles the player would see.
 *
 * The *framing* is a list, and #3472 is the correction to an earlier claim
 * here that it was not. `childNouns()` is an allowlist: a slug missing from
 * it used to be dropped from the parent surface altogether, which is how
 * `measurements` (granted by #1856) and `my-messages` came to be reachable
 * for a parent everywhere except the screen they land on. A slug now either
 * has a child-framed noun or comes out of `ownTiles()` unframed, so the map
 * decides how a tile reads, never whether it exists.
 *
 * "My tasks" (`my-tasks`, in the Tasks group) is included too, scoped to the
 * child, so a parent can help remind their kid of pending tasks. It inherits
 * the player's task / workflow gating like every other mirrored tile.
 *
 * Account-level tiles (settings / password / messages) are NOT mirrored —
 * they stay the parent's OWN, neither child-scoped nor relabeled. `tiles()`
 * returns the development (Me-group) surfaces plus my-tasks; `ownTiles()`
 * (#3472) returns everything else the parent can see, so the two together
 * cover every tile the registry yields and nothing is dropped by omission.
 *
 * This is the business-logic decision ("which tiles, framed how") kept out
 * of the view (§4). The view (FrontendTileGrid) only resolves the
 * child-scoped URL, builds the `<Child>'s <noun>` label, and paints the
 * markup. Icons / colours come straight from the canonical registry tile
 * so the parent surfaces look like the player surfaces they mirror.
 */
final class ParentDashboardTiles {

    /**
     * Player Me-view slugs (plus my-tasks) that the parent surface mirrors,
     * each mapped to a clean, child-framed gettext noun. The noun is NOT
     * string-munged from the player's "My …" label — it is an independent
     * source string so the Anglo possessive ("Sven's development") reads
     * naturally and translates cleanly ("Sven's ontwikkeling").
     *
     * Keyed by the registry `view_slug`. A slug not in this map is dropped
     * from the parent surface (e.g. setup / account tiles), so the parent
     * grid is exactly the development surfaces, framed for the child.
     *
     * @return array<string, string>
     */
    private static function childNouns(): array {
        return [
            'my-development' => _x( 'development', 'parent dashboard tile noun', 'talenttrack' ),
            'my-journey'     => _x( 'journey', 'parent dashboard tile noun', 'talenttrack' ),
            'overview'       => _x( 'profile', 'parent dashboard tile noun', 'talenttrack' ),
            'my-team'        => _x( 'team', 'parent dashboard tile noun', 'talenttrack' ),
            'my-evaluations' => _x( 'evaluations', 'parent dashboard tile noun', 'talenttrack' ),
            'my-activities'  => _x( 'activities', 'parent dashboard tile noun', 'talenttrack' ),
            'my-goals'       => _x( 'goals', 'parent dashboard tile noun', 'talenttrack' ),
            'my-pdp'         => _x( 'development plan', 'parent dashboard tile noun', 'talenttrack' ),
            'my-tasks'       => _x( 'tasks', 'parent dashboard tile noun', 'talenttrack' ),
            // #3472 — #1856 grants a parent their child's measurements, and
            // this map is what decides whether the grant has a tile. It did
            // not, so on the default `classic` shell — where this grid is the
            // parent's only navigation — the surface was granted and
            // unreachable.
            'measurements'   => _x( 'measurements', 'parent dashboard tile noun', 'talenttrack' ),
        ];
    }

    /**
     * The child-scoped parent tile set: the player's Me-group tiles (plus
     * my-tasks) the parent is allowed to see, resolved through the registry
     * so module + `player_*` feature gating is inherited automatically.
     *
     * Each row carries the child-framed `child_noun` (the view composes the
     * final "`<FirstName>'s <noun>`" label, since it knows the child's name)
     * alongside the canonical `icon` / `color` from the registry tile. Tiles
     * keep their registry order (the same order a player sees).
     *
     * @param int $parent_user_id The parent persona's user id; gating is
     *                            resolved against their capabilities.
     * @return list<array{view_slug:string,child_noun:string,icon:string,color:string}>
     */
    public static function tiles( int $parent_user_id ): array {
        $nouns  = self::childNouns();
        $groups = TileRegistry::tilesForUserGrouped( $parent_user_id );

        $tiles = [];
        foreach ( $groups as $group ) {
            foreach ( $group['tiles'] as $tile ) {
                $slug = (string) ( $tile['view_slug'] ?? '' );
                if ( $slug === '' || ! isset( $nouns[ $slug ] ) ) {
                    // Not a mirrored development surface (setup / account /
                    // people / performance tile) — parents only mirror the
                    // Me-group development tiles + my-tasks.
                    continue;
                }
                $tiles[] = [
                    'view_slug' => $slug,
                    'child_noun' => $nouns[ $slug ],
                    'icon'      => (string) ( $tile['icon'] ?? '' ),
                    'color'     => (string) ( $tile['color'] ?? '#0b3d2e' ),
                ];
            }
        }

        /**
         * Allow an integrator to tune the mirrored parent tile set (add /
         * remove a surface) without forking the view. Filtered value must
         * keep the same row shape `{view_slug, child_noun, icon, color}`.
         *
         * @param list<array{view_slug:string,child_noun:string,icon:string,color:string}> $tiles
         * @param int                                                                       $parent_user_id
         */
        $filtered = apply_filters( 'tt_parent_dashboard_tiles', $tiles, $parent_user_id );
        return is_array( $filtered ) ? $filtered : $tiles;
    }

    /**
     * #3472 — everything else the parent can see: their own surfaces, not
     * their child's.
     *
     * The child-noun map above is an allowlist, and anything missing from it
     * was dropped entirely rather than rendered unframed. `my-messages` — the
     * parent's own inbox — fell through that way, and on the default
     * `classic` shell, where this grid *is* the navigation, that left the
     * parent with no route to it at all.
     *
     * These keep their registry labels and carry no `player_id`: they are the
     * parent's, not the child's, so an "`<FirstName>'s`" frame would be a lie.
     *
     * Between this and `tiles()` every tile the registry yields for a parent
     * is now rendered somewhere, which is the property worth having — a Me-tile
     * added later cannot vanish from the parent surface by omission from a map.
     *
     * @return list<array{view_slug:string,label:string,icon:string,color:string}>
     */
    public static function ownTiles( int $parent_user_id ): array {
        $nouns  = self::childNouns();
        $groups = TileRegistry::tilesForUserGrouped( $parent_user_id );

        $tiles = [];
        foreach ( $groups as $group ) {
            foreach ( $group['tiles'] as $tile ) {
                $slug = (string) ( $tile['view_slug'] ?? '' );
                if ( $slug === '' || isset( $nouns[ $slug ] ) ) continue;

                $tiles[] = [
                    'view_slug' => $slug,
                    'label'     => (string) ( $tile['label'] ?? $slug ),
                    'icon'      => (string) ( $tile['icon'] ?? '' ),
                    'color'     => (string) ( $tile['color'] ?? '#0b3d2e' ),
                ];
            }
        }

        /**
         * Companion to `tt_parent_dashboard_tiles` for the parent's own
         * (non-child-scoped) surfaces. Same row shape minus `child_noun`.
         *
         * @param list<array{view_slug:string,label:string,icon:string,color:string}> $tiles
         * @param int                                                                 $parent_user_id
         */
        $filtered = apply_filters( 'tt_parent_dashboard_own_tiles', $tiles, $parent_user_id );
        return is_array( $filtered ) ? $filtered : $tiles;
    }
}
