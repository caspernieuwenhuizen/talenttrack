<?php
namespace TT\Infrastructure\CustomFields;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CustomFieldsRepository — typed data access for tt_custom_fields.
 *
 * The plugin is polymorphic across entity types. Most calls accept an
 * $entity_type (default 'player'); passing different values targets
 * different sets of fields.
 *
 * Stateless. Instantiate where needed; no container binding required.
 */
class CustomFieldsRepository {

    public const ENTITY_PLAYER = 'player';

    public const TYPE_TEXT     = 'text';
    public const TYPE_NUMBER   = 'number';
    public const TYPE_SELECT   = 'select';
    public const TYPE_CHECKBOX = 'checkbox';
    public const TYPE_DATE     = 'date';

    /**
     * @return string[] Allowed field_type values.
     */
    public static function allowedTypes(): array {
        return [
            self::TYPE_TEXT,
            self::TYPE_NUMBER,
            self::TYPE_SELECT,
            self::TYPE_CHECKBOX,
            self::TYPE_DATE,
        ];
    }

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_custom_fields';
    }

    /**
     * @return object[] Active fields for an entity, sorted.
     */
    public function getActive( string $entity_type = self::ENTITY_PLAYER ): array {
        global $wpdb;
        $t = $this->table();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$t} WHERE entity_type = %s AND is_active = 1 ORDER BY sort_order ASC, id ASC",
            $entity_type
        ) );
    }

    /**
     * @return object[] All fields (including inactive) for an entity.
     */
    public function getAll( string $entity_type = self::ENTITY_PLAYER ): array {
        global $wpdb;
        $t = $this->table();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$t} WHERE entity_type = %s ORDER BY sort_order ASC, id ASC",
            $entity_type
        ) );
    }

    public function get( int $id ): ?object {
        global $wpdb;
        $t = $this->table();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
        return $row ?: null;
    }

    public function getByKey( string $entity_type, string $field_key ): ?object {
        global $wpdb;
        $t = $this->table();
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t} WHERE entity_type = %s AND field_key = %s",
            $entity_type, $field_key
        ) );
        return $row ?: null;
    }

    /**
     * Create a new field.
     *
     * @param array<string,mixed> $data
     */
    public function create( array $data ): int {
        global $wpdb;
        $wpdb->insert( $this->table(), $this->normalise( $data, true ) );
        return (int) $wpdb->insert_id;
    }

    /**
     * Update an existing field.
     *
     * @param array<string,mixed> $data
     */
    public function update( int $id, array $data ): bool {
        global $wpdb;
        $normalised = $this->normalise( $data, false );
        // field_key is locked after creation — see the Admin page — so strip it here defensively.
        unset( $normalised['field_key'] );
        unset( $normalised['entity_type'] );
        return $wpdb->update( $this->table(), $normalised, [ 'id' => $id ] ) !== false;
    }

    public function setActive( int $id, bool $active ): bool {
        global $wpdb;
        return $wpdb->update( $this->table(), [ 'is_active' => $active ? 1 : 0 ], [ 'id' => $id ] ) !== false;
    }

    /**
     * Bulk update of sort_order. $pairs is [ field_id => new_order, ... ].
     *
     * @param array<int,int> $pairs
     */
    public function reorder( array $pairs ): void {
        global $wpdb;
        $t = $this->table();
        foreach ( $pairs as $id => $order ) {
            $wpdb->update( $t, [ 'sort_order' => (int) $order ], [ 'id' => (int) $id ] );
        }
    }

    /**
     * Generate a unique field_key from a label. Uses sanitize_key.
     * If a collision exists, appends _2, _3, ...
     */
    public function generateUniqueKey( string $entity_type, string $label ): string {
        $base = sanitize_key( $label );
        if ( $base === '' ) {
            $base = 'field';
        }
        $candidate = $base;
        $n = 2;
        while ( $this->getByKey( $entity_type, $candidate ) !== null ) {
            $candidate = $base . '_' . $n;
            $n++;
        }
        return $candidate;
    }

    /**
     * Normalise payload for insert/update: coerces types, JSON-encodes options.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalise( array $data, bool $for_insert ): array {
        $out = [];
        if ( $for_insert ) {
            $out['entity_type'] = isset( $data['entity_type'] ) ? (string) $data['entity_type'] : self::ENTITY_PLAYER;
            $out['field_key']   = isset( $data['field_key'] ) ? sanitize_key( (string) $data['field_key'] ) : '';
        }
        if ( array_key_exists( 'label', $data ) )       $out['label']       = sanitize_text_field( (string) $data['label'] );
        if ( array_key_exists( 'field_type', $data ) )  $out['field_type']  = (string) $data['field_type'];
        if ( array_key_exists( 'is_required', $data ) ) $out['is_required'] = ! empty( $data['is_required'] ) ? 1 : 0;
        if ( array_key_exists( 'sort_order', $data ) )  $out['sort_order']  = (int) $data['sort_order'];
        if ( array_key_exists( 'is_active', $data ) )   $out['is_active']   = ! empty( $data['is_active'] ) ? 1 : 0;

        if ( array_key_exists( 'options', $data ) ) {
            $opts = $data['options'];
            if ( is_array( $opts ) ) {
                $out['options'] = wp_json_encode( $opts );
            } elseif ( is_string( $opts ) && $opts !== '' ) {
                // Already-encoded JSON is accepted as-is after a sanity decode.
                $decoded = json_decode( $opts, true );
                $out['options'] = is_array( $decoded ) ? wp_json_encode( $decoded ) : null;
            } else {
                $out['options'] = null;
            }
        }

        return $out;
    }

    /**
     * Decode the options column into an array of {value,label} pairs.
     *
     * @return array<int, array{value:string,label:string}>
     */
    public static function decodeOptions( ?string $raw ): array {
        if ( $raw === null || $raw === '' ) return [];
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) return [];
        $out = [];
        foreach ( $decoded as $item ) {
            if ( ! is_array( $item ) ) continue;
            $v = isset( $item['value'] ) ? (string) $item['value'] : '';
            $l = isset( $item['label'] ) ? (string) $item['label'] : $v;
            if ( $v === '' ) continue;
            $out[] = [ 'value' => $v, 'label' => $l ];
        }
        return $out;
    }
}
