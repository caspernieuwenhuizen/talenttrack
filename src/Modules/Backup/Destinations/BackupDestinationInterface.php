<?php
namespace TT\Modules\Backup\Destinations;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BackupDestinationInterface — adapter contract for a backup target.
 *
 * Implementations:
 *   - LocalDestination   — writes to wp-content/uploads/talenttrack-backups/
 *   - EmailDestination   — sends via wp_mail() with the file attached
 *   - (deferred) S3Destination, DropboxDestination, etc.
 *
 * The interface is small on purpose: store + list + fetch + purge. Each
 * destination decides how the opaque `$id` works (filename, mail id,
 * remote object key); callers treat it as a stable handle.
 */
interface BackupDestinationInterface {

    /**
     * Identifier used in settings + UI (`local`, `email`, `s3`, ...).
     */
    public function key(): string;

    /**
     * Human-readable label (translated).
     */
    public function label(): string;

    /**
     * Whether this destination is currently enabled in settings + has
     * the configuration it needs to actually run.
     */
    public function isEnabled(): bool;

    /**
     * Persist the backup file to the destination.
     *
     * @param string              $backup_path Absolute path to the .json.gz on local disk
     * @param array<string,mixed> $metadata    Backup metadata (created_at, preset, plugin_version, sizes, etc.)
     */
    public function store( string $backup_path, array $metadata ): StoreResult;

    /**
     * List backups stored at this destination, newest first.
     *
     * @return array<int, array{
     *   id:string,
     *   filename:string,
     *   size:int,
     *   created_at:string,
     *   preset:string,
     *   meta?:array<string,mixed>
     * }>
     */
    public function listBackups(): array;

    /**
     * Resolve a destination-scoped id to a local file path. Returns
     * empty string if the destination cannot produce a local copy
     * (e.g. an email-only destination after the email was sent).
     */
    public function fetchLocalPath( string $id ): string;

    /**
     * Remove a backup from the destination. Returns true on success or
     * if the backup didn't exist (idempotent).
     */
    public function purge( string $id ): bool;
}
