<?php
namespace TT\Modules\Translations\Cache;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * TranslationsUsageRepository — per-month, per-engine usage tracking
 * for the soft cost cap (#0025).
 *
 * One row per (period_start, engine). `period_start` is the first day
 * of the month the row covers. `chars_billed` accumulates throughout
 * the month; `threshold_hit_at` records the first time we crossed the
 * configured nudge threshold so the admin notice fires at-most-once
 * per month.
 */
final class TranslationsUsageRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_translations_usage';
    }

    public static function periodStart( ?\DateTimeImmutable $when = null ): string {
        $when = $when ?: new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
        return $when->format( 'Y-m-01' );
    }

    public function charsThisMonth( string $engine ): int {
        global $wpdb;
        $period = self::periodStart();
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(chars_billed, 0) FROM {$this->table()}
             WHERE period_start = %s AND engine = %s AND club_id = %d LIMIT 1",
            $period, $engine, CurrentClub::id()
        ) );
    }

    public function increment( string $engine, int $chars ): void {
        global $wpdb;
        if ( $chars <= 0 ) return;
        $period = self::periodStart();
        $row    = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, chars_billed, api_calls FROM {$this->table()}
             WHERE period_start = %s AND engine = %s AND club_id = %d LIMIT 1",
            $period, $engine, CurrentClub::id()
        ) );
        if ( $row ) {
            $wpdb->update( $this->table(), [
                'chars_billed' => (int) $row->chars_billed + $chars,
                'api_calls'    => (int) $row->api_calls + 1,
            ], [ 'id' => (int) $row->id, 'club_id' => CurrentClub::id() ] );
        } else {
            $wpdb->insert( $this->table(), [
                'club_id'      => CurrentClub::id(),
                'period_start' => $period,
                'engine'       => $engine,
                'chars_billed' => $chars,
                'api_calls'    => 1,
            ] );
        }
    }

    /** Returns the recorded threshold-hit timestamp for this month, or null if not yet hit. */
    public function thresholdHitAt( string $engine ): ?string {
        global $wpdb;
        $period = self::periodStart();
        $val = $wpdb->get_var( $wpdb->prepare(
            "SELECT threshold_hit_at FROM {$this->table()}
             WHERE period_start = %s AND engine = %s AND club_id = %d LIMIT 1",
            $period, $engine, CurrentClub::id()
        ) );
        return $val ? (string) $val : null;
    }

    public function markThresholdHit( string $engine ): void {
        global $wpdb;
        $period = self::periodStart();
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table()} SET threshold_hit_at = %s
             WHERE period_start = %s AND engine = %s AND threshold_hit_at IS NULL AND club_id = %d",
            current_time( 'mysql', true ), $period, $engine, CurrentClub::id()
        ) );
    }

    /** @return array<int, object> */
    public function recent( int $months = 6 ): array {
        global $wpdb;
        $months = max( 1, min( 24, $months ) );
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE club_id = %d ORDER BY period_start DESC LIMIT " . ( $months * 4 ),
            CurrentClub::id()
        ) );
    }
}
