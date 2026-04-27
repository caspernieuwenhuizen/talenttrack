<?php
namespace TT\Modules\TeamDevelopment\Cache;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\TeamDevelopment\FitResult;

/**
 * FitScoreCache — per-player fit-score cache, backed by the WP options
 * table. 24h TTL via transients.
 *
 * Cache keys:
 *   tt_pdp_fit_p{player_id}_t{template_id}        — array of FitResult per slot label
 *
 * Invalidation: any evaluation save for the player wipes the entry.
 * The hook lives in TeamDevelopmentModule (`tt_evaluation_saved`
 * action). Nightly recompute is a Sprint-2 v2 concern; v1 lazily
 * recomputes on read after invalidation.
 */
class FitScoreCache {

    private const TTL_SECONDS = 24 * HOUR_IN_SECONDS;

    /** @return FitResult|null */
    public function getSlot( int $player_id, int $template_id, string $slot_label ): ?FitResult {
        $bucket = $this->getAllSlots( $player_id, $template_id );
        if ( $bucket === null ) return null;
        return $bucket[ $slot_label ] ?? null;
    }

    public function putSlot( int $player_id, int $template_id, string $slot_label, FitResult $result ): void {
        $bucket = $this->getAllSlots( $player_id, $template_id ) ?? [];
        $bucket[ $slot_label ] = $result;
        $this->putAllSlots( $player_id, $template_id, $bucket );
    }

    /**
     * @return array<string, FitResult>|null
     */
    public function getAllSlots( int $player_id, int $template_id ): ?array {
        if ( $player_id <= 0 || $template_id <= 0 ) return null;
        $raw = get_transient( $this->key( $player_id, $template_id ) );
        if ( ! is_array( $raw ) ) return null;
        $out = [];
        foreach ( $raw as $label => $payload ) {
            if ( ! is_array( $payload ) ) continue;
            $out[ (string) $label ] = new FitResult(
                (float) ( $payload['score'] ?? 0.0 ),
                is_array( $payload['breakdown'] ?? null ) ? $payload['breakdown'] : [],
                (string) ( $payload['rationale'] ?? '' ),
                (float) ( $payload['side_preference_modifier'] ?? 0.0 )
            );
        }
        return $out;
    }

    /**
     * @param array<string, FitResult> $bucket
     */
    public function putAllSlots( int $player_id, int $template_id, array $bucket ): void {
        if ( $player_id <= 0 || $template_id <= 0 ) return;
        $serial = [];
        foreach ( $bucket as $label => $result ) {
            $serial[ $label ] = $result->toArray();
        }
        set_transient( $this->key( $player_id, $template_id ), $serial, self::TTL_SECONDS );
    }

    /** Wipe every cached template for a player. */
    public function invalidate( int $player_id ): void {
        if ( $player_id <= 0 ) return;
        global $wpdb;
        $like = $wpdb->esc_like( '_transient_tt_pdp_fit_p' . $player_id . '_t' ) . '%';
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $like
        ) );
        foreach ( (array) $rows as $name ) {
            $key = preg_replace( '/^_transient_/', '', (string) $name );
            if ( $key !== '' ) delete_transient( $key );
        }
    }

    private function key( int $player_id, int $template_id ): string {
        return 'tt_pdp_fit_p' . $player_id . '_t' . $template_id;
    }
}
