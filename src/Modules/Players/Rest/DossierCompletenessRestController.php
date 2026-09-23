<?php
namespace TT\Modules\Players\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\BaseController;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Players\Services\DossierCompletenessService;

/**
 * DossierCompletenessRestController (#3805) — `GET /teams/{team_id}/dossier-completeness`.
 *
 * The state of a squad's paperwork in one call, in the same envelope
 * `GET /teams/{team_id}/measurement-coverage` answers in: `counts` per check
 * and a `needs` list naming the players. The office already reads that
 * report weekly; this one answers the other half of the question, and
 * answering it in a different shape would have cost them the habit.
 *
 * **Per team, never club-wide, and never the contact details themselves.**
 * The route says whether a guardian field is filled in, not what is in it,
 * and there is no `/dossier-completeness` without a team in the path. Both
 * are deliberate: the question is "whose file is incomplete", and a
 * club-wide version answering it with names and addresses would be a bulk
 * export of families' contact details behind a reporting capability.
 *
 * #4014 added `family_reachable` to the payload: a count of how many of this
 * squad's families the club has any route to — guardian e-mail, guardian
 * phone or a linked parent account. Still counts, still no details, and the
 * six checks are unchanged beside it. The club-wide form of that question is
 * answered by `GET /alerts/family-reachability`, which is counts and team
 * names only; the refusal above is about *names*, and it stands.
 *
 * Its own controller rather than a route on `PlayersRestController`: this is
 * a report keyed by team, gated by team scope, and composing it next to the
 * player CRUD would put a team-scoped read behind a players collection whose
 * gate asks a different question.
 */
final class DossierCompletenessRestController extends BaseController {

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/teams/(?P<team_id>\d+)/dossier-completeness', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_team_completeness' ],
                'args'                => [
                    'team_id' => [
                        'type'        => 'integer',
                        'description' => 'The squad whose files to check.',
                    ],
                ],
                'permission_callback' => [ __CLASS__, 'can_read_team_dossiers' ],
            ],
        ] );
    }

    /**
     * Global or team-scoped `players` read — the same shape
     * `MeasurementsRestController::can_read_team_sessions()` uses, asked of
     * the entity this report is about.
     *
     * A coach of another team is refused. So is a scout: #3807 narrowed
     * their `players` grant to `player` scope, which is neither global nor
     * this team, and a squad-wide roll-up of families' paperwork is not what
     * a per-player scout link is for.
     */
    public static function can_read_team_dossiers( \WP_REST_Request $r ): bool {
        $uid     = get_current_user_id();
        $team_id = absint( $r['team_id'] );
        if ( $uid <= 0 || $team_id <= 0 ) return false;

        return MatrixGate::can( $uid, 'players', 'read', 'global' )
            || MatrixGate::can( $uid, 'players', 'read', 'team', $team_id );
    }

    public static function get_team_completeness( \WP_REST_Request $r ): \WP_REST_Response {
        $team_id = absint( $r['team_id'] );
        $report  = ( new DossierCompletenessService() )->forTeam( $team_id );

        return new \WP_REST_Response( array_merge( [ 'team_id' => $team_id ], $report ), 200 );
    }
}
