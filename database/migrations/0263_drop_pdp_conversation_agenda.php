<?php
/**
 * Migration 0263 — drop the retired `tt_pdp_conversations.agenda` column
 * (#3381, epic #3301).
 *
 * Migration 0257 moved every coach's pre-meeting text into the free-text
 * "Anything else to prepare?" prep answer, and 0256's question sets replaced
 * the box itself. The column has been kept since v4.118.0 as a rollback
 * courtesy — three releases, nothing has asked for it back, so it goes.
 *
 * `Activator.php` drops it from the CREATE TABLE in the same ship, so an
 * upgraded install and a fresh one end up with the same table. On a fresh
 * install the column is still created (migration 0031's dbDelta) and still
 * migrated (0257) before this drops it, which keeps the chain honest rather
 * than making 0257 a statement about a column that never existed.
 *
 * THE CHECK BEFORE THE DROP
 *
 * This is the only irreversible step in the retirement, so it refuses to
 * take it blind. Before the ALTER it counts the conversations that still
 * hold non-empty `agenda` text which does not appear verbatim in any prep
 * answer on the same conversation. Two known ways that count can be
 * non-zero, both from 0257's own skip branches:
 *
 *   - the academy archived the catch-all question before upgrading, so
 *     0257 had nowhere to file the text;
 *   - a coach had already answered the catch-all by the time 0257 ran, so
 *     it declined to overwrite what they had since written.
 *
 * In either case the text exists only in this column, and dropping it would
 * lose it. So the drop waits: the migration logs `migration.0263.blocked`
 * with the count and leaves the column alone. That install keeps a column a
 * fresh one does not have, which is the cheaper of the two wrongs — the
 * discrepancy is a ticket, the lost text is not recoverable.
 *
 * Idempotent: guarded on the table and the column existing, so a re-run and
 * a partially-migrated host both no-op. Forward-only — reverting would
 * restore an empty column with no writer.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;
use TT\Infrastructure\Logging\Logger;

return new class extends Migration {

    public function getName(): string {
        return '0263_drop_pdp_conversation_agenda';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $conversations = $p . 'tt_pdp_conversations';
        $answers       = $p . 'tt_pdp_prep_answers';

        foreach ( [ $conversations, $answers ] as $table ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
                return;
            }
        }

        if ( ! MigrationHelpers::columnExists( $conversations, 'agenda' ) ) {
            return; // Already dropped, or never created.
        }

        $unmoved = (int) $wpdb->get_var(
            "SELECT COUNT(*)
               FROM {$conversations} c
              WHERE c.agenda IS NOT NULL AND c.agenda <> ''
                AND NOT EXISTS (
                    SELECT 1 FROM {$answers} a
                     WHERE a.conversation_id = c.id
                       AND a.answer_text = c.agenda
                )"
        );

        if ( $unmoved > 0 ) {
            Logger::error( 'migration.0263.blocked', [
                'conversations_with_unmigrated_agenda' => $unmoved,
                'action'                               => 'column kept; see #3381',
            ] );
            return;
        }

        $this->exec( "ALTER TABLE {$conversations} DROP COLUMN agenda" );

        Logger::info( 'migration.0263.summary', [
            'agenda_column_dropped' => true,
        ] );
    }
};
