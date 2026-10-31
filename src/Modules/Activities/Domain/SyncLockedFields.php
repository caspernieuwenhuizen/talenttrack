<?php
namespace TT\Modules\Activities\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SyncLockedFields — which field groups of a Spond-sourced activity a person
 * has edited in TalentTrack, so the Spond sync leaves them alone (#4485).
 *
 * Stored on `tt_activities.sync_locked_fields` as a JSON list of group keys;
 * NULL means nothing is locked. A group is added only when a person's save
 * changes one of its values; an unchanged save locks nothing.
 */
final class SyncLockedFields {

    public const SOURCE = 'spond';

    /** Group => the columns the sync stops writing once the group is locked. */
    public const GROUPS = [
        'title'    => [ 'title' ],
        'date'     => [ 'session_date' ],
        'times'    => [ 'start_time', 'end_time', 'kickoff_time', 'time_of_presence' ],
        'location' => [ 'location' ],
    ];

    /**
     * Group => the columns compared to decide a person changed it. The edit
     * path re-derives `kickoff_time` from `start_time` with its own type rule,
     * so comparing it would lock the times on a save that changed nothing.
     */
    private const COMPARED = [
        'title'    => [ 'title' ],
        'date'     => [ 'session_date' ],
        'times'    => [ 'start_time', 'end_time', 'time_of_presence' ],
        'location' => [ 'location' ],
    ];

    private const TIME_COLUMNS = [ 'start_time', 'end_time', 'kickoff_time', 'time_of_presence' ];

    public static function isGroup( string $group ): bool {
        return isset( self::GROUPS[ $group ] );
    }

    /**
     * @param mixed $raw The stored column value.
     * @return list<string> Known groups, unique, in GROUPS order.
     */
    public static function decode( $raw ): array {
        if ( ! is_string( $raw ) || $raw === '' ) return [];
        $list = json_decode( $raw, true );
        if ( ! is_array( $list ) ) return [];
        $list = array_map( 'strval', array_filter( $list, 'is_scalar' ) );
        return array_values( array_filter(
            array_keys( self::GROUPS ),
            static function ( string $g ) use ( $list ): bool { return in_array( $g, $list, true ); }
        ) );
    }

    /**
     * @param list<string> $groups
     * @return string|null JSON list, or null when nothing is locked.
     */
    public static function encode( array $groups ): ?string {
        $clean = self::decode( (string) wp_json_encode( array_values( $groups ) ) );
        return $clean === [] ? null : (string) wp_json_encode( $clean );
    }

    /**
     * The groups whose values `$data` changes against the stored row. Only
     * columns present in `$data` are compared.
     *
     * @param array<string,mixed> $stored Current row.
     * @param array<string,mixed> $data   Columns about to be written.
     * @return list<string>
     */
    public static function changedGroups( array $stored, array $data ): array {
        $changed = [];
        foreach ( self::COMPARED as $group => $columns ) {
            foreach ( $columns as $column ) {
                if ( ! array_key_exists( $column, $data ) ) continue;
                if ( self::normalise( $column, $stored[ $column ] ?? null ) !== self::normalise( $column, $data[ $column ] ) ) {
                    $changed[] = $group;
                    break;
                }
            }
        }
        return $changed;
    }

    /**
     * The lock value to write after a person's edit, or null when it stays
     * as it is: not a Spond activity, or no group gained a lock.
     *
     * @param array<string,mixed> $stored Current row, with `activity_source_key` and `sync_locked_fields`.
     * @param array<string,mixed> $data   Columns about to be written.
     */
    public static function afterEdit( array $stored, array $data ): ?string {
        if ( (string) ( $stored['activity_source_key'] ?? '' ) !== self::SOURCE ) return null;
        $locked  = self::decode( $stored['sync_locked_fields'] ?? null );
        $changed = array_diff( self::changedGroups( $stored, $data ), $locked );
        if ( $changed === [] ) return null;
        return self::encode( array_merge( $locked, $changed ) );
    }

    /**
     * Drop every locked group's columns from a sync update.
     *
     * @param array<string,mixed> $update
     * @param list<string>        $locked
     * @return array<string,mixed>
     */
    public static function stripLocked( array $update, array $locked ): array {
        foreach ( $locked as $group ) {
            foreach ( self::GROUPS[ $group ] ?? [] as $column ) {
                unset( $update[ $column ] );
            }
        }
        return $update;
    }

    /**
     * Columns the comparison reads from the stored row.
     *
     * @return list<string>
     */
    public static function comparedColumns(): array {
        $out = [];
        foreach ( self::COMPARED as $columns ) {
            foreach ( $columns as $column ) $out[] = $column;
        }
        return $out;
    }

    /** @param mixed $value */
    private static function normalise( string $column, $value ): string {
        $value = trim( (string) ( $value ?? '' ) );
        if ( in_array( $column, self::TIME_COLUMNS, true ) ) {
            return preg_match( '/^\d{2}:\d{2}$/', $value ) ? $value . ':00' : $value;
        }
        if ( $column === 'session_date' ) {
            return $value === '0000-00-00' ? '' : $value;
        }
        return sanitize_text_field( $value );
    }
}
