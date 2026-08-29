<?php
/**
 * Migration 0244 — `tt_share_views` (#3096).
 *
 * A coach shares a match analysis with the staff group and then hears
 * nothing back. The share block shows a URL, a copy button and a rotate
 * button, and no evidence at all that anyone opened it — so the coach
 * either chases people who have already read it, or assumes it landed when
 * it did not.
 *
 * ONE ROW PER VISITOR, NOT PER PAGE LOAD
 *
 * The row is upserted on `uk_visitor`. Three consequences, all wanted:
 * "seen by" is a `COUNT(*)` rather than a `COUNT(DISTINCT)`; the table
 * stays proportional to the number of people rather than to their
 * enthusiasm; and the 90-day purge deletes people who stopped visiting
 * instead of truncating the history of people who still do.
 *
 * WHAT IS NOT STORED
 *
 * `visitor_hash` is either a random per-analysis cookie id or, where a
 * cookie cannot be set, `hash_hmac` over ip|user-agent. Neither the IP nor
 * the user-agent is written anywhere in clear, and the hash is one-way and
 * salted per install. That is what makes the 90-day retention a matter of
 * data minimisation rather than housekeeping — the same posture and cadence
 * `Alerts\Cron\AlertRetentionCron` already sets.
 *
 * WHY A SECOND TABLE FOR THE TOTALS
 *
 * Purging a visitor row must not make the surface say "seen by 2" where it
 * said "seen by 5" yesterday — a number that walks backwards is worse than
 * no number. `tt_share_view_totals` holds what the purge folded away, one
 * row per subject, and the surface adds the two. It is a companion to this
 * table rather than a pair of columns on `tt_match_analysis` precisely
 * because `subject_type` is generic: putting the scalar on the analysis
 * would mean a second copy of the same idea when match prep wires in.
 *
 * Known imprecision, stated rather than hidden: a visitor purged after 90
 * days who comes back is counted twice. The alternative is keeping the
 * hash forever, which is the thing the retention rule exists to avoid.
 *
 * NOT IN THE CASCADE, ON PURPOSE
 *
 * Deleting an analysis leaves its view rows behind. `CascadeRegistry`'s
 * polymorphic form addresses a child through two parent hops, and this
 * table hangs off one — expressing it would mean either a redundant third
 * hop or a plain `cascade` entry that ignores `subject_type` and would take
 * a match prep's rows with it whenever an analysis id happened to collide.
 * What is left behind is a salted hash with nothing readable in it, no
 * longer reachable from anything, and deleted by the 90-day purge either
 * way. That is a better trade than a cascade rule that can delete the wrong
 * subject's rows.
 *
 * SHARED, NOT MATCH-ANALYSIS-SHAPED
 *
 * `subject_type` reserves `match_prep` (#2892) and `team_blueprint` (#0068),
 * which mint their share URLs from the identical HMAC construction. Only
 * the match analysis surface reads this table today; the other two are a
 * call site each, not a schema change.
 *
 * `club_id` per CLAUDE.md §4. Additive and idempotent.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0244_share_views';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_share_views (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            subject_type VARCHAR(32) NOT NULL,
            subject_id BIGINT UNSIGNED NOT NULL,
            visitor_hash CHAR(64) NOT NULL,
            first_seen_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL,
            open_count INT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY uk_visitor (club_id, subject_type, subject_id, visitor_hash),
            KEY idx_subject (club_id, subject_type, subject_id),
            KEY idx_last_seen (last_seen_at)
        ) {$charset};" );

        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_share_view_totals (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            subject_type VARCHAR(32) NOT NULL,
            subject_id BIGINT UNSIGNED NOT NULL,
            archived_unique INT UNSIGNED NOT NULL DEFAULT 0,
            archived_opens INT UNSIGNED NOT NULL DEFAULT 0,
            last_seen_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_subject (club_id, subject_type, subject_id)
        ) {$charset};" );
    }
};
