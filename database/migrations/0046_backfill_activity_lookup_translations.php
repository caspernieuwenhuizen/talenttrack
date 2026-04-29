<?php
/**
 * Migration 0046 — Backfill missing translations on activity-related
 * lookup rows (#0061).
 *
 * Migration 0027 seeded `activity_type` (game/training/other) and
 * `game_subtype` (Friendly) without a `translations` JSON column.
 * Migration 0033 later tried to add NL translations but its insert
 * skipped existing rows, so installs that ran 0027 first end up with
 * untranslated rows. The dropdowns then fall back to `__($name)`,
 * which has no `.po` entry, so the raw English label shows.
 *
 * This migration writes `translations.nl_NL.name` on every activity-
 * related lookup row that is missing one. Idempotent — rows that
 * already have a translation are left alone, so re-running on an
 * install where 0033 / 0040 won the race is a no-op.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0046_backfill_activity_lookup_translations';
    }

    public function up(): void {
        $this->backfillByName( 'activity_type', [
            'training' => [ 'name' => 'Training' ],
            'game'     => [ 'name' => 'Wedstrijd' ],
            'other'    => [ 'name' => 'Overig' ],
            // The two added by migration 0040 — should already have
            // translations from that seed, but defensive backfill in
            // case 0040 ran on a row that existed first.
            'tournament' => [ 'name' => 'Toernooi' ],
            'meeting'    => [ 'name' => 'Bespreking' ],
        ] );

        $this->backfillByName( 'game_subtype', [
            'Friendly' => [ 'name' => 'Oefenwedstrijd' ],
            'League'   => [ 'name' => 'Competitie' ],
            'Cup'      => [ 'name' => 'Beker' ],
            // Lowercase variants in case the user renamed.
            'friendly' => [ 'name' => 'Oefenwedstrijd' ],
            'league'   => [ 'name' => 'Competitie' ],
            'cup'      => [ 'name' => 'Beker' ],
        ] );

        // activity_status + activity_source: migration 0040 included
        // translations, but defensive — same idempotency guard.
        $this->backfillByName( 'activity_status', [
            'planned'   => [ 'name' => 'Gepland' ],
            'completed' => [ 'name' => 'Voltooid' ],
            'cancelled' => [ 'name' => 'Geannuleerd' ],
        ] );

        $this->backfillByName( 'activity_source', [
            'manual'    => [ 'name' => 'Handmatig' ],
            'spond'     => [ 'name' => 'Spond' ],
            'generated' => [ 'name' => 'Gegenereerd' ],
        ] );
    }

    /**
     * For each row matching `(lookup_type, name)`, ensure
     * `translations.nl_NL.name` is populated. Existing translations
     * are preserved.
     *
     * @param array<string,array{name:string}> $expected name → nl translation
     */
    private function backfillByName( string $lookup_type, array $expected ): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_lookups";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return;

        foreach ( $expected as $name => $nl ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, translations FROM {$table} WHERE lookup_type = %s AND name = %s",
                $lookup_type, $name
            ) );

            foreach ( (array) $rows as $row ) {
                $translations = $this->decodeTranslations( (string) ( $row->translations ?? '' ) );
                $existing_nl  = isset( $translations['nl_NL']['name'] ) ? (string) $translations['nl_NL']['name'] : '';

                if ( $existing_nl !== '' ) continue; // Already has a translation; leave alone.

                if ( ! isset( $translations['nl_NL'] ) || ! is_array( $translations['nl_NL'] ) ) {
                    $translations['nl_NL'] = [];
                }
                $translations['nl_NL']['name'] = (string) $nl['name'];

                $wpdb->update(
                    $table,
                    [ 'translations' => (string) wp_json_encode( $translations ) ],
                    [ 'id' => (int) $row->id ]
                );
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeTranslations( string $raw ): array {
        if ( $raw === '' ) return [];
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }
};
