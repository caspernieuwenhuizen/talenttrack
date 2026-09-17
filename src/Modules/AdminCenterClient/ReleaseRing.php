<?php
namespace TT\Modules\AdminCenterClient;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ReleaseRing (#3493) — the release ring and version ceiling the control
 * plane assigns this install, and the gate that holds updates to it.
 *
 * Every install checks GitHub through the plugin update checker and, with
 * `UpdateHardening` forcing auto-updates, installs whatever is newest. At
 * several releases a week that is several chances a week to ship a
 * regression to every club at once. The Admin Center now answers each
 * phone-home with
 *
 *     "update_ring": { "ring": "stable", "max_version": "4.123.0" }
 *
 * and this class keeps any offer above `max_version` from reaching
 * WordPress — so it is neither shown nor auto-installed.
 *
 * ## Degrade open, always
 *
 * No record, a malformed block, or a record older than `STALE_SECONDS`
 * means **no ceiling**: updates are offered exactly as they were before
 * rings existed. A security fix must never wait for the control plane's
 * single server to be reachable. The ceiling is a cap the control plane
 * can place while it is talking to us, not a lock that outlives it.
 *
 * `max_version: null` is also no ceiling — the canary ring's normal state.
 */
final class ReleaseRing {

    public const OPTION = 'tt_release_ring';

    /** A ring record older than this no longer holds updates back. */
    public const STALE_SECONDS = 7 * 86400;

    /**
     * The ring block a verified response carries, or null when it carries
     * none this install can use.
     *
     * @param array<string,mixed> $decoded
     * @return array{ring:string, max_version:?string, fetched_at:int}|null
     */
    public static function fromResponse( array $decoded, ?int $now = null ): ?array {
        $block = $decoded['update_ring'] ?? null;
        if ( ! is_array( $block ) ) return null;

        $ring = is_string( $block['ring'] ?? null ) ? strtolower( trim( $block['ring'] ) ) : '';
        if ( $ring === '' || ! preg_match( '/^[a-z0-9_-]{1,20}$/', $ring ) ) return null;

        $max = $block['max_version'] ?? null;
        if ( $max !== null ) {
            if ( ! is_string( $max ) || ! self::isVersion( $max ) ) return null;
            $max = trim( $max );
        }

        return [ 'ring' => $ring, 'max_version' => $max, 'fetched_at' => $now ?? time() ];
    }

    /** @param array{ring:string, max_version:?string, fetched_at:int} $record */
    public static function store( array $record ): void {
        update_option( self::OPTION, (string) wp_json_encode( $record ), false );
    }

    /**
     * @return array{ring:string, max_version:?string, fetched_at:int}|null
     */
    public static function read(): ?array {
        $raw = get_option( self::OPTION, '' );
        if ( ! is_string( $raw ) || $raw === '' ) return null;
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) return null;

        $record = self::fromResponse( [ 'update_ring' => $data ], (int) ( $data['fetched_at'] ?? 0 ) );
        return $record !== null && $record['fetched_at'] > 0 ? $record : null;
    }

    /**
     * The version ceiling in force now, or null when updates are not held.
     */
    public static function ceiling( ?int $now = null ): ?string {
        $record = self::read();
        if ( $record === null || $record['max_version'] === null ) return null;
        if ( ( $now ?? time() ) - $record['fetched_at'] > self::STALE_SECONDS ) return null;
        return $record['max_version'];
    }

    /** May this install take `$version`? */
    public static function allows( string $version, ?int $now = null ): bool {
        $ceiling = self::ceiling( $now );
        if ( $ceiling === null || ! self::isVersion( $version ) ) return true;
        return version_compare( $version, $ceiling, '<=' );
    }

    public static function register(): void {
        // Late, after the update checker has injected its offer.
        add_filter( 'site_transient_update_plugins', [ self::class, 'filterUpdateTransient' ], 99 );
    }

    /**
     * Drop TalentTrack's offer from the update transient when it is above
     * the ceiling. Everything that decides to show or install an update —
     * the plugins screen, update-core, and the auto-updater
     * `UpdateHardening` enables — reads this transient, so removing the
     * offer here holds all of them back at once, before any auto-update
     * decision is made.
     *
     * @param mixed $transient
     * @return mixed
     */
    public static function filterUpdateTransient( $transient ) {
        if ( ! is_object( $transient ) || ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
            return $transient;
        }
        $basename = plugin_basename( TT_PLUGIN_FILE );
        $offer    = $transient->response[ $basename ] ?? null;
        if ( ! is_object( $offer ) || ! isset( $offer->new_version ) ) return $transient;

        if ( ! self::allows( (string) $offer->new_version ) ) {
            unset( $transient->response[ $basename ] );
        }
        return $transient;
    }

    private static function isVersion( string $version ): bool {
        return (bool) preg_match( '/^\d+(\.\d+){0,3}$/', trim( $version ) );
    }
}
