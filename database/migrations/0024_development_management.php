<?php
/**
 * Migration 0024 — Development management staging tables (#0009).
 *
 * Two tables:
 *
 *  - `tt_dev_ideas` — every staged idea, in any state from "submitted"
 *    by a coach/admin/etc., through "refining", "ready-for-approval",
 *    "promoting", "promoted" (committed to GitHub), "in-progress",
 *    "done" or "rejected". The promotion fields capture the GitHub
 *    commit URL or the error if the API call failed.
 *
 *  - `tt_dev_tracks` — admin-curated development tracks (e.g. "Speed",
 *    "Game intelligence"). Ideas can optionally be tagged to a track
 *    for the player-development roadmap surface.
 *
 * Players + parents do NOT submit ideas in v1 (locked decision). The
 * cap `tt_submit_idea` is granted to every TT role except `tt_player`
 * and `tt_parent`. `tt_promote_idea` is administrator-only — the lead
 * developer's promote action is the gate that produces a GitHub commit.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0024_development_management';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}tt_dev_tracks" ) ) !== "{$p}tt_dev_tracks" ) {
            $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}tt_dev_tracks (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                description TEXT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_sort (sort_order)
            ) {$charset}" );
        }

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}tt_dev_ideas" ) ) !== "{$p}tt_dev_ideas" ) {
            $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}tt_dev_ideas (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                title VARCHAR(255) NOT NULL,
                body LONGTEXT NULL,
                slug VARCHAR(120) NOT NULL DEFAULT '',
                type VARCHAR(20) NOT NULL DEFAULT 'needs-triage',
                status VARCHAR(30) NOT NULL DEFAULT 'submitted',
                author_user_id BIGINT UNSIGNED NOT NULL,
                player_id BIGINT UNSIGNED NULL,
                team_id BIGINT UNSIGNED NULL,
                track_id BIGINT UNSIGNED NULL,
                rejection_note TEXT NULL,
                promoted_filename VARCHAR(255) NULL,
                promoted_commit_url VARCHAR(500) NULL,
                promotion_error TEXT NULL,
                spawned_goal_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                refined_at DATETIME NULL,
                refined_by BIGINT UNSIGNED NULL,
                promoted_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY idx_status (status),
                KEY idx_author (author_user_id),
                KEY idx_player (player_id),
                KEY idx_team (team_id),
                KEY idx_track (track_id),
                KEY idx_created (created_at)
            ) {$charset}" );
        }

        $this->seedCaps();
    }

    /**
     * Grant `tt_submit_idea` to every TT role except player/parent, and
     * `tt_promote_idea` to administrator only. Idempotent.
     */
    private function seedCaps(): void {
        $submit_roles = [
            'administrator',
            'tt_head_dev',
            'tt_club_admin',
            'tt_coach',
            'tt_scout',
            'tt_staff',
            'tt_readonly_observer',
        ];
        foreach ( $submit_roles as $slug ) {
            $role = get_role( $slug );
            if ( $role && ! $role->has_cap( 'tt_submit_idea' ) ) {
                $role->add_cap( 'tt_submit_idea' );
            }
        }

        $refine_roles = [ 'administrator', 'tt_head_dev', 'tt_club_admin' ];
        foreach ( $refine_roles as $slug ) {
            $role = get_role( $slug );
            if ( ! $role ) continue;
            if ( ! $role->has_cap( 'tt_refine_idea' ) ) {
                $role->add_cap( 'tt_refine_idea' );
            }
            if ( ! $role->has_cap( 'tt_view_dev_board' ) ) {
                $role->add_cap( 'tt_view_dev_board' );
            }
        }

        $admin = get_role( 'administrator' );
        if ( $admin && ! $admin->has_cap( 'tt_promote_idea' ) ) {
            $admin->add_cap( 'tt_promote_idea' );
        }
    }
};
