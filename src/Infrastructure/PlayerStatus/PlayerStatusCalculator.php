<?php
namespace TT\Infrastructure\PlayerStatus;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\PotentialBand;
use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Players\Repositories\PlayerBehaviourRatingsRepository;
use TT\Modules\Players\Repositories\PlayerPotentialRepository;

/**
 * PlayerStatusCalculator (#0057 Sprint 2) — produces a `StatusVerdict`
 * from a player's recent inputs.
 *
 * Pure / stateless. Same inputs → same output. Caching happens at the
 * read-model layer (Sprint 4), not here.
 *
 * Inputs and weights come from the methodology config. Each input is
 * normalised to a 0-100 sub-score before weighting:
 *   - ratings:    average eval rating × ( 100 / rating_max )
 *   - behaviour:  average behaviour × ( 100 / rating_max )
 *   - attendance: already 0-100
 *
 * v3.110.116 — ratings + behaviour normalisation reads `rating_max`
 * from `tt_config` so the calculator stays correct after the
 * 1–5 → 5–10 scale flip. Previously hardcoded `×10` (ratings) and
 * `÷ 5 × 100` (behaviour) which both assumed the legacy scale.
 *   - potential:  banded (exceptional=100 / ahead=80 / on_track=60 /
 *                 needs_time=40 / below_level=20)
 *
 * Edge cases:
 *   - Player with no evaluations + no behaviour + < 3 sessions →
 *     `unknown` (grey), reason "needs first evaluation".
 *   - Behaviour < behaviour_floor → status floored to amber/red even
 *     if the composite score would be green.
 */
final class PlayerStatusCalculator {

    private const POTENTIAL_BAND_SCORES = [
        PotentialBand::EXCEPTIONAL => 100,
        PotentialBand::AHEAD       => 80,
        PotentialBand::ON_TRACK    => 60,
        PotentialBand::NEEDS_TIME  => 40,
        PotentialBand::BELOW_LEVEL => 20,
    ];

    private PlayerBehaviourRatingsRepository $behaviourRepo;
    private PlayerPotentialRepository $potentialRepo;
    private PlayerAttendanceCalculator $attendanceCalc;

    public function __construct(
        ?PlayerBehaviourRatingsRepository $behaviour = null,
        ?PlayerPotentialRepository $potential = null,
        ?PlayerAttendanceCalculator $attendance = null
    ) {
        $this->behaviourRepo  = $behaviour  ?? new PlayerBehaviourRatingsRepository();
        $this->potentialRepo  = $potential  ?? new PlayerPotentialRepository();
        $this->attendanceCalc = $attendance ?? new PlayerAttendanceCalculator();
    }

    /**
     * @param array<string,mixed>|null $methodology  Pass null for
     *                                               auto-resolve via MethodologyResolver.
     */
    public function calculate( int $player_id, ?string $as_of_date = null, ?array $methodology = null ): StatusVerdict {
        return $this->calculateMany( [ $player_id ], $as_of_date, $methodology )[ $player_id ];
    }

    /**
     * #4945 — `calculate()` for a squad. Each input the rule reads is read
     * once for every player (one statement per input: ratings, behaviour,
     * attendance, potential, the methodology, and `rating_max` once), then
     * the same rule (`compose()`) runs per player. `calculate()` is this for
     * one player, so there is one rule. Every requested player is present,
     * keyed by id.
     *
     * @param list<int>                $player_ids
     * @param array<string,mixed>|null $methodology Pass null to resolve each player's own.
     * @return array<int, StatusVerdict>
     */
    public function calculateMany( array $player_ids, ?string $as_of_date = null, ?array $methodology = null ): array {
        $ids = array_values( array_unique( array_map( 'intval', $player_ids ) ) );
        if ( $ids === [] ) return [];

        $as_of       = $as_of_date ?? gmdate( 'Y-m-d H:i:s' );
        $window_to   = $as_of_date ?? gmdate( 'Y-m-d' );
        $window_from = gmdate( 'Y-m-d', strtotime( $window_to . ' -90 days' ) );

        $methodologies = $methodology === null ? MethodologyResolver::forPlayers( $ids ) : [];
        $ratings       = $this->ratingsAverages( $ids, $window_from, $window_to );
        $behaviour     = $this->behaviourRepo->averagesInWindow( $ids, $window_from . ' 00:00:00', $window_to . ' 23:59:59' );
        $attendance    = $this->attendanceCalc->scoresFor( $ids, $window_from, $window_to );
        $potential     = $this->potentialRepo->latestForMany( $ids );
        $rating_max    = max( 1.0, (float) QueryHelpers::get_config( 'rating_max', '10' ) );

        $out = [];
        foreach ( $ids as $id ) {
            $out[ $id ] = $this->compose(
                $methodology ?? ( $methodologies[ $id ] ?? MethodologyResolver::shippedDefault() ),
                $as_of,
                $ratings[ $id ] ?? null,
                $behaviour[ $id ] ?? null,
                $attendance[ $id ] ?? $this->attendanceCalc->scoreFor( $id, $window_from, $window_to ),
                $potential[ $id ] ?? null,
                $rating_max
            );
        }
        return $out;
    }

