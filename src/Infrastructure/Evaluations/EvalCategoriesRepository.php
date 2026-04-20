<?php
namespace TT\Infrastructure\Evaluations;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * EvalCategoriesRepository — data access for tt_eval_categories.
 *
 * Sprint 1I (v2.12.0). Replaces the previous pattern of reading
 * main evaluation categories from tt_lookups (lookup_type='eval_category').
 *
 * The table supports a two-level hierarchy:
 *   - main categories: parent_id IS NULL  (e.g. Technical, Tactical)
 *   - subcategories:   parent_id = <main>  (e.g. Short pass under Technical)
 *
 * Deeper nesting is NOT supported. Code that treats a child-of-child as a
 * leaf would produce confusing UX; we don't allow it.
 */
class EvalCategoriesRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_eval_categories';
    }

    /**
     * v2.12.2 — translate a stored category label at display time.
     *
     * Seeded categories and subcategories store English source strings in
     * `tt_eval_categories.label` (e.g. "Short pass", "Technical"). Each
     * seeded string has a matching msgid entry in the .po files, so
     * __() translates them automatically at render time. Admin-added
     * labels that have no translation entry pass through unchanged —
     * __() returns its input when the translator has no match.
     *
     * Call this wherever a category label is rendered to the user:
     * admin tree, evaluation form, detail view, radar chart legends.
     * Never call it inside data writes or queries — the DB stores the
     * source string, not the translation.
     */
    public static function displayLabel( string $raw ): string {
        if ( $raw === '' ) return '';
        // The translator function is called with a dynamic argument
        // here. That's fine in our case because every seeded label is
        // registered as a msgid in talenttrack-nl_NL.po — the string
        // extraction tool (msgfmt_mini) picks them up from the seed
        // tables, not from __() call sites. Non-seeded labels simply
        // fall through unchanged.
        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
        return __( $raw, 'talenttrack' );
    }

    /* ═══════════════ Fetchers ═══════════════ */

    /** Main categories only. Ordered for rendering. */
    public function getMainCategories( bool $active_only = true ): array {
        global $wpdb;
        $t = $this->table();
        $where = 'parent_id IS NULL';
        if ( $active_only ) $where .= ' AND is_active = 1';
        return $wpdb->get_results(
            "SELECT * FROM {$t} WHERE {$where} ORDER BY display_order ASC, id ASC"
        );
    }

    /** Subcategories of a given parent. Ordered for rendering. */
    public function getChildren( int $parent_id, bool $active_only = true ): array {
        global $wpdb;
        $t = $this->table();
        $where = 'parent_id = %d';
        if ( $active_only ) $where .= ' AND is_active = 1';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$t} WHERE {$where} ORDER BY display_order ASC, id ASC",
            $parent_id
        ) );
    }

    public function get( int $id ): ?object {
        global $wpdb;
        $t = $this->table();
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t} WHERE id = %d LIMIT 1", $id
        ) );
        return $row ?: null;
    }

    public function getByKey( string $key ): ?object {
        global $wpdb;
        $t = $this->table();
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t} WHERE category_key = %s LIMIT 1", $key
        ) );
        return $row ?: null;
    }

    /**
     * Fetch every category (main + sub) as a flat list. Useful for building
     * parent→child maps in one query.
     */
    public function getAll( bool $active_only = true ): array {
        global $wpdb;
        $t = $this->table();
        $where = $active_only ? 'WHERE is_active = 1' : '';
        return $wpdb->get_results(
            "SELECT * FROM {$t} {$where} ORDER BY parent_id IS NULL DESC, parent_id ASC, display_order ASC, id ASC"
        );
    }

    /**
     * Tree representation: each main category object gets a `children`
     * array with its subcategory rows attached. Convenient for form
     * rendering and admin UIs.
     *
     * @return array<int, object>  Each object: main category + ->children = [subcat...]
     */
    public function getTree( bool $active_only = true ): array {
        $main = $this->getMainCategories( $active_only );
        $children_by_parent = [];

        // One query for all children rather than N.
        global $wpdb;
        $t = $this->table();
        $where = 'parent_id IS NOT NULL';
        if ( $active_only ) $where .= ' AND is_active = 1';
        $all_children = $wpdb->get_results(
            "SELECT * FROM {$t} WHERE {$where} ORDER BY display_order ASC, id ASC"
        );
        if ( is_array( $all_children ) ) {
            foreach ( $all_children as $c ) {
                $children_by_parent[ (int) $c->parent_id ][] = $c;
            }
        }

        foreach ( $main as $m ) {
            $m->children = $children_by_parent[ (int) $m->id ] ?? [];
        }
        return $main;
    }

    /* ═══════════════ Writes ═══════════════ */

    /**
     * Create a category (main or sub). If category_key is blank it's
     * derived from the label. Returns the new id.
     *
     * @param array<string,mixed> $data
     */
    public function create( array $data ): int {
        global $wpdb;
        $normalized = $this->normalize( $data, true );
        $wpdb->insert( $this->table(), $normalized );
        return (int) $wpdb->insert_id;
    }

    /**
     * Update a category. Locks category_key and parent_id after creation —
     * changing parent would invalidate any ratings referencing the old
     * path. (Admins who need to move subcategories can deactivate one and
     * create another.)
     */
    public function update( int $id, array $data ): bool {
        global $wpdb;
        $normalized = $this->normalize( $data, false );
        unset( $normalized['category_key'], $normalized['parent_id'] );
        return $wpdb->update( $this->table(), $normalized, [ 'id' => $id ] ) !== false;
    }

    public function setActive( int $id, bool $active ): bool {
        global $wpdb;
        return $wpdb->update( $this->table(), [ 'is_active' => $active ? 1 : 0 ], [ 'id' => $id ] ) !== false;
    }

    /**
     * Normalize + sanitize incoming data. On insert requires category_key
     * and label; on update only the provided keys are honored.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalize( array $data, bool $for_insert ): array {
        $out = [];
        if ( $for_insert ) {
            $label = isset( $data['label'] ) ? sanitize_text_field( (string) $data['label'] ) : '';
            // Accept either 'category_key' (new canonical name) or 'key'
            // (legacy call-site name) on create — keeps internal callers
            // that haven't been rewritten yet working. Stored column is
            // always category_key.
            $raw_key = '';
            if ( isset( $data['category_key'] ) && $data['category_key'] !== '' ) {
                $raw_key = (string) $data['category_key'];
            } elseif ( isset( $data['key'] ) && $data['key'] !== '' ) {
                $raw_key = (string) $data['key'];
            }
            $key = $raw_key !== '' ? sanitize_key( $raw_key ) : sanitize_key( $label );
            if ( $key === '' ) $key = 'cat_' . substr( md5( $label . microtime( true ) ), 0, 8 );
            $out['category_key'] = $key;
            $out['label']        = $label;

            if ( array_key_exists( 'parent_id', $data ) ) {
                $p = $data['parent_id'];
                $out['parent_id'] = ( $p === null || $p === '' || (int) $p === 0 ) ? null : (int) $p;
            }
        } else {
            if ( array_key_exists( 'label', $data ) ) {
                $out['label'] = sanitize_text_field( (string) $data['label'] );
            }
        }

        if ( array_key_exists( 'description', $data ) ) {
            $out['description'] = $data['description'] === null
                ? null
                : sanitize_textarea_field( (string) $data['description'] );
        }
        if ( array_key_exists( 'display_order', $data ) ) {
            $out['display_order'] = (int) $data['display_order'];
        }
        if ( array_key_exists( 'is_active', $data ) ) {
            $out['is_active'] = ! empty( $data['is_active'] ) ? 1 : 0;
        }
        if ( array_key_exists( 'is_system', $data ) ) {
            $out['is_system'] = ! empty( $data['is_system'] ) ? 1 : 0;
        }
        return $out;
    }

    /* ═══════════════ Legacy-shape shim ═══════════════ */

    /**
     * Returns main categories in the same shape as the pre-2.12 lookup
     * rows: objects with ->id, ->name, ->description, ->sort_order. This
     * lets any remaining caller that still thinks in lookup-shape keep
     * working without a structural rewrite.
     *
     * Prefer getMainCategories() or getTree() in new code.
     *
     * @return object[]
     */
    public function getMainCategoriesLegacyShape(): array {
        $main = $this->getMainCategories( true );
        $out = [];
        foreach ( $main as $m ) {
            $row = new \stdClass();
            $row->id          = (int) $m->id;
            $row->name        = (string) $m->label;
            $row->description = (string) ( $m->description ?? '' );
            $row->sort_order  = (int) $m->display_order;
            $out[] = $row;
        }
        return $out;
    }
}
