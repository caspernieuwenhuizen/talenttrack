<?php
namespace TT\Modules\Prospects\Cron;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ProspectRetentionCron (#0081 child 1) — daily GDPR retention purge
 * for `tt_prospects`.
 *
 * Active-chain prospects are never auto-purged. Two purge conditions:
 *
 *  - **Stale-no-progress** — last workflow task on the chain completed
 *    more than `prospect_retention_days_no_progress` ago (default 90),
 *    OR no task was ever created AND `created_at` is older than the
 *    same threshold.
 *  - **Terminal-decline cool-off** — most recent task completed with a
 *    terminal-decline outcome AND more than
 *    `prospect_retention_days_terminal` (default 30) since that
 *    completion. Includes archived prospects whose `archived_at`
 *    matches.
 *
 * Thresholds are configurable per install via WP options
 * `tt_prospect_retention_days_no_progress` / `tt_prospect_retention_days_terminal`.
 *
 * The cron does the deletes in chunks of 50 per tick. For each row:
 *   - hard-delete the prospect row
 *   - cascade-delete any `tt_workflow_tasks` rows linked via
 *     entity-link `prospect_id` (when child 2 ships the templates)
 *   - write one audit row to `tt_authorization_changelog` with
 *     `change_type = 'gdpr_prospect_retention_purge'`
 *
 * Failure is silent and retried next tick — the cron is idempotent
 * (deleting an already-deleted row is a no-op).
 */
final class ProspectRetentionCron {

    public const HOOK = 'tt_prospects_retention_cron';

    public const DEFAULT_NO_PROGRESS_DAYS = 90;
    public const DEFAULT_TERMINAL_DAYS    = 30;
    private const BATCH_SIZE              = 50;

    public static function init(): void {
        add_action( self::HOOK,  [ self::class, 'run' ] );
        add_action( 'init',      [ self::class, 'ensureScheduled' ] );
    }

    public static function ensureScheduled(): void {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            // Stagger one hour after install so freshly-activated sites
            // don't fire the cron during the activation flow.
            wp_schedule_event( time() + 3600, 'daily', self::HOOK );
        }
    }

    public static function unschedule(): void {
        $ts = wp_next_scheduled( self::HOOK );
        if ( $ts ) wp_unschedule_event( $ts, self::HOOK );
    }

    public static function run(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $prospects_table = "{$p}tt_prospects";
        $changelog_table = "{$p}tt_authorization_changelog";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $prospects_table ) ) !== $prospects_table ) {
            return;
        }

        $no_progress_days = (int) get_option(
            'tt_prospect_retention_days_no_progress',
            self::DEFAULT_NO_PROGRESS_DAYS
        );
        $terminal_days = (int) get_option(
            'tt_prospect_retention_days_terminal',
            self::DEFAULT_TERMINAL_DAYS
        );
        if ( $no_progress_days <= 0 ) $no_progress_days = self::DEFAULT_NO_PROGRESS_DAYS;
        if ( $terminal_days    <= 0 ) $terminal_days    = self::DEFAULT_TERMINAL_DAYS;

        $no_progress_cutoff = gmdate( 'Y-m-d H:i:s', time() - $no_progress_days * DAY_IN_SECONDS );
        $terminal_cutoff    = gmdate( 'Y-m-d H:i:s', time() - $terminal_days    * DAY_IN_SECONDS );

        // --- Condition A: stale-no-progress -----------------------
        // Active-chain protection currently runs on `created_at` only
        // because the chain link (`tt_workflow_tasks.prospect_id`) ships
        // in #0081 child 2. Once that lands, this query gets a LEFT
        // JOIN onto `tt_workflow_tasks` keyed on `prospect_id` and the
        // HAVING clause excludes any prospect with a non-terminal task.
        // Promoted prospects (`promoted_to_player_id IS NOT NULL`) are
        // protected — promotion turns them into PII for an academy
        // player and PlayerDataMap registers the row under the player's
        // identity for #0073 erasure.
        $stale_sql = $wpdb->prepare(
            "SELECT id, club_id FROM {$prospects_table}
              WHERE created_at < %s
                AND promoted_to_player_id IS NULL
                AND archived_at IS NULL
              LIMIT %d",
            $no_progress_cutoff, self::BATCH_SIZE
        );

        $stale = $wpdb->get_results( $stale_sql );
        foreach ( (array) $stale as $row ) {
            self::purge(
                (int) $row->id,
                (int) $row->club_id,
                'stale_no_progress',
                $changelog_table,
                $prospects_table
            );
        }

        // --- Condition B: terminal-decline cool-off ----------------
        // Archived prospects whose archive_reason is a terminal-decline
        // outcome AND archived_at older than the terminal cutoff.
        $terminal_sql = $wpdb->prepare(
            "SELECT id, club_id FROM {$prospects_table}
              WHERE archived_at IS NOT NULL
                AND archived_at < %s
                AND archive_reason IN ('declined','parent_withdrew','no_show')
                AND promoted_to_player_id IS NULL
              LIMIT %d",
            $terminal_cutoff, self::BATCH_SIZE
        );
        $terminal = $wpdb->get_results( $terminal_sql );
        foreach ( (array) $terminal as $row ) {
            self::purge(
                (int) $row->id,
                (int) $row->club_id,
                'terminal_decline_cooldown',
                $changelog_table,
                $prospects_table
            );
        }
    }

    private static function purge(
        int $prospect_id,
        int $club_id,
        string $reason,
        string $changelog_table,
        string $prospects_table
    ): void {
        if ( $prospect_id <= 0 ) return;

        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->delete( $prospects_table, [ 'id' => $prospect_id, 'club_id' => $club_id ] );

        // Cascade workflow tasks linked via entity-link prospect_id.
        // The workflow tasks table doesn't have a `prospect_id` column
        // until #0081 child 2 lands; until then this cascade is a no-op.
        $tasks_table = "{$p}tt_workflow_tasks";
        $has_prospect_col = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM {$tasks_table} LIKE %s", 'prospect_id'
        ) );
        if ( $has_prospect_col === 'prospect_id' ) {
            $wpdb->delete( $tasks_table, [ 'prospect_id' => $prospect_id ] );
        }

        // Audit row. Reason embedded in `note` so the audit trail is
        // self-explanatory; the `change_type` is the stable machine key.
        $changelog_exists = $wpdb->get_var( $wpdb->prepare(
            'SHOW TABLES LIKE %s', $changelog_table
        ) ) === $changelog_table;
        if ( $changelog_exists ) {
            $wpdb->insert( $changelog_table, [
                'persona'       => 'system',
                'entity'        => 'prospects',
                'activity'      => 'create_delete',
                'scope_kind'    => 'global',
                'change_type'   => 'gdpr_prospect_retention_purge',
                'before_value'  => 'prospect#' . $prospect_id,
                'after_value'   => null,
                'actor_user_id' => 0,
                'note'          => 'auto-purge reason=' . $reason . ' club=' . $club_id,
                'created_at'    => current_time( 'mysql', true ),
            ] );
        }
    }
}