    /**
     * The status rule for one player, over inputs already read.
     *
     * @param array<string,mixed> $methodology
     * @param array{sessions:int,present:int,late:int,absent:int,excused:int,injured:int,suspended:int,attended:int,score:?float,low_confidence:bool} $att
     */
    private function compose( array $methodology, string $as_of, ?float $rating_avg, ?float $behaviour_avg, array $att, ?object $potential_row, float $rating_max ): StatusVerdict {
        $inputs  = [];
        $reasons = [];

        // Ratings: avg from tt_eval_ratings in the last 90 days, scaled
        // against the configured rating_max (v3.110.116 — was `× 10`).
        $rating_score = $rating_avg === null
            ? [ 'value' => null, 'score' => null ]
            : [ 'value' => $rating_avg, 'score' => round( ( $rating_avg / $rating_max ) * 100, 1 ) ];
        $inputs['ratings'] = [
            'value'  => $rating_score['value'],
            'weight' => (int) ( $methodology['inputs']['ratings']['weight'] ?? 0 ),
            'score'  => $rating_score['score'],
        ];
        if ( $rating_score['value'] === null ) $reasons[] = __( 'No evaluations in the last 90 days.', 'talenttrack' );

        // Behaviour: avg in window, scaled to 0-100 against the
        // configured rating_max (v3.110.116 — was hardcoded `/ 5`).
        $behaviour_score = $behaviour_avg !== null ? round( ( $behaviour_avg / $rating_max ) * 100, 1 ) : null;
        $inputs['behaviour'] = [
            'value'  => $behaviour_avg,
            'weight' => (int) ( $methodology['inputs']['behaviour']['weight'] ?? 0 ),
            'score'  => $behaviour_score,
        ];

        // Attendance: 0-100 directly from the calculator.
        $inputs['attendance'] = [
            'value'  => $att['score'],
            'weight' => (int) ( $methodology['inputs']['attendance']['weight'] ?? 0 ),
            'score'  => $att['score'],
        ];
        if ( $att['low_confidence'] ) $reasons[] = __( 'Sparse attendance data — confidence reduced.', 'talenttrack' );

        // Potential: banded; null when never set.
        $potential_band = $potential_row ? (string) ( $potential_row->potential_band ?? '' ) : null;
        $potential_score = $potential_band !== null
            ? (float) ( self::POTENTIAL_BAND_SCORES[ $potential_band ] ?? 50 )
            : null;
        $inputs['potential'] = [
            'value'  => $potential_band !== null ? (float) ( self::POTENTIAL_BAND_SCORES[ $potential_band ] ?? 50 ) : null,
            'weight' => (int) ( $methodology['inputs']['potential']['weight'] ?? 0 ),
            'score'  => $potential_score,
        ];

        // Compose: weighted average over enabled inputs that have a value.
        //
        // #3413 — the same loop now also records what it skipped.
        // `$enabled_weight_total` is what the methodology *asks* for;
        // `$weight_total` is what it got. A zero-weight input is neither:
        // it contributes nothing to the composite, so it cannot be missing
        // from it either. Dropping it before the null check keeps the
        // arithmetic identical to before (it added 0 to both sums) while
        // keeping `missing_inputs` about evidence that would have counted.
        $weighted_sum         = 0.0;
        $weight_total         = 0;
        $enabled_weight_total = 0;
        /** @var list<string> $missing */
        $missing = [];
        foreach ( $inputs as $key => $row ) {
            $enabled = (bool) ( $methodology['inputs'][ $key ]['enabled'] ?? true );
            if ( ! $enabled ) continue;
            $weight = (int) $row['weight'];
            if ( $weight <= 0 ) continue;
            $enabled_weight_total += $weight;
            if ( $row['score'] === null ) {
                $missing[] = (string) $key;
                continue;
            }
            $weighted_sum += $row['score'] * $weight;
            $weight_total += $weight;
        }

        $coverage = $enabled_weight_total > 0
            ? round( $weight_total / $enabled_weight_total, 4 )
            : 0.0;

        if ( $weight_total === 0 ) {
            // Unchanged: every input missing is still `unknown`, with the
            // reason it has always carried. Coverage is 0.0 and every
            // enabled input is named, so a consumer can tell "we know
            // nothing" from "we know most of it" without re-deriving it.
            $reasons[] = __( 'Insufficient signal — needs first evaluation and a few activities.', 'talenttrack' );
            return new StatusVerdict(
                StatusVerdict::COLOR_UNKNOWN,
                null,
                $inputs,
                $reasons,
                $as_of,
                (string) ( $methodology['version_id'] ?? 'shipped' ),
                0.0,
                $missing
            );
        }

        $composite = round( $weighted_sum / $weight_total, 1 );

        $amber_threshold = (float) ( $methodology['thresholds']['amber_below'] ?? 60 );
        $red_threshold   = (float) ( $methodology['thresholds']['red_below']   ?? 40 );

        $color = StatusVerdict::COLOR_GREEN;
        if ( $composite < $red_threshold ) {
            $color = StatusVerdict::COLOR_RED;
            $reasons[] = sprintf( __( 'Composite %s below red threshold %s.', 'talenttrack' ), $composite, $red_threshold );
        } elseif ( $composite < $amber_threshold ) {
            $color = StatusVerdict::COLOR_AMBER;
            $reasons[] = sprintf( __( 'Composite %s below amber threshold %s.', 'talenttrack' ), $composite, $amber_threshold );
        }

        // Behaviour floor — can downgrade green→amber when behaviour
        // is below the configured floor.
        $floor = (float) ( $methodology['floor_rules']['behaviour_floor_below'] ?? 0 );
        if ( $floor > 0 && $behaviour_avg !== null && $behaviour_avg < $floor ) {
            if ( $color === StatusVerdict::COLOR_GREEN ) {
                $color = StatusVerdict::COLOR_AMBER;
                $reasons[] = sprintf( __( 'Behaviour %s below floor %s — capped at amber.', 'talenttrack' ), $behaviour_avg, $floor );
            }
        }

        // #3413 — say it out loud. A partial verdict reaching a surface
        // without this reads as a full one, and the surface has no way to
        // tell. Deliberately after the `unknown` return above, so the
        // all-inputs-missing case keeps exactly the reason it had.
        if ( $missing !== [] ) {
            $labels = array_map(
                static fn( string $key ): string => StatusVerdict::inputLabel( $key ),
                $missing
            );
            $reasons[] = sprintf(
                /* translators: %s is a comma-separated list of missing inputs, e.g. "potential". */
                __( 'Computed without %s.', 'talenttrack' ),
                implode( ', ', $labels )
            );
        }

        return new StatusVerdict(
            $color,
            $composite,
            $inputs,
            $reasons,
            $as_of,
            (string) ( $methodology['version_id'] ?? 'shipped' ),
            $coverage,
            $missing
        );
    }

    /**
     * Each player's average evaluation rating in the window, rounded to two
     * places, in one statement. A player with no rating is absent.
     *
     * @param list<int> $player_ids
     * @return array<int, float>
     */
    private function ratingsAverages( array $player_ids, string $from, string $to ): array {
        if ( $player_ids === [] ) return [];
        global $wpdb;
        $in = implode( ',', array_fill( 0, count( $player_ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.player_id, AVG(r.rating) AS avg_rating
               FROM {$wpdb->prefix}tt_eval_ratings r
               JOIN {$wpdb->prefix}tt_evaluations e ON e.id = r.evaluation_id AND e.club_id = r.club_id
              WHERE e.player_id IN ({$in})
                AND e.club_id = %d
                AND " . ArchiveRepository::filterClause( 'active', 'e' ) . "
                AND e.eval_date >= %s
                AND e.eval_date <= %s
              GROUP BY e.player_id",
            ...array_merge( $player_ids, [ CurrentClub::id(), $from, $to ] )
        ) );
        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $r ) {
            if ( is_object( $r ) && ( $r->avg_rating ?? null ) !== null ) $out[ (int) ( $r->player_id ?? 0 ) ] = round( (float) $r->avg_rating, 2 );
        }
        return $out;
    }
}
