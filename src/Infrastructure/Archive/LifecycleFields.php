<?php
namespace TT\Infrastructure\Archive;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LifecycleFields (#2023) — the single source for the soft-delete
 * lifecycle timestamps emitted in every REST row / detail payload.
 *
 * Every archivable entity carries `archived_at` (active → archived) and,
 * since #2020, `trashed_at` (archived → recycle bin). Before this helper
 * each REST controller hand-wrote `'archived_at' => $row->archived_at`
 * in its own `serialize()` — which is exactly how the Bug-2 omission
 * crept into HolidaysRestController (it forgot the field, so the
 * FrontendListTable `show_if` hid both archived-row actions).
 *
 * Funnelling both fields through one helper means the new `trashed_at`
 * column can't drift per entity: every payload that spreads this array
 * exposes the same two keys, normalised to `?string` (null when the row
 * is not in that tier), so the JS `show_if` checks and the recycle-bin
 * UI read a stable contract everywhere.
 *
 * Usage in a controller's serialize():
 *
 *   return array_merge( [ ...row fields... ],
 *       LifecycleFields::forRow( $row ) );
 *
 * The source object may be a DB row (stdClass) or any object exposing
 * `archived_at` / `trashed_at`; missing properties read as null.
 */
final class LifecycleFields {

    /**
     * @param object|null $row  DB row / domain object with archived_at + trashed_at.
     * @return array{archived_at: ?string, trashed_at: ?string}
     */
    public static function forRow( $row ): array {
        return [
            'archived_at' => self::stamp( is_object( $row ) ? ( $row->archived_at ?? null ) : null ),
            'trashed_at'  => self::stamp( is_object( $row ) ? ( $row->trashed_at ?? null ) : null ),
        ];
    }

    /**
     * Build the pair from already-extracted values (for domain objects /
     * array sources that don't expose the raw row).
     *
     * @param mixed $archived_at
     * @param mixed $trashed_at
     * @return array{archived_at: ?string, trashed_at: ?string}
     */
    public static function fromValues( $archived_at, $trashed_at ): array {
        return [
            'archived_at' => self::stamp( $archived_at ),
            'trashed_at'  => self::stamp( $trashed_at ),
        ];
    }

    /** Normalise a lifecycle timestamp to a non-empty string or null. */
    private static function stamp( $value ): ?string {
        if ( $value === null || $value === '' ) return null;
        return (string) $value;
    }
}
