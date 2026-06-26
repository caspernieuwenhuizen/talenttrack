<?php
/**
 * Migration 0185 — backfill friendly position names into existing
 * position-change journey events (#1983).
 *
 * Older POSITION_CHANGED events baked raw position codes — or even the raw
 * JSON array `["CB","LB"]` — into their stored `summary` / `payload`, because
 * JourneyEventSubscriber::formatPositions() joined codes verbatim. The
 * forward fix now resolves each code to its long form; this migration brings
 * existing rows in line so the timeline reads the same for historical events.
 *
 * Strategy: for every tt_player_events row with source_entity_type =
 * 'position_change', rewrite embedded position codes — both the `["CB","LB"]`
 * JSON-array form and bare `CB` / `LB` tokens — to their long form, in the
 * site locale. Idempotent: the long forms contain no position-code tokens, so
 * a row already free of codes is left untouched and re-running is a no-op.
 *
 * Forward-only; run alone (data rewrite, no schema change).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Query\LabelTranslator;

return new class extends Migration {

    private const CODES = [ 'GK', 'CB', 'LB', 'RB', 'CDM', 'CM', 'CAM', 'LW', 'RW', 'ST', 'CF' ];

    public function getName(): string {
        return '0185_backfill_journey_position_labels';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_player_events';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, summary, payload FROM {$table} WHERE source_entity_type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix
                'position_change'
            )
        );
        if ( ! $rows ) {
            return;
        }

        $map = $this->labelMap();

        foreach ( $rows as $row ) {
            $new_summary = $this->humanize( (string) $row->summary, $map );
            $new_payload = $this->humanizePayload( (string) $row->payload, $map );

            if ( $new_summary === (string) $row->summary && $new_payload === (string) $row->payload ) {
                continue; // already friendly — idempotent skip.
            }

            $wpdb->update(
                $table,
                [ 'summary' => mb_substr( $new_summary, 0, 500 ), 'payload' => $new_payload ],
                [ 'id' => (int) $row->id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );
        }
    }

    /** Build a code => long-form-label map in the current (site) locale. */
    private function labelMap(): array {
        $map = [];
        foreach ( self::CODES as $code ) {
            $map[ $code ] = LabelTranslator::positionLabel( $code );
        }
        return $map;
    }

    /**
     * Replace JSON-array code fragments first (`["CB","LB"]` → "Centre back,
     * Left back"), then any remaining bare code tokens. Word-boundary match
     * keeps the codes from touching already-translated words.
     */
    private function humanize( string $text, array $map ): string {
        if ( $text === '' ) {
            return $text;
        }

        $text = preg_replace_callback(
            '/\[(?:\s*"[A-Za-z]{2,3}"\s*,?)+\]/',
            static function ( array $m ) use ( $map ): string {
                $decoded = json_decode( $m[0], true );
                if ( ! is_array( $decoded ) ) {
                    return $m[0];
                }
                $parts = [];
                foreach ( $decoded as $v ) {
                    $code    = strtoupper( trim( (string) $v ) );
                    $parts[] = $map[ $code ] ?? (string) $v;
                }
                return implode( ', ', $parts );
            },
            $text
        ) ?? $text;

        foreach ( $map as $code => $label ) {
            $text = preg_replace( '/\b' . preg_quote( $code, '/' ) . '\b/', $label, $text ) ?? $text;
        }
        return $text;
    }

    /** Humanise the `from` / `to` string values inside the JSON payload. */
    private function humanizePayload( string $payload, array $map ): string {
        if ( $payload === '' ) {
            return $payload;
        }
        $decoded = json_decode( $payload, true );
        if ( ! is_array( $decoded ) ) {
            return $payload;
        }
        foreach ( [ 'from', 'to' ] as $k ) {
            if ( isset( $decoded[ $k ] ) && is_string( $decoded[ $k ] ) ) {
                $decoded[ $k ] = $this->humanize( $decoded[ $k ], $map );
            }
        }
        $encoded = wp_json_encode( $decoded );
        return $encoded !== false ? $encoded : $payload;
    }
};
