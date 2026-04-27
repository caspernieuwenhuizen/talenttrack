<?php
namespace TT\Infrastructure\CustomFields;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Frontend\CustomFieldRenderer;

/**
 * CustomFieldsSlot — form-injection point for custom fields.
 *
 * Sprint 1H (v2.11.0). Entity forms call:
 *
 *   CustomFieldsSlot::render( 'player', (int) $player_id, 'first_name' );
 *
 * and this class renders any active custom fields whose insert_after
 * matches that slug. Use CustomFieldsSlot::renderAppend($entity, $id)
 * at the bottom of the form for the catch-all group (insert_after IS NULL).
 *
 * Output is `<tr>` rows matching the WP-admin `.form-table` convention
 * used by all entity edit pages. Form field names follow the
 * `custom_fields[field_key]` convention enforced by the existing
 * Shared\Frontend\CustomFieldRenderer — POST data flows through the
 * existing CustomFieldValidator::persistFromPost path.
 *
 * Per-request caching: custom fields are fetched once per entity type;
 * values are fetched once per (entity_type, entity_id). A single form
 * render with several slot calls only hits the database twice.
 */
class CustomFieldsSlot {

    /** @var array<string, array<string, object[]>> */
    private static $fields_cache = [];

    /** @var array<string, array<int, string|null>> */
    private static $values_cache = [];

    /**
     * Render any active custom fields whose insert_after matches the
     * given slug. Outputs one <tr> per field.
     *
     * Idempotent and safe to call with a slug that has no matching
     * fields — emits nothing.
     */
    public static function render( string $entity_type, int $entity_id, string $slug ): void {
        $groups = self::loadFields( $entity_type );
        if ( empty( $groups[ $slug ] ) ) return;

        $values    = $entity_id > 0 ? self::loadValues( $entity_type, $entity_id ) : [];
        $submitted = self::submittedValues();

        foreach ( $groups[ $slug ] as $field ) {
            $val = self::resolveCurrentValue( $field, $values, $submitted );
            echo CustomFieldRenderer::inputRow( $field, $val ); /* output is escaped inside */
        }
    }

    /**
     * Convenience: render catch-all (insert_after IS NULL) fields at
     * the bottom of a form. Equivalent to render($entity_type, $entity_id, '__append__').
     */
    public static function renderAppend( string $entity_type, int $entity_id ): void {
        self::render( $entity_type, $entity_id, '__append__' );
    }

    /**
     * Detail-page read-only view: outputs an "Additional information"
     * section with one row per non-empty custom field value. Emits
     * nothing if the entity has no populated values.
     */
    public static function renderReadonly( string $entity_type, int $entity_id ): void {
        if ( $entity_id <= 0 ) return;

        $groups = self::loadFields( $entity_type );
        $values = self::loadValues( $entity_type, $entity_id );

        // Flatten groups back into a single ordered list.
        $ordered = [];
        foreach ( $groups as $slug => $list ) {
            if ( $slug === '__append__' ) continue;
            foreach ( $list as $f ) $ordered[] = $f;
        }
        if ( ! empty( $groups['__append__'] ) ) {
            foreach ( $groups['__append__'] as $f ) $ordered[] = $f;
        }
        if ( empty( $ordered ) ) return;

        $visible = [];
        foreach ( $ordered as $f ) {
            $fid = (int) $f->id;
            $raw = $values[ $fid ] ?? null;
            if ( $raw === null || $raw === '' ) continue;
            $visible[] = [ 'field' => $f, 'value' => $raw ];
        }
        if ( empty( $visible ) ) return;
        ?>
        <h2 style="margin-top:30px;"><?php esc_html_e( 'Additional information', 'talenttrack' ); ?></h2>
        <table class="widefat striped" style="max-width:700px;">
            <tbody>
                <?php foreach ( $visible as $row ) :
                    $f = $row['field'];
                    $v = $row['value']; ?>
                    <tr>
                        <th style="width:40%;"><?php echo esc_html( (string) $f->label ); ?></th>
                        <td><?php echo CustomFieldRenderer::display( $f, $v ); /* already escaped */ ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    // Internals

    private static function loadFields( string $entity_type ): array {
        if ( ! isset( self::$fields_cache[ $entity_type ] ) ) {
            $repo = new CustomFieldsRepository();
            self::$fields_cache[ $entity_type ] = $repo->getActiveGroupedByInsertAfter( $entity_type );
        }
        return self::$fields_cache[ $entity_type ];
    }

    private static function loadValues( string $entity_type, int $entity_id ): array {
        $key = $entity_type . ':' . $entity_id;
        if ( ! isset( self::$values_cache[ $key ] ) ) {
            $repo = new CustomValuesRepository();
            self::$values_cache[ $key ] = $repo->getByEntity( $entity_type, $entity_id );
        }
        return self::$values_cache[ $key ];
    }

    /**
     * Pull the current custom_fields POST array (if any). Used so a
     * failed save re-renders with what the user typed, not what's in
     * the database.
     *
     * @return array<string, mixed>
     */
    private static function submittedValues(): array {
        if ( empty( $_POST['custom_fields'] ) || ! is_array( $_POST['custom_fields'] ) ) return [];
        $out = [];
        foreach ( $_POST['custom_fields'] as $k => $v ) {
            $out[ (string) $k ] = $v;
        }
        return $out;
    }

    /**
     * Pick the "best" current value for a field at render time:
     * submitted value > stored value > null.
     *
     * @param array<string, mixed> $stored    field_id => raw stored value
     * @param array<string, mixed> $submitted field_key => raw submitted value
     * @return mixed
     */
    private static function resolveCurrentValue( $field, array $stored, array $submitted ) {
        $key = (string) $field->field_key;
        if ( array_key_exists( $key, $submitted ) ) {
            return $submitted[ $key ];
        }
        $fid = (int) $field->id;
        return $stored[ $fid ] ?? null;
    }
}
