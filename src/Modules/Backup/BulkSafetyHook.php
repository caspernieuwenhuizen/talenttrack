<?php
namespace TT\Modules\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Backup\Destinations\LocalDestination;

/**
 * BulkSafetyHook — before a destructive bulk operation that affects
 * more than $threshold rows, take an auto-safety backup so the user
 * can undo afterwards.
 *
 * Hooks at `tt_pre_bulk_destructive` (fired by BulkActionsHelper before
 * its switch statement runs). The threshold and "what counts as
 * destructive" are configurable; defaults are conservative — archive
 * and delete-permanently both qualify; restore does not (it's the
 * inverse direction).
 *
 * On a successful safety backup, the backup id and affected-rows
 * payload are stashed in a per-user transient. The next pageload's
 * admin-notice augmentation picks it up to render the Undo link.
 */
class BulkSafetyHook {

    public const TRANSIENT_PREFIX = 'tt_undo_bulk_';
    public const ACTIONS_THAT_TRIGGER = [ 'archive', 'delete_permanent' ];

    public static function init(): void {
        add_action( 'tt_pre_bulk_destructive', [ self::class, 'maybeSnapshot' ], 10, 3 );
    }

    /**
     * @param string  $entity action target ('player', 'team', etc.)
     * @param string  $action the bulk action ('archive', 'delete_permanent', 'restore')
     * @param int[]   $ids    ids targeted by the action
     */
    public static function maybeSnapshot( string $entity, string $action, array $ids ): void {
        if ( ! in_array( $action, self::ACTIONS_THAT_TRIGGER, true ) ) return;

        $threshold = (int) apply_filters( 'tt_backup_bulk_safety_threshold', 10, $entity, $action );
        if ( count( $ids ) <= $threshold ) return;

        $result = BackupRunner::run();
        if ( empty( $result['ok'] ) || empty( $result['filename'] ) ) return;

        $payload = [
            'backup_id'   => (string) $result['filename'],
            'entity'      => $entity,
            'action'      => $action,
            'ids'         => array_map( 'intval', $ids ),
            'created_at'  => time(),
        ];

        $user_id = get_current_user_id();
        set_transient( self::transientKey( $user_id ), $payload, 14 * DAY_IN_SECONDS );
    }

    /**
     * Read + clear the pending undo payload for the current user.
     *
     * @return array{backup_id:string, entity:string, action:string, ids:int[], created_at:int}|null
     */
    public static function popPending( int $user_id ): ?array {
        $key   = self::transientKey( $user_id );
        $value = get_transient( $key );
        if ( ! is_array( $value ) ) return null;
        delete_transient( $key );
        return $value;
    }

    /** Read the pending undo payload without consuming it. */
    public static function peekPending( int $user_id ): ?array {
        $key   = self::transientKey( $user_id );
        $value = get_transient( $key );
        return is_array( $value ) ? $value : null;
    }

    /** Backup file path for an undo payload (or empty if missing). */
    public static function pendingBackupPath( array $payload ): string {
        $local = new LocalDestination();
        $id    = isset( $payload['backup_id'] ) ? (string) $payload['backup_id'] : '';
        return $local->fetchLocalPath( $id );
    }

    private static function transientKey( int $user_id ): string {
        return self::TRANSIENT_PREFIX . max( 0, $user_id );
    }
}
