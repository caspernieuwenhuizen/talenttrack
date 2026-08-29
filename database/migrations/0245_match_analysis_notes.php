<?php
/**
 * Migration 0245 — `tt_match_analysis_notes` (#3091, epic #2704).
 *
 * A match analysis records judgement at two levels — a rating per phase and
 * a marker per player — and the notes underneath carry none. So a phase
 * rated "Wisselend" has four bullets beneath it with no way to tell which
 * two were the good half. The coach knew while typing and the surface threw
 * it away.
 *
 * WHY A TABLE RATHER THAN A `+ ` / `- ` PREFIX IN THE EXISTING TEXT
 *
 * The valence has to be countable in SQL. #2725 reads these rows across a
 * season, and a trend report that regexes free text is one nobody can
 * trust. A prefix convention would also corrupt silently the first time a
 * coach opened a bullet with a hyphen, which is a normal way to write.
 *
 * WHAT THE BACKFILL DOES, AND THE ONE JUDGEMENT IN IT
 *
 *   - `tt_match_analysis_sections.notes` splits on newline, one row per
 *     non-empty line, `valence = ''`. Phase bullets come back neutral
 *     because nothing in the old data says otherwise.
 *   - `tt_match_analysis_players.note` becomes one row whose valence is
 *     **derived from the player's marker**: stood_out → plus, below_par →
 *     minus, anything else → neutral.
 *
 * That second rule is a judgement, stated here so a reviewer can disagree
 * on purpose. It is not an invention: with one note per player, the marker
 * *was* the coach's verdict on that exact sentence. Dropping it to neutral
 * would discard real signal that is sitting right there. What it cannot
 * know is the case this feature exists for — a player who did one thing
 * well and one badly — because the old shape could not hold it.
 *
 * THE OLD COLUMNS STAY, UNWRITTEN, FOR ONE RELEASE
 *
 * `sections.notes` and `players.note` keep their content and stop being
 * written to. Same courtesy `SECTION_SET_PIECES_LEGACY` got in 0231: if the
 * split turns out wrong, the previous text is still there to roll back to.
 * A follow-up drops them once a release has passed.
 *
 * `club_id` + `uuid` per CLAUDE.md §4; `player_id` is the join key, never
 * `wp_user_id`. Additive and idempotent — the backfill is guarded on the
 * table being empty, so re-running it cannot duplicate a coach's bullets.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Logging\Logger;

return new class extends Migration {

    public function getName(): string {
        return '0245_match_analysis_notes';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_match_analysis_notes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid CHAR(36) NOT NULL,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            analysis_id BIGINT UNSIGNED NOT NULL,
            scope VARCHAR(16) NOT NULL,
            section_key VARCHAR(64) DEFAULT NULL,
            player_id BIGINT UNSIGNED DEFAULT NULL,
            valence VARCHAR(8) NOT NULL DEFAULT '',
            body VARCHAR(255) NOT NULL DEFAULT '',
            position TINYINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            KEY idx_analysis_scope (analysis_id, scope, position),
            KEY idx_club_player (club_id, player_id),
            KEY idx_club_section (club_id, section_key, valence)
        ) {$charset};" );

        $this->backfill();
    }

    /**
     * Guarded on an empty table: this migration is idempotent, and a
     * second run that re-split every section would double a coach's
     * bullets rather than fail loudly.
     */
    private function backfill(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $notes = $p . 'tt_match_analysis_notes';

        $existing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$notes}" );
        if ( $existing > 0 ) return;

        $sections = 0;
        $rows = $wpdb->get_results(
            "SELECT club_id, analysis_id, section_key, notes
               FROM {$p}tt_match_analysis_sections
              WHERE notes IS NOT NULL AND notes <> ''"
        );

        foreach ( (array) $rows as $row ) {
            $lines = preg_split( '/\r\n|\r|\n/', (string) $row->notes ) ?: [];
            $position = 0;
            foreach ( $lines as $line ) {
                $body = trim( (string) $line );
                if ( $body === '' ) continue;

                $wpdb->insert( $notes, [
                    'uuid'        => wp_generate_uuid4(),
                    'club_id'     => (int) $row->club_id,
                    'analysis_id' => (int) $row->analysis_id,
                    'scope'       => 'section',
                    'section_key' => (string) $row->section_key,
                    'player_id'   => null,
                    'valence'     => '',
                    'body'        => mb_substr( $body, 0, 255 ),
                    'position'    => $position,
                    'updated_at'  => current_time( 'mysql' ),
                ] );
                $position++;
                $sections++;
            }
        }

        $players = 0;
        $rows = $wpdb->get_results(
            "SELECT club_id, analysis_id, player_id, marker, note
               FROM {$p}tt_match_analysis_players
              WHERE note IS NOT NULL AND note <> ''"
        );

        foreach ( (array) $rows as $row ) {
            $body = trim( (string) $row->note );
            if ( $body === '' ) continue;

            // The marker was the verdict on this exact sentence. See the
            // file docblock — this is the one inference in the migration.
            $marker  = (string) ( $row->marker ?? '' );
            $valence = '';
            if ( $marker === 'stood_out' ) $valence = 'plus';
            if ( $marker === 'below_par' ) $valence = 'minus';

            $wpdb->insert( $notes, [
                'uuid'        => wp_generate_uuid4(),
                'club_id'     => (int) $row->club_id,
                'analysis_id' => (int) $row->analysis_id,
                'scope'       => 'player',
                'section_key' => null,
                'player_id'   => (int) $row->player_id,
                'valence'     => $valence,
                'body'        => mb_substr( $body, 0, 255 ),
                'position'    => 0,
                'updated_at'  => current_time( 'mysql' ),
            ] );
            $players++;
        }

        Logger::info( 'migration.0245.summary', [
            'section_notes' => $sections,
            'player_notes'  => $players,
        ] );
    }
};
