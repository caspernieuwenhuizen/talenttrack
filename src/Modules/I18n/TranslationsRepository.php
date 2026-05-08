<?php
namespace TT\Modules\I18n;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * TranslationsRepository (#0090 Phase 1) — read + write API on
 * `tt_translations`. Single chokepoint so caching, locale fallback
 * chains, and tenancy enforcement live in one place.
 *
 * **Read path** — `translate( $entity_type, $entity_id, $field, $locale, $fallback )`:
 *   1. Try cache (60s wp_cache, group `tt_translations`,
 *      versioned key per-(entity_type, entity_id) so save invalidates
 *      via O(1) version bump per the #0078 Phase 5 pattern).
 *   2. On cache miss, batch-fetch every (field, locale) row for the
 *      `(entity_type, entity_id)` triple — one query for all fields
 *      keeps render paths cheap when an entity has multiple fields.
 *   3. Locale fallback chain: `$locale → 'en_US' → $fallback`. Never
 *      returns empty string; the canonical column on the source
 *      table is the immovable backstop.
 *
 * **Write path** — `upsert( $entity_type, $entity_id, $field, $locale, $value, $user_id )`:
 *   - Validates registration via `TranslatableFieldRegistry`.
 *   - Single-row INSERT … ON DUPLICATE KEY UPDATE against the
 *     `(club_id, entity_type, entity_id, field, locale)` unique index.
 *   - Bumps the per-row version counter so cached reads in flight
 *     fall back to a fresh fetch.
 *
 * **Tenancy** — every read + write is scoped to `CurrentClub::id()`.
 *
 * **Cap-checking** lives in callers (REST controllers, admin pages).
 * The repository trusts that whoever called it has the right cap.
 */
final class TranslationsRepository {

    private const CACHE_GROUP   = 'tt_translations';
    private const CACHE_TTL     = 60; // seconds
    private const VERSION_PREFIX = 'tt_translations_v_';
    private const FALLBACK_LOCALE = 'en_US';

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_translations';
    }

    /**
     * Resolve a translation for the given entity field. Falls back
     * `$locale → en_US → $fallback`. Never returns empty string.
     */
    public function translate(
        string $entity_type,
        int $entity_id,
        string $field,
        string $locale,
        string $fallback
    ): string {
        if ( $entity_id <= 0 ) return $fallback;
        if ( ! TranslatableFieldRegistry::isRegistered( $entity_type, $field ) ) {
            return $fallback;
        }

        $rows = $this->batchFetch( $entity_type, $entity_id );

        // 1. Requested locale.
        $hit = $this->lookup( $rows, $field, $locale );
        if ( $hit !== null && $hit !== '' ) return $hit;

        // 2. en_US fallback (operator-authored canonical English,
        //    distinct from the canonical column when the operator
        //    rebrands a row's English without changing the seed).
        if ( $locale !== self::FALLBACK_LOCALE ) {
            $hit = $this->lookup( $rows, $field, self::FALLBACK_LOCALE );
            if ( $hit !== null && $hit !== '' ) return $hit;
        }

        // 3. Caller-supplied canonical column value.
        return $fallback;
    }

    /**
     * Insert or replace a translation row. Validates entity_type +
     * field against the registry. Bumps the per-row cache version on
     * success.
     */
    public function upsert(
        string $entity_type,
        int $entity_id,
        string $field,
        string $locale,
        string $value,
        int $user_id
    ): bool {
        if ( $entity_id <= 0 || $locale === '' ) return false;
        if ( ! TranslatableFieldRegistry::isRegistered( $entity_type, $field ) ) return false;

        global $wpdb;
        $table = $this->table();
        $club  = CurrentClub::id();
        $now   = current_time( 'mysql', true );

        // Use REPLACE INTO for the unique (club_id, entity_type,
        // entity_id, field, locale) tuple. wpdb->replace() is the
        // portable equivalent of REPLACE INTO across MySQL backends.
        $ok = $wpdb->replace(
            $table,
            [
                'club_id'     => $club,
                'entity_type' => $entity_type,
                'entity_id'   => $entity_id,
                'field'       => $field,
                'locale'      => $locale,
                'value'       => $value,
                'updated_by'  => $user_id > 0 ? $user_id : null,
                'updated_at'  => $now,
            ],
            [ '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s' ]
        );

        if ( $ok === false ) return false;

        $this->bumpVersion( $entity_type, $entity_id );
        return true;
    }

    /**
     * Remove a single translation row. Returns true on delete (or
     * row didn't exist — both are no-op-success).
     */
    public function delete(
        string $entity_type,
        int $entity_id,
        string $field,
        string $locale
    ): bool {
        if ( $entity_id <= 0 ) return false;
        global $wpdb;
        $wpdb->delete(
            $this->table(),
            [
                'club_id'     => CurrentClub::id(),
                'entity_type' => $entity_type,
                'entity_id'   => $entity_id,
                'field'       => $field,
                'locale'      => $locale,
            ],
            [ '%d', '%s', '%d', '%s', '%s' ]
        );
        $this->bumpVersion( $entity_type, $entity_id );
        return true;
    }

    /**
     * Cascade-delete every translation row for an entity. Called when
     * the source row itself is hard-deleted (e.g. lookup row, eval
     * category) so `tt_translations` does not retain orphans pointing
     * at vanished `entity_id`s.
     */
    public function deleteAllFor( string $entity_type, int $entity_id ): bool {
        if ( $entity_id <= 0 ) return false;
        global $wpdb;
        $wpdb->delete(
            $this->table(),
            [
                'club_id'     => CurrentClub::id(),
                'entity_type' => $entity_type,
                'entity_id'   => $entity_id,
            ],
            [ '%d', '%s', '%d' ]
        );
        $this->bumpVersion( $entity_type, $entity_id );
        return true;
    }

    /**
     * Bulk read — returns every translation for the given entity,
     * grouped by (field, locale). Used by the per-entity admin
     * Translations tab to populate the edit grid. Bypasses the
     * 60s cache.
     *
     * @return array<string, array<string, string>>  field => locale => value
     */
    public function allFor( string $entity_type, int $entity_id ): array {
        if ( $entity_id <= 0 ) return [];
        global $wpdb;
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT field, locale, value FROM ' . $this->table()
                . ' WHERE club_id = %d AND entity_type = %s AND entity_id = %d',
                CurrentClub::id(),
                $entity_type,
                $entity_id
            ),
            ARRAY_A
        );
        $out = [];
        foreach ( $rows as $r ) {
            $out[ (string) $r['field'] ][ (string) $r['locale'] ] = (string) $r['value'];
        }
        return $out;
    }

    /**
     * Bump the per-(entity_type, entity_id) cache version counter.
     * Versioned key pattern (mirrors #0078 Phase 5 CustomWidgetCache):
     * incrementing the version orphans every prior cached value
     * without scanning the cache. O(1) invalidation.
     */
    public function bumpVersion( string $entity_type, int $entity_id ): void {
        $opt = self::VERSION_PREFIX . $entity_type . '_' . $entity_id;
        $cur = (int) get_option( $opt, 0 );
        update_option( $opt, $cur + 1, false );
    }

    /**
     * @return array<int, array{field:string,locale:string,value:string}>
     */
    private function batchFetch( string $entity_type, int $entity_id ): array {
        $cache_key = $this->cacheKey( $entity_type, $entity_id );
        $cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
        if ( is_array( $cached ) ) return $cached;

        global $wpdb;
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT field, locale, value FROM ' . $this->table()
                . ' WHERE club_id = %d AND entity_type = %s AND entity_id = %d',
                CurrentClub::id(),
                $entity_type,
                $entity_id
            ),
            ARRAY_A
        );

        wp_cache_set( $cache_key, $rows, self::CACHE_GROUP, self::CACHE_TTL );
        return $rows;
    }

    /**
     * @param array<int, array{field:string,locale:string,value:string}> $rows
     */
    private function lookup( array $rows, string $field, string $locale ): ?string {
        foreach ( $rows as $r ) {
            if ( (string) ( $r['field'] ?? '' ) !== $field ) continue;
            if ( (string) ( $r['locale'] ?? '' ) !== $locale ) continue;
            return (string) ( $r['value'] ?? '' );
        }
        return null;
    }

    private function cacheKey( string $entity_type, int $entity_id ): string {
        $opt     = self::VERSION_PREFIX . $entity_type . '_' . $entity_id;
        $version = (int) get_option( $opt, 0 );
        return $entity_type . '_' . $entity_id . '_v' . $version;
    }
}
