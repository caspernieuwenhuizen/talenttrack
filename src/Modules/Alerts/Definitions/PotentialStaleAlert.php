<?php
namespace TT\Modules\Alerts\Definitions;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;

/**
 * PotentialStaleAlert (#3225, epic #2629) — nobody has revisited what this
 * player could become.
 *
 * Which player question does this answer? *Where are they going?* —
 * potential is the academy's own answer to it, and an answer from eighteen
 * months ago is not one. A band set at intake and never revised is still
 * read as the current view by everything downstream: `PlayerStatusCalculator`
 * weights it into the traffic-light status, `DevelopmentScorer` reads it in
 * team chemistry, `EvidencePacket` reads it in the PDP. A stale input
 * propagates silently into all three, and nothing on any screen says the
 * number is old.
 *
 * ## Why an alert and not a reminder
 *
 * This is a **state-derived, self-resolving** condition, which is what the
 * reconcile loop is for: somebody sets the potential and the alert clears
 * itself on the next pass. No dismissal state to manage, nothing to
 * unsubscribe from. A bespoke reminder would have needed all of that and
 * would have kept firing after the work was done.
 *
 * ## The clock starts at the later of two dates
 *
 * A player who joined three weeks ago is not overdue, so the window is
 * measured from the later of their most recent potential entry and their
 * creation date. That also makes "never set at all" the same condition
 * rather than a second definition: a player with no potential row is
 * measured from when they joined, and becomes overdue on the same clock as
 * everybody else. Being invisible is the worse failure — a player nobody
 * has ever assessed is more concerning than one assessed a while ago, not
 * less.
 *
 * ## Trial and archived players are out
 *
 * A trialling player has no quarterly cadence to be late on — that is what
 * the trial case is for — and an archived one has left.
 * `activePlayerWhere()` already excludes both.
 *
 * ## The window
 *
 * `alerts_potential_stale_days` (180 by default) — two missed quarters
 * against a stated quarterly cadence. One missed quarter is a busy season;
 * two is nobody looking. Club-scoped config, so an academy running a
 * different rhythm changes it without waiting for a release.
 *
 * ## Who is told, and why it is not simply the head coach
 *
 * `AbstractPlayerAlert` resolves each player's head coach, and on the
 * default seed a head coach holds `player_potential: read` at team scope —
 * **not `change`**. Sending them this would be telling somebody about work
 * they cannot do, which is the definition of noise and the fastest way to
 * train an academy to ignore the bell.
 *
 * So the audience is whoever can actually set potential: the head of
 * development and the club admin hold `player_potential: rcd` globally, and
 * they are added as extra recipients. Any head coach an academy HAS granted
 * the change activity to keeps getting it for their own teams. Everyone
 * else is filtered out in `evaluate()`.
 *
 * Custodians are resolved by enumerate-then-ask rather than
 * `get_users( [ 'capability' => … ] )`, for the reason
 * {@see AbstractDataQualityAlert::custodians()} documents at length: most
 * `tt_*` capabilities are matrix-derived and bridged at runtime through the
 * `user_has_cap` filter, which a meta query never runs — so the meta query
 * returns nobody on an install where the capability is very much held.
 */
final class PotentialStaleAlert extends AbstractPlayerAlert {

    /** tt_config key: days before a potential counts as stale. */
    public const CONFIG_KEY_STALE_DAYS = 'alerts_potential_stale_days';

    /** Two missed quarters against the stated quarterly cadence. */
    private const DEFAULT_STALE_DAYS = 180;

    public function key(): string {
        return 'players.potential_stale';
    }

    public function module(): string {
        return 'players';
    }

    public function label(): string {
        return __( 'Potential not revisited', 'talenttrack' );
    }

    public function description(): string {
        return __( "A player's potential has not been revisited for two quarters. It still feeds their status, their team-chemistry score and their development plan, so an out-of-date band quietly shapes decisions nobody is re-examining.", 'talenttrack' );
    }

    /**
     * Setting potential is what clears this, so this is the capability the
     * audience has to hold — see the class docblock for why the head coach
     * alone is the wrong answer.
     */
    public function capRequired(): string {
        return 'tt_set_player_potential';
    }

    /** How many custodians one evaluation will name. */
    private const MAX_CUSTODIANS = 20;

    /** Never scan past this many accounts looking for them. */
    private const SCAN_CEILING = 1000;

    /** @var list<int>|null memoised for the whole evaluation */
    private ?array $custodians = null;

    /**
     * The people who can set potential, added to every row.
     *
     * Resolved once per evaluation, not once per player — the sweep runs
     * across the whole academy and this must not become a per-row query.
     *
     * @return list<int>
     */
    protected function extraRecipientsFor( object $row ): array {
        if ( $this->custodians === null ) {
            $this->custodians = $this->resolveCustodians();
        }
        return $this->custodians;
    }

    /**
     * Drop any recipient who cannot act on this.
     *
     * The base class adds each player's head coach unconditionally, and on
     * the default seed that persona can read potential but not change it.
     * Same shape as `NoMeasurementThisSeasonAlert`, and memoised per
     * recipient so the cost is one check per person rather than per player.
     *
     * @return list<\TT\Modules\Alerts\Domain\AlertOccurrence>
     */
    public function evaluate( AlertContext $context ): array {
        $decided = [];
        $out     = [];

        foreach ( parent::evaluate( $context ) as $occurrence ) {
            $user_id = (int) $occurrence->recipientUserId;
            if ( ! array_key_exists( $user_id, $decided ) ) {
                $decided[ $user_id ] = user_can( $user_id, $this->capRequired() );
            }
            if ( $decided[ $user_id ] ) {
                $out[] = $occurrence;
            }
        }

        $this->custodians = null;

        return $out;
    }

