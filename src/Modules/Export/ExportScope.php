<?php
namespace TT\Modules\Export;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Authorization\MatrixGate;

/**
 * ExportScope — which teams a caller may export, for the bulk exporters.
 *
 * A bulk export is a staff tool: a list of a squad, its evaluations, its
 * goals, its registers. The coarse gate in front of it used to ask only for
 * a `tt_view_*` capability, and `LegacyCapMapper` answers every one of those
 * with `MatrixGate::canAnyScope()` — so a grant at ANY scope was enough, and
 * none of the exporters narrowed afterwards. A parent's `players [r, player]`
 * over their own child satisfied it, and the file that came back was the
 * whole academy.
 *
 * The rule, in three tiers:
 *
 *  - **Unrestricted** — an administrator, a holder of `tt_view_settings`, or
 *    a holder of the entity at global scope. `null` is returned and the
 *    exporter adds no clause. The first two are the same unconditional line
 *    `PrintRouter::canPrint()` draws; they are here because a WordPress
 *    administrator does not always resolve to a matrix persona, and a gate
 *    built on the matrix alone would lock them out.
 *  - **Team-scoped** — the teams returned by `get_permitted_teams()`, which
 *    is the teams the caller coaches, each confirmed against the matrix at
 *    team scope. The export is narrowed to them, and a requested team outside
 *    them is refused.
 *  - **Everyone else is refused, never narrowed.** A caller holding the
 *    entity only at `player` or `self` scope — a parent, a player, a scout
 *    linked to individual children — has no squad to export. Narrowing a
 *    parent "to their child's team" would still hand them every teammate's
 *    family's contact details, so the boundary is refusal, not a smaller
 *    file. An empty permitted list is a refusal for the same reason, and so
 *    that the answer says "not yours" out loud instead of arriving as an
 *    empty download that looks like a bug.
 */
final class ExportScope {

    /**
     * May this caller run a bulk export over `$entity` at all?
     *
     * The coarse gate — `ScopeGatedExporter::isAvailableFor()` delegates
     * here, so a refused caller is stopped before `collect()` and does not
     * see the export offered. `teamIdsFor()` is the authoritative check.
     */
    public static function mayExport( int $user_id, string $entity ): bool {
        if ( $user_id <= 0 ) return false;
        if ( self::isUnrestricted( $user_id, $entity ) ) return true;

        return self::permittedTeamIds( $user_id, $entity ) !== [];
    }

    /**
     * The team ids to narrow to, or `null` for no narrowing.
     *
     * @param int $requested_team The `team_id` filter the caller sent, or 0.
     * @return list<int>|null
     * @throws ExportException `forbidden` when the caller may export no team,
     *                         or asked for one outside their scope.
     */
    public static function teamIdsFor( int $user_id, string $entity, int $requested_team = 0 ): ?array {
        if ( self::isUnrestricted( $user_id, $entity ) ) return null;

        $allowed = self::permittedTeamIds( $user_id, $entity );
        if ( $allowed === [] ) {
            throw new ExportException(
                'forbidden',
                __( 'Exports cover the teams you work with, and your account is not linked to one.', 'talenttrack' )
            );
        }
        if ( $requested_team > 0 && ! in_array( $requested_team, $allowed, true ) ) {
            throw new ExportException( 'forbidden', __( 'You do not have access to this team.', 'talenttrack' ) );
        }

        return $allowed;
    }

    /**
     * `<column> IN (…)` for a narrowed export. The ids are integers from
     * `teamIdsFor()`, cast again here, so the fragment carries no input.
     *
     * @param list<int> $team_ids
     */
    public static function inClause( string $column, array $team_ids ): string {
        $ids = array_values( array_filter( array_map( 'intval', $team_ids ), static fn( int $id ): bool => $id > 0 ) );
        // Unreachable from `teamIdsFor()`, which refuses an empty list; kept
        // so a future caller cannot turn "no teams" into "no filter".
        if ( $ids === [] ) return '1 = 0';

        return $column . ' IN (' . implode( ',', $ids ) . ')';
    }

    private static function isUnrestricted( int $user_id, string $entity ): bool {
        return user_can( $user_id, 'administrator' )
            || user_can( $user_id, 'tt_view_settings' )
            || MatrixGate::can( $user_id, $entity, 'read', MatrixGate::SCOPE_GLOBAL );
    }

    /** @return list<int> */
    private static function permittedTeamIds( int $user_id, string $entity ): array {
        $ids = [];
        foreach ( QueryHelpers::get_permitted_teams( $user_id, $entity, 'read' ) as $team ) {
            $vars = get_object_vars( (object) $team );
            $id   = isset( $vars['id'] ) ? (int) $vars['id'] : 0;
            if ( $id > 0 ) $ids[] = $id;
        }
        return $ids;
    }
}
