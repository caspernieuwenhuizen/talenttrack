<?php
/**
 * Migration 0231 — split the merged set-piece section by side
 * (#2748, epic #2704).
 *
 * The analysis shipped with one `set_pieces` section covering both our set
 * pieces and theirs. That cost two things: a note about our corners sat in
 * the same box as one about defending their free kicks, and match prep's
 * two set-piece goal boxes had to be joined into a single "Planned" line.
 * The vocabulary now carries `set_pieces_attack` and `set_pieces_defend`,
 * which restores an exact 1:1 mapping with the four goal boxes on the plan.
 *
 * ## Why existing rows land on the attacking side
 *
 * There is no way to tell from the text which side a merged note was about,
 * so this has to make a choice. Attacking is the better default: the
 * shipped surface listed our own set-piece goal first, and a coach writing
 * one line about set pieces is more often writing about their own routine
 * than about the opposition's. The alternative — dropping the rows, or
 * duplicating them onto both sides — either loses a coach's words or
 * invents a claim they did not make.
 *
 * In practice this moves almost nothing: the feature shipped in v4.96.0 the
 * same day, and the only rows that exist anywhere are demo data. The
 * migration is here so that "almost" is not doing any load-bearing work.
 *
 * Player items tagged with the merged key move the same way.
 *
 * Idempotent: re-running matches no rows the second time.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    private const LEGACY = 'set_pieces';
    private const TARGET = 'set_pieces_attack';

    public function getName(): string {
        return '0231_split_match_analysis_set_pieces';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $sections = "{$p}tt_match_analysis_sections";
        $players  = "{$p}tt_match_analysis_players";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sections ) ) === $sections ) {
            // A section row already on the target key would collide with
            // the unique (analysis_id, section_key) index, so those are left
            // alone: the analysis already has an attacking set-piece section
            // and the merged one has nowhere to go without overwriting it.
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$sections} s
                    SET s.section_key = %s
                  WHERE s.section_key = %s
                    AND NOT EXISTS (
                        SELECT 1 FROM ( SELECT * FROM {$sections} ) t
                         WHERE t.analysis_id = s.analysis_id
                           AND t.section_key = %s
                    )",
                self::TARGET,
                self::LEGACY,
                self::TARGET
            ) );
        }

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $players ) ) === $players ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$players} SET team_function = %s WHERE team_function = %s",
                self::TARGET,
                self::LEGACY
            ) );
        }
    }
};
