<?php
namespace TT\Modules\Alerts\Definitions;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Alerts\Contracts\AlertInterface;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\AlertOccurrence;
use TT\Modules\Alerts\Domain\Severity;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * AbstractPlayerAlert (#2636, epic #2629) — shared machinery for every
 * definition whose subject rolls up to a player.
 *
 * `AbstractActivityAlert` did the same job for the wave 1 Activities
 * definitions. This is its counterpart for the rest of the catalogue: an
 * unevaluated player, an overdue goal, a PDP cycle with no conversation, a
 * player turning eighteen. The condition differs every time; the audience,
 * the occurrence shape and the club scoping do not.
 *
 * What it owns:
 *
 *  - **Audience.** Epic decision 7: the occurrence goes to the person who
 *    can fix the thing. For a player-shaped condition that is the head
 *    coach of the player's team, plus whatever the subclass adds (the
 *    author of an evaluation, say). A Head of Development does not receive
 *    one occurrence per team — their oversight is the aggregate roll-up in
 *    #2633, and fanning every team's occurrences at the person with the
 *    least time to read them is the fastest route to the whole feature
 *    being ignored.
 *
 *  - **Set-based audience resolution.** Head coaches for every team in the
 *    result set are fetched in ONE query, keyed by team. A per-player
 *    lookup would be a query per row on an hourly, every-club sweep, which
 *    is exactly the shape `AlertInterface` forbids.
 *
 *  - **`player_id` on every occurrence**, so the wave 3 player-record
 *    surface can pick these up without each definition remembering to.
 *
 * Subclasses supply the condition (`rows()`), the title, and — optionally —
 * the severity rule, the subject type, and extra recipients. They never
 * touch the occurrence shape.
 *
 * ## What `rows()` must return
 *
 * One object per occurrence, carrying at least:
 *
 *   - `player_id`   — the player this rolls up to
 *   - `team_id`     — used to resolve the head coach (0 when unknown)
 *   - `first_name`, `last_name` — for the title
 *
 * and, when the subject is not the player itself, `subject_id`.
 */
abstract class AbstractPlayerAlert implements AlertInterface {

    public const SUBJECT_TYPE = 'player';

    /** @var ConfigService|null */
    private $config = null;

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
     * The rows matching this alert's condition. Must be a single set-based
     * query — never one per player, per team or per recipient.
     *
     * @return list<object>
     */
    abstract protected function rows( AlertContext $context ): array;

    /** Translated one-line title for one matching row. */
    abstract protected function titleFor( object $row ): string;

    /**
     * Subject type written to the occurrence. Defaults to the player; a
     * definition whose subject is a different record (an evaluation, a
     * goal) overrides this and `subjectIdFor()` together.
     */
    public function subjectType(): string {
        return static::SUBJECT_TYPE;
    }

    protected function subjectIdFor( object $row ): int {
        return (int) ( $row->subject_id ?? $row->player_id ?? 0 );
    }

    protected function playerIdFor( object $row ): int {
        return (int) ( $row->player_id ?? 0 );
    }

    /**
     * Where clicking the occurrence takes the recipient. Defaults to the
     * player's record, which is the right destination whenever the fix is
     * "look at this player"; override when the fix lives on another screen.
     */
    protected function urlFor( object $row ): string {
        return RecordLink::detailUrlFor( 'players', $this->playerIdFor( $row ) );
    }

    /**
     * Recipients beyond the team's head coach, as WP user ids. Returned
     * per row, so the subclass must have carried them out of its own query
     * rather than looking them up here — a lookup in this method would be
     * the per-row query the contract forbids.
     *
     * @return list<int>
     */
    protected function extraRecipientsFor( object $row ): array {
        return [];
    }

    /**
     * Severity for one row. Override to age an occurrence up; the evaluator
     * recomputes it on every reconcile, so an ageing rule takes effect on
     * the next sweep rather than only on rows created after it.
     */
    protected function severityFor( object $row ): string {
        return $this->defaultSeverity();
    }

