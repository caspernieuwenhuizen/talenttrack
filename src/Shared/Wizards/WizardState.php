<?php
namespace TT\Shared\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Wizard state — accumulated answers across steps.
 *
 * Keyed on `(user_id, wizard_slug)` so two wizards in flight don't
 * step on each other. Stored in WP transients with a short TTL (an
 * hour) — long enough for a real user, short enough that abandoned
 * sessions clean up themselves.
 *
 * Each successful step writes its values via `merge()`; the state
 * carries `_step` (current slug) and `_started_at` (timestamp) for
 * the framework's own bookkeeping.
 *
 * The Phase-4 analytics layer reads `_started_at` + `_completed_at`
 * + `_skipped_steps` from this same store, so the schema is stable.
 */
final class WizardState {

    private const TTL = HOUR_IN_SECONDS;

    public static function key( int $user_id, string $wizard_slug ): string {
        return 'tt_wizard_' . $user_id . '_' . $wizard_slug;
    }

    /**
     * @return array<string,mixed>
     *
     * #0072 — split-store load. Transient is the fast path within the
     * same session; if it's expired we fall through to the persistent
     * `tt_wizard_drafts` table so cross-device drafts resume.
     */
    public static function load( int $user_id, string $wizard_slug ): array {
        $row = get_transient( self::key( $user_id, $wizard_slug ) );
        if ( is_array( $row ) && ! empty( $row ) ) return $row;
        return self::loadFromTable( $user_id, $wizard_slug );
    }

    /**
     * @param array<string,mixed> $state
     *
     * #0072 — write-through to the persistent table so the draft
     * survives transient expiry / device switch.
     */
    public static function save( int $user_id, string $wizard_slug, array $state ): void {
        set_transient( self::key( $user_id, $wizard_slug ), $state, self::TTL );
        self::saveToTable( $user_id, $wizard_slug, $state );
    }

    public static function clear( int $user_id, string $wizard_slug ): void {
        delete_transient( self::key( $user_id, $wizard_slug ) );
        self::deleteFromTable( $user_id, $wizard_slug );
    }

    // -----------------------------------------------------------------
    // Persistent draft store (#0072) — `tt_wizard_drafts` table
    // -----------------------------------------------------------------

