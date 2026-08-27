<?php
/**
 * Migration 0237 — `tt_match_prep.share_token_seed` (#2892).
 *
 * Match analysis got a signed, revocable staff share link in #2709. Match
 * preparation is the surface that needs one *before* the match rather than
 * after it: a head coach who has laid out the lineup, the per-phase goals
 * and the per-player attention notes could only get that sheet to an
 * assistant, an analyst or a keeper coach by printing it or exporting a
 * PDF — which is stale the moment a starter changes.
 *
 * `tt_match_prep` already carries `uuid CHAR(36)` with `uniq_uuid`
 * (migration 0118), so only the seed is missing.
 *
 * WHY A SEED SEPARATE FROM THE UUID
 *
 * The uuid supplies randomness against enumeration; the HMAC binds a URL to
 * the seed. Rotating the seed invalidates every URL previously issued for
 * that prep, which is the whole point: a shared match prep names minors and
 * says which of them is expected to start. Revocation is not a
 * nice-to-have, and it is impossible if the only secret is an identifier
 * the record cannot change.
 *
 * Nullable with no default, and deliberately NOT populated here. A prep
 * nobody has shared should not be carrying a secret; the seed is minted on
 * the first request for a link. The share route reads the seed as stored
 * and never initialises it, so a guessed uuid cannot mint the very secret
 * it would need.
 *
 * Additive and idempotent.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0237_match_prep_share_token';
    }

    public function up(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'tt_match_prep';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        MigrationHelpers::addColumnIfMissing(
            $table,
            'share_token_seed',
            'VARCHAR(64) DEFAULT NULL',
            'uuid'
        );
    }
};