    /**
     * Extra payload keys for one row, merged over the standard ones. Use it
     * for anything the surface needs that the title does not already say.
     *
     * @return array<string,mixed>
     */
    protected function payloadFor( object $row ): array {
        return [];
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
            $subject_id = $this->subjectIdFor( $row );
            if ( $subject_id <= 0 ) continue;

            $recipients = [];
            $team_id    = (int) ( $row->team_id ?? 0 );
            if ( $team_id > 0 && isset( $head_coaches[ $team_id ] ) ) {
                $recipients[ $head_coaches[ $team_id ] ] = true;
            }
            foreach ( $this->extraRecipientsFor( $row ) as $extra ) {
                $extra = (int) $extra;
                if ( $extra > 0 ) $recipients[ $extra ] = true;
            }

            // No resolvable recipient means nobody would ever see this, so
            // writing it would be inventing work for the retention cron.
            if ( empty( $recipients ) ) continue;

            $payload = array_merge( [
                'title'       => $this->titleFor( $row ),
                'url'         => $this->urlFor( $row ),
                'player_name' => $this->playerName( $row ),
            ], $this->payloadFor( $row ) );

            foreach ( array_keys( $recipients ) as $user_id ) {
                $out[] = new AlertOccurrence(
                    $this->key(),
                    (int) $user_id,
                    $this->subjectType(),
                    $subject_id,
                    $this->severityFor( $row ),
                    $payload,
                    $this->playerIdFor( $row )
                );
            }
        }

        return $out;
    }

    /**
     * WP user id of each team's head coach, keyed by team id, in one query.
     *
     * Same resolution as `AbstractActivityAlert::headCoachesByTeam()` and
     * `Workflow\Resolvers\TeamHeadCoachResolver` — the `head_coach`
     * functional-role assignment in `tt_team_people`, the single source of
     * truth since #1315 retired the legacy `tt_teams.head_coach_id` column.
     * #2635 lifts that resolver into shared infrastructure and both copies
     * collapse into a call to it; until then, duplicating one join is
     * cheaper than a cross-module dependency on a class about to move.
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
     * Shared WHERE fragment for the players table: this club, active, not
     * archived, not in the recycle bin.
     *
     * A dropped club clause on an unauthenticated sweep crosses tenants
     * silently rather than failing, so it is never optional. `trashed_at`
     * matters just as much: `ArchiveRepository` treats archived and trashed
     * as independent flags, so a row someone put in the bin can still have
     * `archived_at IS NULL` and would otherwise keep producing alerts about
     * a player the academy has already deleted.
     */
    protected function activePlayerWhere( string $alias = 'p' ): string {
        return QueryHelpers::clubScopeWhere( $alias )
            . " AND {$alias}.status = 'active'"
            . " AND {$alias}.archived_at IS NULL"
            . " AND {$alias}.trashed_at IS NULL";
    }

    /** Display name for a row carrying `first_name` / `last_name`. */
    protected function playerName( object $row ): string {
        $name = trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) );
        return $name !== '' ? $name : __( 'this player', 'talenttrack' );
    }

    /**
     * A club-scoped numeric threshold from `tt_config`, floored at 1.
     *
     * Thresholds are configuration, not constants: an academy that
     * evaluates monthly and one that evaluates twice a season disagree
     * about what "not evaluated recently" means, and the one whose
     * threshold is wrong stops trusting the alert entirely.
     */
    protected function threshold( string $key, int $default ): int {
        if ( $this->config === null ) {
            $this->config = new ConfigService();
        }
        $value = $this->config->getInt( $key, $default );
        return $value > 0 ? $value : $default;
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

    /** Days from today until a stored date, never negative. */
    protected function daysUntil( string $date ): int {
        $date = QueryHelpers::usableDate( $date );
        if ( $date === null ) return 0;
        $ts = strtotime( $date );
        if ( $ts === false ) return 0;
        $diff = (int) ceil( ( $ts - current_time( 'timestamp' ) ) / DAY_IN_SECONDS );
        return max( 0, $diff );
    }
}
