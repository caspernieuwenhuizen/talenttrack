<?php
namespace TT\Modules\Alerts\Definitions;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Authorization\MatrixGate;

/**
 * NoMeasurementThisSeasonAlert (#2636, epic #2629) — a player has not been
 * measured at all this season.
 *
 * Which player question does this answer? *Where have they come from?* —
 * physically. Height, weight, sprint times and the rest of the testing
 * battery are the only part of a player's record that is not somebody's
 * opinion, and they are what makes growth visible: the maturation curve, the
 * dip that turns out to be a growth spurt, the comparison against the age
 * band. A season with no measurement leaves a permanent hole in that curve
 * — a gap you cannot fill retrospectively, because the player has already
 * grown.
 *
 * ## Season, not a rolling window
 *
 * The academy's testing battery runs on a season rhythm, so the question is
 * "was this player tested this season", not "in the last N days". The
 * current season comes from `tt_seasons.is_current`, the same flag
 * `SeasonsRepository::current()` reads.
 *
 * `alerts_measurement_grace_days` (60 by default) holds the alert back until
 * the season has been running long enough for the battery to have happened.
 * Firing in week one would tell every coach in the academy about every
 * player at once, which is indistinguishable from telling them nothing.
 *
 * ## Why the capability gate is doubled
 *
 * Measurements have no legacy `tt_*` capability — the module is governed
 * entirely by the `measurements` matrix entity. `capRequired()` can only
 * express a flat cap, so it declares `tt_view_players` (you must be able to
 * see the player at all) and this definition additionally filters its own
 * recipients through `MatrixGate` for `measurements:read`. The result is
 * checked per recipient, not per row: the set is the head coaches of the
 * teams in the result, memoised, which is a handful of calls however many
 * players are in the list.
 */
final class NoMeasurementThisSeasonAlert extends AbstractPlayerAlert {

    /** tt_config key: days into the season before the alert appears. */
    public const CONFIG_KEY_GRACE_DAYS = 'alerts_measurement_grace_days';

    private const DEFAULT_GRACE_DAYS = 60;

    public function key(): string {
        return 'measurements.none_this_season';
    }

    public function module(): string {
        return 'measurements';
    }

    public function label(): string {
        return __( 'No measurement this season', 'talenttrack' );
    }

    public function description(): string {
        return __( 'A player has no measurement recorded this season. Growth data is the one part of the record that is not an opinion, and a missed season leaves a hole in the curve that cannot be filled later.', 'talenttrack' );
    }

    /**
     * The flat half of the gate — see the class docblock. The matrix half
     * (`measurements:read`) is applied to recipients in `evaluate()`.
     */
    public function capRequired(): string {
        return 'tt_view_players';
    }

    /** Halfway through the season with nothing recorded is no longer a delay. */
    protected function severityFor( object $row ): string {
        $grace = $this->threshold( self::CONFIG_KEY_GRACE_DAYS, self::DEFAULT_GRACE_DAYS );
        return $this->daysSince( (string) ( $row->season_start ?? '' ) ) >= ( $grace * 3 )
            ? Severity::URGENT
            : Severity::ATTENTION;
    }

    protected function titleFor( object $row ): string {
        $season = trim( (string) ( $row->season_name ?? '' ) );
        if ( $season === '' ) {
            return sprintf(
                /* translators: %s: player name */
                __( '%s has no measurement recorded this season.', 'talenttrack' ),
                $this->playerName( $row )
            );
        }

        return sprintf(
            /* translators: 1: player name, 2: season name */
            __( '%1$s has no measurement recorded in %2$s.', 'talenttrack' ),
            $this->playerName( $row ),
            $season
        );
    }

    /** @return array<string,mixed> */
    protected function payloadFor( object $row ): array {
        return [ 'season_name' => (string) ( $row->season_name ?? '' ) ];
    }

    /**
     * The base class resolves the audience; this filters it against the
     * matrix entity that actually governs measurements.
     *
     * The decision is memoised per recipient, so the cost is one call per
     * head coach in the result set rather than one per player — the
     * per-row-query shape `AlertInterface` forbids.
     *
     * @return list<\TT\Modules\Alerts\Domain\AlertOccurrence>
     */
    public function evaluate( AlertContext $context ): array {
        $decided = [];
        $out     = [];

        foreach ( parent::evaluate( $context ) as $occurrence ) {
            $user_id = (int) $occurrence->recipientUserId;
            if ( ! array_key_exists( $user_id, $decided ) ) {
                $decided[ $user_id ] = MatrixGate::canAnyScope( $user_id, 'measurements', 'read' );
            }
            if ( $decided[ $user_id ] ) {
                $out[] = $occurrence;
            }
        }

        return $out;
    }

    /** @return list<object> */
    protected function rows( AlertContext $context ): array {
        global $wpdb;
        $p     = $wpdb->prefix;
        $grace = $this->threshold( self::CONFIG_KEY_GRACE_DAYS, self::DEFAULT_GRACE_DAYS );

        // The current season is joined as a one-row derived table rather
        // than fetched first and interpolated: it keeps this to a single
        // statement, and it means an academy with no current season set
        // produces no rows instead of an error.
        //
        // The measurements module's own repositories filter on
        // `archived_at` only; both flags are checked here because
        // `ArchiveRepository` treats archived and trashed independently and
        // a binned result is not a measurement.
        $sql = $wpdb->prepare(
            "SELECT p.id AS player_id, p.first_name, p.last_name, p.team_id,
                    s.name AS season_name, s.start_date AS season_start
               FROM {$p}tt_players p
         INNER JOIN (
                    SELECT id, name, start_date, end_date
                      FROM {$p}tt_seasons
                     WHERE club_id = %d AND is_current = 1
                  ORDER BY id DESC
                     LIMIT 1
                 ) s
              WHERE " . $this->activePlayerWhere( 'p' ) . "
                AND p.team_id > 0
                AND s.start_date <= DATE_SUB( CURDATE(), INTERVAL %d DAY )
                AND NOT EXISTS (
                    SELECT 1 FROM {$p}tt_measurement_results r
                     WHERE r.player_id = p.id
                       AND r.club_id = p.club_id
                       AND r.archived_at IS NULL
                       AND r.trashed_at IS NULL
                       AND r.recorded_date BETWEEN s.start_date AND s.end_date
                )"
            . $context->applyScope( self::SUBJECT_TYPE, 'p.id' ) . "
              ORDER BY p.team_id ASC, p.id ASC",
            CurrentClub::id(),
            $grace
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql );
        return is_array( $rows ) ? $rows : [];
    }
}
