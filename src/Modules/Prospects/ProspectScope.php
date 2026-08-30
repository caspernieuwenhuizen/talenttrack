<?php
namespace TT\Modules\Prospects;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ProspectScope (#3160) — which prospects may this viewer see?
 *
 * `tt_view_prospects` maps to `prospects:read` and is answered by
 * `MatrixGate::canAnyScope` — *"do you hold this anywhere"*. head_coach's
 * grant is `[ 'r', 'team' ]`, and the seed says why: so a coach can follow
 * **their own age group's funnel** (`config/authorization_seed.php`, #0081).
 * Nothing in the query read that.
 *
 * ## What the schema actually offers
 *
 * `tt_prospects` has **no team column** — no `target_team_id`, nothing. A
 * prospect has not joined yet, so there is no roster row to hang one off.
 * What it does carry is `age_group_lookup_id`, which is exactly the
 * dimension the seed's comment names, plus `promoted_to_player_id` once the
 * prospect becomes a player and acquires a team.
 *
 * So the scope is: **the age groups of the teams you coach, plus anyone
 * already promoted into one of those teams, plus your own discoveries.**
 * The third clause is what keeps a coach who logs a prospect for a squad
 * they do not coach from immediately losing sight of them.
 *
 * A prospect with no age group and no promotion is invisible to a
 * team-scoped viewer. These are children who have not joined the academy,
 * collected before any relationship exists and on a legal basis of consent
 * — when the record does not say who it is for, less visibility is the
 * right default (CLAUDE.md §1).
 *
 * ## Why this replaced `isScoutOnly()`
 *
 * Both consumers used to ask `isScoutOnly()`, which meant "holds
 * `tt_view_prospects` but not `tt_manage_prospects`" and narrowed to
 * `discovered_by_user_id = self`. That was right when the scout's grant was
 * `[ 'rcd', 'self' ]`. **v3.110.154 moved the scout to `[ 'rcd', 'global' ]`**
 * — two scouts on the same pool need to see each other's work — and the
 * helper inverted with it: it stopped catching scouts, and started catching
 * the only remaining persona without `create_delete`, which is the head
 * coach. The head coach's board has been showing them nothing but their own
 * discoveries ever since, while `GET /prospects` handed them the whole club.
 */
final class ProspectScope {

    /**
     * No narrowing at all — a global `prospects` read. Scout, Head of
     * Development and Academy Admin hold it; so do administrators through
     * the `tt_edit_settings` rung inside the helper.
     */
    public static function canSeeAll( int $user_id ): bool {
        return $user_id > 0 && QueryHelpers::user_has_global_entity_read( $user_id, 'prospects' );
    }

    /**
     * The viewer's narrowing, or null when none applies.
     *
     * @return array{age_group_lookup_ids: list<int>, team_ids: list<int>, user_id: int}|null
     */
    public static function forUser( int $user_id ): ?array {
        if ( $user_id <= 0 ) {
            return [ 'age_group_lookup_ids' => [], 'team_ids' => [], 'user_id' => 0 ];
        }
        if ( self::canSeeAll( $user_id ) ) {
            return null;
        }

        // Archived teams included: a coach who ran last season's JO15 should
        // not lose the funnel that fed it mid-transition.
        $teams     = QueryHelpers::get_teams_for_coach( $user_id, true );
        $team_ids  = array_values( array_unique( array_map( 'intval', array_column( $teams, 'id' ) ) ) );
        $age_names = [];
        foreach ( $teams as $team ) {
            $name = trim( (string) ( $team->age_group ?? '' ) );
            if ( $name !== '' ) $age_names[] = $name;
        }

        return [
            'age_group_lookup_ids' => self::ageGroupLookupIds( array_values( array_unique( $age_names ) ) ),
            'team_ids'             => $team_ids,
            'user_id'              => $user_id,
        ];
    }

    /**
     * `AND ( … )` for a query over `tt_prospects`, or an empty string when
     * the viewer may see everything.
     *
     * `$alias` is a caller-supplied SQL identifier and must be a literal.
     * Every id interpolated below is cast to int.
     */
    public static function sqlClause( int $user_id, string $alias = 'p' ): string {
        $scope = self::forUser( $user_id );
        if ( $scope === null ) return '';

        global $wpdb;
        $col = $alias !== '' ? $alias . '.' : '';
        $ors = [];

        if ( $scope['age_group_lookup_ids'] ) {
            $ors[] = $col . 'age_group_lookup_id IN ('
                . implode( ',', array_map( 'intval', $scope['age_group_lookup_ids'] ) ) . ')';
        }
        if ( $scope['team_ids'] ) {
            $ors[] = $col . 'promoted_to_player_id IN ( SELECT id FROM '
                . $wpdb->prefix . 'tt_players WHERE team_id IN ('
                . implode( ',', array_map( 'intval', $scope['team_ids'] ) )
                . ') AND club_id = ' . (int) CurrentClub::id() . ' )';
        }
        // Always last, and always present: a viewer keeps sight of what they
        // logged themselves even when it sits outside their squads.
        $ors[] = $col . 'discovered_by_user_id = ' . (int) $scope['user_id'];

        return ' AND ( ' . implode( ' OR ', $ors ) . ' )';
    }

    /**
     * Resolve age-group names to `tt_lookups` ids. `tt_teams.age_group`
     * stores the name, not a foreign key — `QueryHelpers::age_groups_in_use()`
     * matches the same way — so the join happens here rather than in SQL.
     *
     * @param list<string> $names
     * @return list<int>
     */
    private static function ageGroupLookupIds( array $names ): array {
        if ( ! $names ) return [];

        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ids = (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_lookups
              WHERE lookup_type = 'age_group' AND club_id = %d AND name IN ($placeholders)",
            ...array_merge( [ CurrentClub::id() ], $names )
        ) );

        return array_values( array_unique( array_map( 'intval', $ids ) ) );
    }
}
