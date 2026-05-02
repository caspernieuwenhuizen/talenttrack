<?php
namespace TT\Infrastructure\PlayerStatus;

if ( ! defined( 'ABSPATH' ) ) exit;

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
 *   - ratings:    average eval rating (0-10 scale) → ×10
 *   - behaviour:  average behaviour (1-5 scale) → ×20
 *   - attendance: already 0-100
 *   - potential:  banded (first_team=100 / professional_elsewhere=80 /
 *                 semi_pro=60 / top_amateur=40 / recreational=20)
 *
 * Edge cases:
 *   - Player with no evaluations + no behaviour + < 3 sessions →
 *     `unknown` (grey), reason "needs first evaluation".
 *   - Behaviour < behaviour_floor → status floored to amber/red even
 *     if the composite score would be green.
 */
final class PlayerStatusCalculator {

    private const POTENTIAL_BAND_SCORES = [
        'first_team'             => 100,
        'professional_elsewhere' => 80,
        'semi_pro'               => 60,
        'top_amateur'             => 40,
        'recreational'           => 20,
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
        $methodology = $methodology ?? MethodologyResolver::forPlayer( $player_id );
        $as_of       = $as_of_date ?? gmdate( 'Y-m-d H:i:s' );
        $window_to   = $as_of_date ?? gmdate( 'Y-m-d' );
        $window_from = gmdate( 'Y-m-d', strtotime( $window_to . ' -90 days' ) );

        $inputs  = [];
        $reasons = [];

        // Ratings: avg from tt_eval_ratings in the last 90 days, scaled 0-10 → 0-100.
        $rating_score = $this->ratingsScore( $player_id, $window_from, $window_to );
        $inputs['ratings'] = [
            'value'  => $rating_score['value'],
            'weight' => (int) ( $methodology['inputs']['ratings']['weight'] ?? 0 ),
            'score'  => $rating_score['score'],
        ];
        if ( $rating_score['value'] === null ) $reasons[] = __( 'No evaluations in the last 90 days.', 'talenttrack' );

        // Behaviour: avg in window, 1-5 scaled to 0-100.
        $behaviour_avg = $this->behaviourRepo->averageInWindow( $player_id, $window_from . ' 00:00:00', $window_to . ' 23:59:59' );
        $behaviour_score = $behaviour_avg !== null ? round( ( $behaviour_avg / 5 ) * 100, 1 ) : null;
        $inputs['behaviour'] = [
            'value'  => $behaviour_avg,
            'weight' => (int) ( $methodology['inputs']['behaviour']['weight'] ?? 0 ),
            'score'  => $behaviour_score,
        ];

        // Attendance: 0-100 directly from the calculator.
        $att = $this->attendanceCalc->scoreFor( $player_id, $window_from, $window_to );
        $inputs['attendance'] = [
            'value'  => $att['score'],
            'weight' => (int) ( $methodology['inputs']['attendance']['weight'] ?? 0 ),
            'score'  => $att['score'],
        ];
        if ( $att['low_confidence'] ) $reasons[] = __( 'Sparse attendance data — confidence reduced.', 'talenttrack' );

        // Potential: banded; null when never set.
        $potential_row  = $this->potentialRepo->latestFor( $player_id );
        $potential_band = $potential_row ? (string) $potential_row->potential_band : null;
        $potential_score = $potential_band !== null
            ? (float) ( self::POTENTIAL_BAND_SCORES[ $potential_band ] ?? 50 )
            : null;
        $inputs['potential'] = [
            'value'  => $potential_band !== null ? (float) ( self::POTENTIAL_BAND_SCORES[ $potential_band ] ?? 50 ) : null,
            'weight' => (int) ( $methodology['inputs']['potential']['weight'] ?? 0 ),
            'score'  => $potential_score,
        ];

        // Compose: weighted average over enabled inputs that have a value.
        $weighted_sum = 0.0;
        $weight_total = 0;
        foreach ( $inputs as $key => $row ) {
            $enabled = (bool) ( $methodology['inputs'][ $key ]['enabled'] ?? true );
            if ( ! $enabled ) continue;
            if ( $row['score'] === null ) continue;
            $weighted_sum += $row['score'] * $row['weight'];
            $weight_total += $row['weight'];
        }

        if ( $weight_total === 0 ) {
            $reasons[] = __( 'Insufficient signal — needs first evaluation and a few activities.', 'talenttrack' );
            return new StatusVerdict(
                StatusVerdict::COLOR_UNKNOWN,
                null,
                $inputs,
                $reasons,
                $as_of,
                (string) ( $methodology['version_id'] ?? 'shipped' )
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

        return new StatusVerdict(
            $color,
            $composite,
            $inputs,
            $reasons,
            $as_of,
            (string) ( $methodology['version_id'] ?? 'shipped' )
        );
    }

    /**
     * @return array{value:?float,score:?float}
     */
    private function ratingsScore( int $player_id, string $from, string $to ): array {
        global $wpdb;
        $avg = $wpdb->get_var( $wpdb->prepare(
            "SELECT AVG(r.rating)
               FROM {$wpdb->prefix}tt_eval_ratings r
               JOIN {$wpdb->prefix}tt_evaluations e ON e.id = r.evaluation_id AND e.club_id = r.club_id
              WHERE e.player_id = %d
                AND e.club_id = %d
                AND e.eval_date >= %s
                AND e.eval_date <= %s",
            $player_id, CurrentClub::id(), $from, $to
        ) );
        if ( $avg === null ) return [ 'value' => null, 'score' => null ];
        $value = round( (float) $avg, 2 );
        return [ 'value' => $value, 'score' => round( $value * 10, 1 ) ];
    }
}