    /**
     * Enumerate-then-ask. See the class docblock: a capability meta query
     * cannot see a matrix-derived grant, and would find nobody.
     *
     * @return list<int>
     */
    private function resolveCustodians(): array {
        if ( ! function_exists( 'get_users' ) ) return [];

        $ids = get_users( [
            'fields'  => 'ID',
            'number'  => self::SCAN_CEILING,
            'orderby' => 'ID',
            'order'   => 'ASC',
        ] );

        // No `is_array()` guard: `get_users( [ 'fields' => 'ID' ] )` is
        // typed as an array, and PHPStan level 8 rejects the dead else
        // branch rather than letting a defensive habit stand in for a
        // real possibility.
        $cap = $this->capRequired();
        $out = [];
        foreach ( $ids as $id ) {
            $id = (int) $id;
            if ( $id <= 0 ) continue;
            if ( ! user_can( $id, $cap ) ) continue;
            $out[] = $id;
            if ( count( $out ) >= self::MAX_CUSTODIANS ) break;
        }

        return $out;
    }

    /** A year without a look is a different thing from two quarters. */
    protected function severityFor( object $row ): string {
        $stale = $this->threshold( self::CONFIG_KEY_STALE_DAYS, self::DEFAULT_STALE_DAYS );
        return $this->daysSince( (string) ( $row->last_looked ?? '' ) ) >= ( $stale * 2 )
            ? Severity::URGENT
            : Severity::ATTENTION;
    }

    protected function titleFor( object $row ): string {
        // Never assessed reads differently from gone stale, and conflating
        // them would send a coach looking for a revision that never
        // happened.
        if ( (int) ( $row->has_potential ?? 0 ) === 0 ) {
            return sprintf(
                /* translators: %s: player name */
                __( "%s has no potential recorded yet.", 'talenttrack' ),
                $this->playerName( $row )
            );
        }

        return sprintf(
            /* translators: 1: player name, 2: number of months */
            __( "%1\$s's potential has not been revisited in %2\$d months.", 'talenttrack' ),
            $this->playerName( $row ),
            (int) max( 1, (int) round( $this->daysSince( (string) ( $row->last_looked ?? '' ) ) / 30 ) )
        );
    }

    /** @return array<string,mixed> */
    protected function payloadFor( object $row ): array {
        return [
            'has_potential' => (int) ( $row->has_potential ?? 0 ) === 1,
            'last_looked'   => (string) ( $row->last_looked ?? '' ),
            'days_since'    => $this->daysSince( (string) ( $row->last_looked ?? '' ) ),
        ];
    }

    /**
     * One statement, no per-row queries.
     *
     * `last_looked` is the later of the player's most recent potential
     * entry and their creation date, via `GREATEST` over a correlated
     * subquery with `COALESCE` for the never-set case — which is why "never
     * assessed" needs no second definition.
     *
     * @return list<object>
     */
    protected function rows( AlertContext $context ): array {
        // #3243 — an academy that has switched potential capture off has
        // decided not to maintain it. Telling them it has gone stale is
        // nagging about work they have deliberately stopped doing, and it
        // would fire for every player at once. The feature flag alone: the
        // capability half of `potentialCaptureAvailable()` is per-user and
        // this runs in a sweep with no user.
        if ( ! \TT\Core\FeatureRegistry::isEnabled( 'potential_rating' ) ) return [];

        global $wpdb;
        $p     = $wpdb->prefix;
        $stale = $this->threshold( self::CONFIG_KEY_STALE_DAYS, self::DEFAULT_STALE_DAYS );

        $sql = $wpdb->prepare(
            "SELECT p.id AS player_id, p.first_name, p.last_name, p.team_id,
                    ( SELECT MAX( pot.set_at )
                        FROM {$p}tt_player_potential pot
                       WHERE pot.player_id = p.id AND pot.club_id = p.club_id
                    ) IS NOT NULL AS has_potential,
                    GREATEST(
                        COALESCE(
                            ( SELECT MAX( pot.set_at )
                                FROM {$p}tt_player_potential pot
                               WHERE pot.player_id = p.id AND pot.club_id = p.club_id
                            ),
                            p.created_at
                        ),
                        p.created_at
                    ) AS last_looked
               FROM {$p}tt_players p
              WHERE " . $this->activePlayerWhere( 'p' ) . "
                AND p.club_id = %d
                AND p.team_id > 0
                AND GREATEST(
                        COALESCE(
                            ( SELECT MAX( pot.set_at )
                                FROM {$p}tt_player_potential pot
                               WHERE pot.player_id = p.id AND pot.club_id = p.club_id
                            ),
                            p.created_at
                        ),
                        p.created_at
                    ) <= DATE_SUB( NOW(), INTERVAL %d DAY )"
            . $context->applyScope( self::SUBJECT_TYPE, 'p.id' ) . "
              ORDER BY p.team_id ASC, p.id ASC",
            CurrentClub::id(),
            $stale
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql );
        return is_array( $rows ) ? $rows : [];
    }
}
