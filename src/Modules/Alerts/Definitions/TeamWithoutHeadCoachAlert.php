<?php
namespace TT\Modules\Alerts\Definitions;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * TeamWithoutHeadCoachAlert (#2636, epic #2629) — a squad with players and
 * nobody responsible for them.
 *
 * Which player question does this answer? *What does this player need
 * next?* — for every player in the squad at once, because the person whose
 * job it is to answer that does not exist. It is also the reason the rest
 * of this catalogue can go quiet on a team: almost every other definition
 * sends its occurrence to the head coach, so a team without one silently
 * receives none of them. This alert is what makes that silence audible.
 *
 * ## Only teams that have players
 *
 * A team with no head coach and no players is a placeholder — a shell
 * created for next season, or one whose squad has moved on. Alerting on
 * those would fill the list with rows nobody intends to act on and teach
 * people to skim past the ones that matter.
 *
 * Trial groups are excluded on the same reasoning: `team_kind` marks them
 * as a different kind of thing, and they are run by whoever is running the
 * trial rather than by a permanent head coach.
 *
 * ## "Has a head coach" means today
 *
 * `tt_team_people` assignments carry an end date, so a coach who left in
 * June is not this team's head coach in September. The condition matches
 * the live head-coach queries in `TeamsRestController`: a `head_coach`
 * functional-role assignment that has not ended, held by a person who is
 * not archived.
 *
 * The role join deliberately matches on `fr.id` alone, exactly as
 * `AbstractPlayerAlert::headCoachesByTeam()` and
 * `Workflow\Resolvers\TeamHeadCoachResolver` do. `role_key` carries a global
 * unique index, so there is one `head_coach` row to find; adding a club
 * predicate here and not there is how the two resolutions would drift, and
 * a team being told it has no head coach while every other alert still
 * routes to one is the worst possible way for them to disagree.
 */
final class TeamWithoutHeadCoachAlert extends AbstractDataQualityAlert {

    public const SUBJECT_TYPE = 'team';

    public function key(): string {
        return 'dataquality.team_without_head_coach';
    }

    public function label(): string {
        return __( 'Team has no head coach', 'talenttrack' );
    }

    public function description(): string {
        return __( 'A team with players has nobody assigned as head coach. Most alerts go to the head coach, so a team without one quietly stops receiving any of them.', 'talenttrack' );
    }

    public function capRequired(): string {
        return 'tt_manage_teams';
    }

    public function subjectType(): string {
        return self::SUBJECT_TYPE;
    }

    /**
     * Louder than the other data-quality alert. A missing team assignment
     * on one player is an administrative gap; a squad with nobody
     * responsible for it is a hole in the academy's own structure.
     */
    protected function severityFor( object $row ): string {
        return Severity::ATTENTION;
    }

    /** @return list<string> */
    public function defaultSurfaces(): array {
        return [ 'badge', 'banner' ];
    }

    protected function titleFor( object $row ): string {
        $team = trim( (string) ( $row->team_name ?? '' ) );
        if ( $team === '' ) $team = __( 'A team', 'talenttrack' );
        $players = (int) ( $row->player_count ?? 0 );

        return sprintf(
            /* translators: 1: team name, 2: number of players in the squad */
            _n(
                '%1$s has %2$d player and no head coach.',
                '%1$s has %2$d players and no head coach.',
                $players,
                'talenttrack'
            ),
            $team,
            $players
        );
    }

    /** @return array<string,mixed> */
    protected function payloadFor( object $row ): array {
        return [
            'team_name'    => (string) ( $row->team_name ?? '' ),
            'player_count' => (int) ( $row->player_count ?? 0 ),
        ];
    }

    protected function urlFor( object $row ): string {
        return RecordLink::detailUrlFor( 'teams', (int) ( $row->subject_id ?? 0 ) );
    }

    /** @return list<object> */
    protected function rows( AlertContext $context ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        // The squad count is aggregated with a correlated subquery rather
        // than a GROUP BY, so the row count stays exactly one per team and
        // the NOT EXISTS can short-circuit independently.
        $sql =
            "SELECT t.id AS subject_id, t.name AS team_name,
                    ( SELECT COUNT(*)
                        FROM {$p}tt_players pl
                       WHERE pl.team_id = t.id
                         AND pl.club_id = t.club_id
                         AND pl.status = 'active'
                         AND pl.archived_at IS NULL
                         AND pl.trashed_at IS NULL ) AS player_count
               FROM {$p}tt_teams t
              WHERE " . QueryHelpers::clubScopeWhere( 't' ) . "
                AND t.archived_at IS NULL
                AND t.trashed_at IS NULL
                AND ( t.team_kind IS NULL OR t.team_kind = '' )
                AND EXISTS (
                    SELECT 1 FROM {$p}tt_players pl2
                     WHERE pl2.team_id = t.id
                       AND pl2.club_id = t.club_id
                       AND pl2.status = 'active'
                       AND pl2.archived_at IS NULL
                       AND pl2.trashed_at IS NULL
                )
                AND NOT EXISTS (
                    SELECT 1
                      FROM {$p}tt_team_people tp
                INNER JOIN {$p}tt_functional_roles fr
                        ON fr.id = tp.functional_role_id
                INNER JOIN {$p}tt_people pe
                        ON pe.id = tp.person_id AND pe.club_id = tp.club_id
                     WHERE tp.team_id = t.id
                       AND fr.role_key = 'head_coach'
                       AND pe.archived_at IS NULL
                       AND ( tp.end_date IS NULL OR tp.end_date >= CURDATE() )
                )"
            . $context->applyScope( self::SUBJECT_TYPE, 't.id' ) . "
              ORDER BY t.name ASC, t.id ASC";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql );
        return is_array( $rows ) ? $rows : [];
    }
}
