<?php
namespace TT\Modules\Measurements\Growth;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BmiSeriesBuilder (#2895) — pairs dated height and weight readings into a
 * BMI series for one player.
 *
 * WHY THE DATED READINGS, NOT `tt_players.height_cm` / `weight_kg`
 *
 * The snapshot columns on the player row are undated. They cannot support
 * a trend, and — worse — they cannot be checked for staleness: a height
 * recorded eighteen months ago looks exactly like one recorded last week.
 * A BMI built from a current weight and a year-old height is wrong in the
 * direction that matters, and nothing about the number would say so.
 *
 * THE PAIRING RULE
 *
 * A BMI point is anchored on a **weight** and paired with the most recent
 * **height** within `PAIR_WINDOW_DAYS` before or after it. Weight is the
 * anchor because it moves faster: an academy weighing monthly and measuring
 * height quarterly should get monthly BMI points, and anchoring on height
 * would throw most of the weights away.
 *
 * A weight with no height inside the window produces no point at all. That
 * is deliberate — the alternative is reaching further back for a height,
 * which is precisely the silent staleness the dated readings exist to
 * avoid.
 *
 * This class knows nothing about growth references. It answers "what was
 * this player's BMI, and when" — reference-independent, and testable
 * without one.
 */
final class BmiSeriesBuilder {

    /**
     * How far a height may sit from a weight and still describe the same
     * body. Thirty days is the shaped decision: long enough to pair a
     * monthly weigh-in with a quarterly height, short enough that an
     * adolescent's growth spurt does not get averaged across it.
     */
    public const PAIR_WINDOW_DAYS = 30;

    /**
     * Definition names that count as a height or a weight, lowercased.
     *
     * Matched on NAME rather than a fixed id because the academy owns its
     * measurement vocabulary — a Dutch install calls these `Lengte` and
     * `Gewicht`. Public so a view can ask "does this academy have both?"
     * using the same list this class searches, rather than a second copy that
     * drifts.
     *
     * @var list<string>
     */
    public const HEIGHT_NAMES = [ 'height', 'lengte', 'length', 'stature' ];

    /** @var list<string> */
    public const WEIGHT_NAMES = [ 'weight', 'gewicht', 'mass' ];

    private \wpdb $wpdb;
    private string $t_results;
    private string $t_definitions;

    public function __construct() {
        global $wpdb;
        $this->wpdb          = $wpdb;
        $this->t_results     = $wpdb->prefix . 'tt_measurement_results';
        $this->t_definitions = $wpdb->prefix . 'tt_measurement_definitions';
    }

    /**
     * The player's BMI points, oldest first.
     *
     * @return list<array{date:string, bmi:float, height_cm:float, weight_kg:float, height_date:string, gap_days:int}>
     */
    public function forPlayer( int $player_id, int $club_id ): array {
        if ( $player_id <= 0 ) return [];

        $heights = $this->readings( $player_id, $club_id, 'height' );
        $weights = $this->readings( $player_id, $club_id, 'weight' );

        if ( ! $heights || ! $weights ) return [];

        $points = [];
        foreach ( $weights as $w_date => $weight_kg ) {
            $match = $this->nearestHeight( $w_date, $heights );
            if ( $match === null ) continue;

            [ $h_date, $height_cm, $gap ] = $match;

            $metres = $height_cm / 100;
            if ( $metres <= 0 ) continue;

            $points[] = [
                'date'        => $w_date,
                'bmi'         => round( $weight_kg / ( $metres * $metres ), 2 ),
                'height_cm'   => $height_cm,
                'weight_kg'   => $weight_kg,
                'height_date' => $h_date,
                'gap_days'    => $gap,
            ];
        }

        usort( $points, static fn( $a, $b ): int => strcmp( $a['date'], $b['date'] ) );

        return $points;
    }

    /**
     * The nearest height to a weight date, within the window.
     *
     * Ties — a height the same number of days before and after — resolve to
     * the EARLIER one, because a height already taken is a measurement and a
     * height taken later is partly a different body.
     *
     * @param array<string,float> $heights date => cm
     * @return array{0:string,1:float,2:int}|null
     */
    private function nearestHeight( string $weight_date, array $heights ): ?array {
        $w_ts  = strtotime( $weight_date );
        if ( $w_ts === false ) return null;

        $best      = null;
        $best_gap  = PHP_INT_MAX;

        foreach ( $heights as $h_date => $cm ) {
            $h_ts = strtotime( $h_date );
            if ( $h_ts === false ) continue;

            $gap = (int) round( abs( $w_ts - $h_ts ) / DAY_IN_SECONDS );
            if ( $gap > self::PAIR_WINDOW_DAYS ) continue;

            // `<` not `<=`, and heights arrive oldest-first, so an exact tie
            // keeps the earlier reading.
            if ( $gap < $best_gap ) {
                $best_gap = $gap;
                $best     = [ (string) $h_date, (float) $cm, $gap ];
            }
        }

        return $best;
    }

    /**
     * Dated readings for a definition whose name matches height or weight,
     * oldest first, keyed by date.
     *
     * Matched on the definition NAME rather than a fixed id: the academy
     * owns its measurement vocabulary, and a Dutch install calls these
     * `Lengte` and `Gewicht`. Archived AND trashed results are excluded —
     * a reading somebody deleted should not appear in a trend. This reads
     * through the shared lifecycle clause (#2906) rather than a hand-rolled
     * `archived_at IS NULL`, which would have counted rows the recycle bin
     * hides in every list.
     *
     * @return array<string,float>
     */
    private function readings( int $player_id, int $club_id, string $kind ): array {
        $names = $kind === 'height' ? self::HEIGHT_NAMES : self::WEIGHT_NAMES;

        $placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );

        $params = array_merge( [ $player_id, $club_id ], $names );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT r.recorded_date, r.value_numeric
               FROM {$this->t_results} r
               JOIN {$this->t_definitions} d ON d.id = r.definition_id
              WHERE r.player_id = %d
                AND r.club_id = %d
                AND " . \TT\Infrastructure\Archive\ArchiveRepository::filterClause( 'active', 'r' ) . "
                AND r.value_numeric IS NOT NULL
                AND r.value_numeric > 0
                AND LOWER(TRIM(d.name)) IN ({$placeholders})
           ORDER BY r.recorded_date ASC, r.id ASC",
            ...$params
        ) );

        $out = [];
        foreach ( (array) $rows as $row ) {
            // Later reading on the same date wins — a correction recorded
            // after the fact is the one to keep.
            $out[ (string) $row->recorded_date ] = (float) $row->value_numeric;
        }

        return $out;
    }
}
