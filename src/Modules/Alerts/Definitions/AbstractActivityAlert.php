<?php
namespace TT\Modules\Alerts\Definitions;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Alerts\Contracts\AlertInterface;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\AlertOccurrence;
use TT\Modules\Alerts\Domain\Severity;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * AbstractActivityAlert (#2631, epic #2629) — shared machinery for the
 * three wave 1 definitions, all of which are about an activity.
 *
 * What it owns:
 *
 *  - **Audience.** Epic decision 7: an occurrence goes to the people who
 *    can fix the thing — the activity's own coach plus the team's head
 *    coach — and to nobody else. A Head of Development at a twenty-team
 *    academy does not receive twenty times the volume; their oversight is
 *    the aggregate roll-up in #2633. Fanning every occurrence at the person
 *    whose attention is most contested is the fastest way to have the whole
 *    feature ignored.
 *
 *  - **Set-based audience resolution.** Head coaches for every team in the
 *    result set are fetched in ONE query, keyed by team. A per-activity
 *    lookup would be a query per row on an hourly, every-club sweep, which
 *    is exactly the shape `AlertInterface` forbids.
 *
 * Subclasses supply the condition (`rows()`), the title, and the severity
 * ageing rule. They never touch recipients or the occurrence shape.
 */
abstract class AbstractActivityAlert implements AlertInterface {

    public const SUBJECT_TYPE = 'activity';

    public function module(): string {
        return 'activities';
    }

    public function subjectType(): string {
        return static::SUBJECT_TYPE;
    }

    /**
     * Fixing any of these means editing the activity, so that is the
     * capability that gates receipt.
     */
    public function capRequired(): string {
        return 'tt_edit_activities';
    }

    /** @return list<string> */
    public function defaultSurfaces(): array {
        return [ 'badge', 'banner' ];
    }

    public function isOperational(): bool {
        return false;
    }

    public function defaultSeverity(): string {
        return Severity::ATTENTION;
    }

    /**
     * The activities matching this alert's condition.
     *
     * Must return objects carrying at least `id`, `title`, `session_date`,
     * `team_id` and `coach_id`, and must be a single set-based query.
     *
     * @return list<object>
     */
    abstract protected function rows( AlertContext $context ): array;

    /** Translated one-line title for one matching activity. */
    abstract protected function titleFor( object $row ): string;

    /**
     * Severity for one row. Default: whatever the definition ships. Override
     * to age an occurrence up — and note the evaluator recomputes this on
     * every reconcile, so an ageing rule takes effect on the next sweep
     * rather than only on rows created after it.
     */
    protected function severityFor( object $row ): string {
        return $this->defaultSeverity();
    }

    /**
     * @return list<AlertOccurrence>
     */
    public function evaluate( AlertContext $context ): array {
        $rows = $this->rows( $context );
        if ( empty( $rows ) ) return [];

        $team_ids = [];
        foreach ( $rows as $row ) {
            $tid = (int) ( $row->team_id ?? 0 );
            if ( $tid > 0 ) $team_ids[ $tid ] = true;
        }
        $head_coaches = $this->headCoachesByTeam( array_keys( $team_ids ) );

        $out = [];
        foreach ( $rows as $row ) {
            $activity_id = (int) ( $row->id ?? 0 );
            if ( $activity_id <= 0 ) continue;

            $recipients = [];
            $coach_id   = (int) ( $row->coach_id ?? 0 );
            if ( $coach_id > 0 ) $recipients[ $coach_id ] = true;

            $team_id = (int) ( $row->team_id ?? 0 );
            if ( $team_id > 0 && isset( $head_coaches[ $team_id ] ) ) {
                $recipients[ $head_coaches[ $team_id ] ] = true;
            }

            // No resolvable recipient means nobody would ever see this, so
            // writing it would be inventing work for the retention cron.
            if ( empty( $recipients ) ) continue;

            $payload = [
                'title'         => $this->titleFor( $row ),
                'url'           => RecordLink::detailUrlFor( 'activities', $activity_id ),
                'activity_name' => (string) ( $row->title ?? '' ),
                'session_date'  => (string) ( $row->session_date ?? '' ),
            ];

            foreach ( array_keys( $recipients ) as $user_id ) {
                $out[] = new AlertOccurrence(
                    $this->key(),
                    (int) $user_id,
                    self::SUBJECT_TYPE,
                    $activity_id,
                    $this->severityFor( $row ),
                    $payload
                );
            }
        }

        return $out;
    }

    /**
     * WP user id of each team's head coach, keyed by team id, in one query.
     *
     * Same resolution as `Workflow\Resolvers\TeamHeadCoachResolver` — the
     * `head_coach` functional-role assignment in `tt_team_people`, which has
     * been the single source of truth since #1315 retired the legacy
     * `tt_teams.head_coach_id` column. #2635 lifts that resolver into shared
     * infrastructure and this method collapses into a call to it; until
     * then, duplicating one join is cheaper than a cross-module dependency
     * on a class that is about to move.
     *
     * @param list<int> $team_ids
     * @return array<int,int> team_id => wp_user_id
     */
    protected function headCoachesByTeam( array $team_ids ): array {
        global $wpdb;
        $team_ids = array_values( array_filter( array_map( 'intval', $team_ids ) ) );
        if ( empty( $team_ids ) ) return [];

        $p    = $wpdb->prefix;
        $list = implode( ',', $team_ids );

        // MIN(tp.id) mirrors the resolver's ORDER BY tp.id ASC LIMIT 1:
        // where a team somehow has two head-coach assignments, both pick the
        // same one, so an occurrence does not flip between recipients from
        // sweep to sweep and duplicate itself under two dedupe keys.
        $rows = $wpdb->get_results(
            "SELECT tp.team_id, pe.wp_user_id
               FROM {$p}tt_team_people tp
         INNER JOIN {$p}tt_functional_roles fr ON tp.functional_role_id = fr.id
         INNER JOIN {$p}tt_people pe ON tp.person_id = pe.id
              WHERE tp.team_id IN ({$list})
                AND fr.role_key = 'head_coach'
                AND pe.wp_user_id > 0
                AND tp.id IN (
                    SELECT MIN(tp2.id)
                      FROM {$p}tt_team_people tp2
                INNER JOIN {$p}tt_functional_roles fr2 ON tp2.functional_role_id = fr2.id
                     WHERE tp2.team_id IN ({$list})
                       AND fr2.role_key = 'head_coach'
                  GROUP BY tp2.team_id
                )"
        );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $out[ (int) $row->team_id ] = (int) $row->wp_user_id;
        }
        return $out;
    }

    /**
     * Shared WHERE fragment: this club, not archived. Every definition here
     * needs both, and a dropped club clause on an unauthenticated sweep
     * crosses tenants silently rather than failing.
     */
    protected function baseWhere( string $alias = 'a' ): string {
        return QueryHelpers::clubScopeWhere( $alias ) . " AND {$alias}.archived_at IS NULL";
    }

    /** Days between a stored date and today, never negative. */
    protected function daysSince( string $date ): int {
        $date = QueryHelpers::usableDate( $date );
        if ( $date === null ) return 0;
        $ts = strtotime( $date );
        if ( $ts === false ) return 0;
        $diff = (int) floor( ( current_time( 'timestamp' ) - $ts ) / DAY_IN_SECONDS );
        return max( 0, $diff );
    }
}
