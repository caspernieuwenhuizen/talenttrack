<?php
/**
 * Migration 0227 — retention holds on media attachments (#2666, epic #2589).
 *
 * Media retention expires an **attachment**, not an item. A photo tagged
 * to three players is not one player's to expire (epic decision D5, and
 * R2 on #2666): when one of them has been gone long enough, their link
 * goes and the photo stays for the others and for the training it came
 * from. The item is only deleted when nothing links it any more, which
 * is the rule `MediaLinksRepository::unlink()` already enforces.
 *
 * So the hold lives on the link. Three columns, all null until somebody
 * decides to keep something:
 *
 *   retention_hold_at      when the decision was made
 *   retention_hold_reason  why — a safeguarding matter, a dispute, an
 *                          appeal still open
 *   retention_hold_by      who decided
 *
 * A held link stops appearing in the review queue and is never proposed
 * for removal again. That is the legal-hold case, and it is the reason
 * the queue is a queue rather than a scheduled delete: an academy needs
 * somewhere to say "not this one, and here is why".
 *
 * No retention *date* is stored. The expiry is derived from the player's
 * dated departure event and the club's configured period, so changing
 * the period re-evaluates everything and a player who returns simply
 * falls out of the queue again. Materialising it would mean a stale
 * column the day anybody edits either input.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0227_media_retention_holds';
    }

    public function up(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'tt_media_links';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        MigrationHelpers::addColumnIfMissing( $table, 'retention_hold_at', 'DATETIME DEFAULT NULL' );
        MigrationHelpers::addColumnIfMissing( $table, 'retention_hold_reason', 'VARCHAR(255) DEFAULT NULL' );
        MigrationHelpers::addColumnIfMissing( $table, 'retention_hold_by', 'BIGINT UNSIGNED DEFAULT NULL' );
    }
};
