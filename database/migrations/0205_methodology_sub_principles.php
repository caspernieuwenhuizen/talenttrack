<?php
/**
 * Migration 0205 — Sub-principles as a first-class methodology entity
 * (#2369, epic #2316 follow-up).
 *
 * The PPT expresses each game phase as hoofdprincipes (already seeded as
 * `tt_principles`) plus sub-principes grouped per line (aanvallers /
 * middenvelders / verdedigers / algemeen). Those were previously folded
 * into a principle's `line_guidance` free text; this migration promotes
 * them to their own entity so they are queryable, editable and REST-
 * addressable like every other methodology building block.
 *
 * Creates `tt_methodology_sub_principles`, scoped to a methodology set
 * (MethodologyScope) and soft-linked to a phase via
 * `(phase_side, phase_number)` — optionally to a parent principle via
 * `principle_id`. Carries the SaaS tenancy scaffold (`club_id`, `uuid`).
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS. The JO13 content seed is
 * migration 0206.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0205_methodology_sub_principles';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        $this->exec( "CREATE TABLE IF NOT EXISTS {$p}tt_methodology_sub_principles (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id          INT UNSIGNED    NOT NULL DEFAULT 1,
            uuid             CHAR(36)        DEFAULT NULL,
            methodology_id   BIGINT UNSIGNED DEFAULT NULL,
            principle_id     BIGINT UNSIGNED DEFAULT NULL,
            phase_side       VARCHAR(16)     DEFAULT NULL,
            phase_number     TINYINT UNSIGNED DEFAULT NULL,
            line_key         VARCHAR(24)     DEFAULT NULL,
            title_json       TEXT            DEFAULT NULL,
            description_json LONGTEXT        DEFAULT NULL,
            sort_order       INT             NOT NULL DEFAULT 0,
            is_shipped       TINYINT(1)      NOT NULL DEFAULT 0,
            archived_at      DATETIME        DEFAULT NULL,
            created_at       DATETIME        DEFAULT CURRENT_TIMESTAMP,
            updated_at       DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_uuid (uuid),
            KEY idx_club_methodology (club_id, methodology_id),
            KEY idx_methodology_phase (methodology_id, phase_side, phase_number, line_key)
        ) {$charset};" );
    }
};