    /** @return array<string,mixed> */
    private static function loadFromTable( int $user_id, string $wizard_slug ): array {
        global $wpdb;
        $tbl = $wpdb->prefix . 'tt_wizard_drafts';
        if ( ! self::tableExists( $tbl ) ) return [];
        $json = $wpdb->get_var( $wpdb->prepare(
            "SELECT state_json FROM {$tbl} WHERE user_id = %d AND wizard_slug = %s LIMIT 1",
            $user_id, $wizard_slug
        ) );
        if ( ! is_string( $json ) || $json === '' ) return [];
        $decoded = json_decode( $json, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /** @param array<string,mixed> $state */
    private static function saveToTable( int $user_id, string $wizard_slug, array $state ): void {
        global $wpdb;
        $tbl = $wpdb->prefix . 'tt_wizard_drafts';
        if ( ! self::tableExists( $tbl ) ) return;
        $club_id = class_exists( '\TT\Infrastructure\Tenancy\CurrentClub' )
            ? (int) \TT\Infrastructure\Tenancy\CurrentClub::id()
            : 1;
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$tbl} ( user_id, club_id, wizard_slug, state_json, updated_at )
                  VALUES ( %d, %d, %s, %s, %s )
                  ON DUPLICATE KEY UPDATE state_json = VALUES(state_json), updated_at = VALUES(updated_at)",
            $user_id, $club_id, $wizard_slug,
            wp_json_encode( $state ),
            current_time( 'mysql', true )
        ) );
    }

    private static function deleteFromTable( int $user_id, string $wizard_slug ): void {
        global $wpdb;
        $tbl = $wpdb->prefix . 'tt_wizard_drafts';
        if ( ! self::tableExists( $tbl ) ) return;
        $wpdb->delete( $tbl, [ 'user_id' => $user_id, 'wizard_slug' => $wizard_slug ] );
    }

    private static function tableExists( string $table ): bool {
        static $cache = [];
        if ( isset( $cache[ $table ] ) ) return $cache[ $table ];
        global $wpdb;
        $cache[ $table ] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        return $cache[ $table ];
    }

    /**
     * #0072 — daily orphan cleanup. Deletes draft rows older than the
     * filtered TTL (default 14 days). Wired by `WizardDraftCleanupCron`.
     */
    public static function cleanupOldDrafts(): int {
        global $wpdb;
        $tbl = $wpdb->prefix . 'tt_wizard_drafts';
        if ( ! self::tableExists( $tbl ) ) return 0;
        $days = (int) apply_filters( 'tt_wizard_draft_ttl_days', 14 );
        if ( $days < 1 ) $days = 14;
        return (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$tbl} WHERE updated_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d DAY )",
            $days
        ) );
    }

    /**
     * #0072 — does a persistent draft row exist for this (user, wizard)?
     * The wizard view uses this to decide whether to render the
     * "Continue or start over?" banner on first hit.
     */
    public static function hasPersistentDraft( int $user_id, string $wizard_slug ): bool {
        global $wpdb;
        $tbl = $wpdb->prefix . 'tt_wizard_drafts';
        if ( ! self::tableExists( $tbl ) ) return false;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$tbl} WHERE user_id = %d AND wizard_slug = %s LIMIT 1",
            $user_id, $wizard_slug
        ) );
    }

    /**
     * #0072 — when did this user last save a draft for this wizard?
     * UTC datetime string or null.
     */
    public static function persistentDraftAge( int $user_id, string $wizard_slug ): ?string {
        global $wpdb;
        $tbl = $wpdb->prefix . 'tt_wizard_drafts';
        if ( ! self::tableExists( $tbl ) ) return null;
        $val = $wpdb->get_var( $wpdb->prepare(
            "SELECT updated_at FROM {$tbl} WHERE user_id = %d AND wizard_slug = %s LIMIT 1",
            $user_id, $wizard_slug
        ) );
        return is_string( $val ) && $val !== '' ? $val : null;
    }

    /**
     * @param array<string,mixed> $patch
     * @return array<string,mixed>
     */
    public static function merge( int $user_id, string $wizard_slug, array $patch ): array {
        $current = self::load( $user_id, $wizard_slug );
        $merged  = array_merge( $current, $patch );
        self::save( $user_id, $wizard_slug, $merged );
        return $merged;
    }

    public static function start( int $user_id, string $wizard_slug, string $first_step ): array {
        $state = [
            '_step'        => $first_step,
            '_started_at'  => time(),
            '_skipped'     => [],
        ];
        self::save( $user_id, $wizard_slug, $state );
        return $state;
    }

    public static function setStep( int $user_id, string $wizard_slug, string $step_slug ): array {
        return self::merge( $user_id, $wizard_slug, [ '_step' => $step_slug ] );
    }

    public static function recordSkip( int $user_id, string $wizard_slug, string $step_slug ): void {
        $state = self::load( $user_id, $wizard_slug );
        $skipped = (array) ( $state['_skipped'] ?? [] );
        if ( ! in_array( $step_slug, $skipped, true ) ) $skipped[] = $step_slug;
        $state['_skipped'] = $skipped;
        self::save( $user_id, $wizard_slug, $state );
    }

    /**
     * Push the step the user just left onto a history stack so Back
     * can pop it (#0063). Conditional branching (e.g. NewPlayer's
     * trial path) means we can't compute "previous step" purely from
     * the static step list — we have to track what was actually
     * visited.
     */
    public static function pushHistory( int $user_id, string $wizard_slug, string $step_slug ): void {
        $state = self::load( $user_id, $wizard_slug );
        $history = (array) ( $state['_history'] ?? [] );
        // Drop trailing duplicate so a Back→Next round-trip doesn't
        // accumulate the same slug twice.
        if ( end( $history ) !== $step_slug ) $history[] = $step_slug;
        $state['_history'] = $history;
        self::save( $user_id, $wizard_slug, $state );
    }

    /**
     * Pop the previous step off the history. Returns null when there
     * is no prior step (i.e. user is on step 1) so the framework can
     * gracefully suppress the Back button.
     */
    public static function popHistory( int $user_id, string $wizard_slug ): ?string {
        $state = self::load( $user_id, $wizard_slug );
        $history = (array) ( $state['_history'] ?? [] );
        $prev = array_pop( $history );
        $state['_history'] = $history;
        self::save( $user_id, $wizard_slug, $state );
        return is_string( $prev ) && $prev !== '' ? $prev : null;
    }

    public static function hasHistory( int $user_id, string $wizard_slug ): bool {
        $state = self::load( $user_id, $wizard_slug );
        return ! empty( $state['_history'] );
    }
}
