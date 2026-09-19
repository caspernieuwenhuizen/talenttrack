<?php
namespace TT\Modules\Prospects;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Authorization\MatrixGate;

/**
 * ScoutingVisitsAccess (#2007) — who is offered the scout's visit planner.
 *
 * ## Why this is not just the prospects cap
 *
 * A head coach reads prospects at team scope on purpose: #0081 gave them
 * their own age group's funnel. The scouting-visits tile was later collapsed
 * onto the same `prospects` entity to fix an unrelated phantom-entity 403
 * (#1143), and from that moment the two were inseparable — a head coach who
 * had the funnel had the scout's outbound visit planner too, and removing
 * the prospects grant to hide one would have taken the other with it.
 *
 * So the tile hangs off a visibility entity of its own,
 * `scouting_visits_panel` (the #0079 pattern), seeded for scout, head of
 * development and academy admin. This class asks that same question for the
 * two views behind the tile, because a tile-less sub-view — the visit detail
 * page — is reachable by URL and the dashboard's dispatch gate has no tile
 * entity to read for it.
 *
 * The VIEWS still gate on the prospects caps. Those decide whether the user
 * may read prospect data at all; this decides whether this particular
 * surface is theirs. Both have to pass.
 *
 * ## Dormant installs are unchanged
 *
 * When `tt_authorization_active` is 0 the matrix decides nothing and the
 * per-view capability checks are the gate (#0071 put matrix-dormant installs
 * out of scope). Answering `true` here on such an install is not a hole; it
 * is the same answer the whole product gives.
 */
final class ScoutingVisitsAccess {

    public const ENTITY = 'scouting_visits_panel';

    /**
     * True when this user should reach the scouting-visit surfaces.
     *
     * @param bool $is_admin the caller's own administrator determination,
     *                       kept as the bypass every matrix consumer applies.
     */
    public static function allows( int $user_id, bool $is_admin = false ): bool {
        if ( $is_admin ) return true;
        if ( $user_id <= 0 ) return false;
        if ( ! self::matrixActive() ) return true;
        if ( ! class_exists( '\\TT\\Modules\\Authorization\\MatrixGate' ) ) return true;

        return MatrixGate::canAnyScope( $user_id, self::ENTITY, 'read' );
    }

    /**
     * May this user read this visit?
     *
     * A visit is the scout's own planning record: they see theirs, the head
     * of development (`tt_manage_prospects`) sees everybody's. #3604 moved
     * the rule here because the two views each carried their own copy and
     * REST carried none at all — a read route written against either copy
     * would have been a third answer to one question.
     *
     * @param object $visit a `tt_scouting_plan_visits` row.
     * @param bool   $is_admin the caller's own administrator determination,
     *                         kept as the bypass every matrix consumer applies.
     */
    public static function canReadVisit( int $user_id, object $visit, bool $is_admin = false ): bool {
        if ( $is_admin ) return true;
        if ( $user_id <= 0 ) return false;

        $row = (array) $visit;
        if ( (int) ( $row['scout_user_id'] ?? 0 ) === $user_id ) return true;

        return AuthorizationService::userCanOrMatrix( $user_id, 'tt_manage_prospects' );
    }

    /**
     * The scout a listing must be narrowed to, or null when the caller may
     * see every scout's visits. A caller-supplied `scout_user_id` is
     * overruled by the returned id, never the other way round.
     */
    public static function forcedScoutFilter( int $user_id, bool $is_admin = false ): ?int {
        if ( $is_admin ) return null;
        if ( AuthorizationService::userCanOrMatrix( $user_id, 'tt_manage_prospects' ) ) return null;

        return $user_id;
    }

    private static function matrixActive(): bool {
        if ( ! class_exists( '\\TT\\Infrastructure\\Config\\ConfigService' ) ) return false;

        return (bool) ( new ConfigService() )->getBool( 'tt_authorization_active', false );
    }
}
