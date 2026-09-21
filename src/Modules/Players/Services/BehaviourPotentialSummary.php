<?php
namespace TT\Modules\Players\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Alerts\Definitions\PotentialStaleAlert;
use TT\Modules\Players\PlayerStatusModule;
use TT\Modules\Players\Repositories\PlayerBehaviourRatingsRepository;
use TT\Modules\Players\Repositories\PlayerPotentialRepository;

/**
 * Where a player stands on behaviour and potential, and what the viewer may
 * record against them (#3967).
 *
 * The profile's Behaviour & potential card composes this; it decides
 * nothing itself. Kept out of the view so the answer — the latest rating,
 * the 90-day average the traffic light reads, the current band and whether
 * it is overdue — is the same wherever it is asked.
 *
 * A half is `null` when its feature is switched off for the academy. That
 * is "we do not do this here", which the card shows by leaving the half
 * out, not by an empty state.
 */
final class BehaviourPotentialSummary {

    /** The window the shipped methodology averages behaviour over. */
    public const BEHAVIOUR_WINDOW_DAYS = 90;

    private const DEFAULT_STALE_DAYS = 180;

    private PlayerBehaviourRatingsRepository $behaviour;
    private PlayerPotentialRepository $potential;

    public function __construct(
        ?PlayerBehaviourRatingsRepository $behaviour = null,
        ?PlayerPotentialRepository $potential = null
    ) {
        $this->behaviour = $behaviour ?? new PlayerBehaviourRatingsRepository();
        $this->potential = $potential ?? new PlayerPotentialRepository();
    }

    /**
     * Days after which a potential band counts as overdue.
     *
     * The club's `alerts_potential_stale_days`, the number the
     * *Potential not revisited* alert reads, so a screen and the reminder
     * can never disagree about what late means.
     */
    public static function staleDays(): int {
        $days = (int) QueryHelpers::get_config(
            PotentialStaleAlert::CONFIG_KEY_STALE_DAYS,
            (string) self::DEFAULT_STALE_DAYS
        );
        return $days > 0 ? $days : self::DEFAULT_STALE_DAYS;
    }

    /**
     * @return array{
     *   behaviour: ?array{
     *     can_record: bool,
     *     latest: ?array{rating: float, rated_at: string, rated_by: string},
     *     average: ?float,
     *     window_days: int
     *   },
     *   potential: ?array{
     *     can_record: bool,
     *     applies: bool,
     *     current: ?array{band: string, label: string, set_at: string, set_by: string},
     *     days_since: ?int,
     *     overdue: bool,
     *     entries: int
     *   }
     * }
     */
    public function forPlayer( int $player_id, ?object $player, int $user_id ): array {
        return [
            'behaviour' => $this->behaviourHalf( $player_id, $user_id ),
            'potential' => $this->potentialHalf( $player_id, $player, $user_id ),
        ];
    }

    /**
     * @return ?array{
     *   can_record: bool,
     *   latest: ?array{rating: float, rated_at: string, rated_by: string},
     *   average: ?float,
     *   window_days: int
     * }
     */
    private function behaviourHalf( int $player_id, int $user_id ): ?array {
        if ( ! \TT\Core\FeatureRegistry::isEnabled( 'behaviour_rating' ) ) return null;

        $latest = null;
        $rows   = $this->behaviour->listForPlayer( $player_id, 1 );
        if ( $rows ) {
            $row    = (array) $rows[0];
            $latest = [
                'rating'   => (float) ( $row['rating'] ?? 0 ),
                'rated_at' => (string) ( $row['rated_at'] ?? '' ),
                'rated_by' => self::displayName( (int) ( $row['rated_by'] ?? 0 ) ),
            ];
        }

        $now     = current_time( 'timestamp' );
        $average = $this->behaviour->averageInWindow(
            $player_id,
            gmdate( 'Y-m-d H:i:s', $now - self::BEHAVIOUR_WINDOW_DAYS * DAY_IN_SECONDS ),
            gmdate( 'Y-m-d H:i:s', $now )
        );

        return [
            'can_record'  => PlayerStatusModule::behaviourCaptureAvailableFor( $player_id, $user_id ),
            'latest'      => $latest,
            'average'     => $average,
            'window_days' => self::BEHAVIOUR_WINDOW_DAYS,
        ];
    }

    /**
     * @return ?array{
     *   can_record: bool,
     *   applies: bool,
     *   current: ?array{band: string, label: string, set_at: string, set_by: string},
     *   days_since: ?int,
     *   overdue: bool,
     *   entries: int
     * }
     */
    private function potentialHalf( int $player_id, ?object $player, int $user_id ): ?array {
        if ( ! \TT\Core\FeatureRegistry::isEnabled( 'potential_rating' ) ) return null;

        $dob     = $player !== null && isset( $player->date_of_birth ) ? (string) $player->date_of_birth : null;
        $history = $this->potential->historyFor( $player_id );

        $current    = null;
        $days_since = null;
        if ( $history ) {
            $row  = (array) $history[0];
            $band = (string) ( $row['potential_band'] ?? '' );
            $when = (string) ( $row['set_at'] ?? '' );

            $current = [
                'band'   => $band,
                'label'  => PotentialTrajectory::labelFor( $band ),
                'set_at' => $when,
                'set_by' => self::displayName( (int) ( $row['set_by'] ?? 0 ) ),
            ];

            $ts = $when !== '' ? strtotime( $when ) : false;
            if ( $ts !== false ) {
                $days_since = max( 0, (int) floor( ( current_time( 'timestamp' ) - $ts ) / DAY_IN_SECONDS ) );
            }
        }

        return [
            'can_record' => PlayerStatusModule::potentialCaptureAvailableFor( $player_id, $user_id ),
            'applies'    => PlayerStatusModule::potentialAppliesAtBirthdate( $dob ),
            'current'    => $current,
            'days_since' => $days_since,
            'overdue'    => $days_since !== null && $days_since >= self::staleDays(),
            'entries'    => count( $history ),
        ];
    }

    private static function displayName( int $user_id ): string {
        if ( $user_id <= 0 ) return '';
        $user = get_userdata( $user_id );
        return $user instanceof \WP_User ? (string) $user->display_name : '';
    }
}
