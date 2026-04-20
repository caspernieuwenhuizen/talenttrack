<?php
namespace TT\Shared\Validation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\CustomFields\CustomFieldsRepository;
use TT\Infrastructure\CustomFields\CustomValuesRepository;

/**
 * CustomFieldValidator — server-side validation for custom field values.
 *
 * v2.11.0 (Sprint 1H): expanded from 5 to 10 field types. Added
 * textarea, multi_select, url, email, phone. Added two conveniences on
 * top of the existing validate() contract:
 *
 *   - persistFromPost( entity_type, entity_id, $_POST ) — used by each
 *     module's save handler. Runs validate() against the entity's active
 *     fields, then upserts sanitized values via CustomValuesRepository.
 *     Errors accumulate but do not halt the save — the save handler is
 *     expected to still-have-saved the native entity, and any custom
 *     fields that validate successfully are persisted. Fields that fail
 *     validation are skipped (their previous stored value is retained).
 *
 *   - This matches the Sprint 1H design: wrong input on a custom field
 *     shouldn't throw away a successful native save.
 *
 * Input contract for validate(): raw { field_key => submitted_value }
 * map, plus the full list of active field definitions. multi_select
 * submits as an array (from `custom_fields[key][]`) or a comma-joined
 * string (from JSON/API callers).
 *
 * Output contract:
 *   - errors: array<int, array{code, message, details}> (empty on success)
 *   - sanitized: array<int, ?string> — { field_id => value_string_for_storage }
 *     where null means "delete any stored value".
 */
class CustomFieldValidator {

    /**
     * @param object[]            $fields    Field definitions (output of CustomFieldsRepository::getActive)
     * @param array<string,mixed> $submitted Raw payload: field_key => value
     * @param array<string,mixed> $multi_markers Raw $_POST['custom_fields_multi_marker'] or []
     * @return array{errors: array<int, array{code:string, message:string, details:array<string,mixed>}>, sanitized: array<int, ?string>, skipped: array<int, int>}
     */
    public function validate( array $fields, array $submitted, array $multi_markers = [] ): array {
        $errors    = [];
        $sanitized = [];
        $skipped   = []; // field IDs the caller should not touch (absent from form)

        foreach ( $fields as $field ) {
            $key   = (string) $field->field_key;
            $type  = (string) $field->field_type;
            $req   = ! empty( $field->is_required );
            $fid   = (int) $field->id;
            $label = (string) $field->label;

            // Determine "was this field on the submitted form?" — different
            // rules for different types. This matters because absence must
            // NOT wipe existing stored values.
            $submitted_here = $this->wasFieldOnForm( $type, $key, $submitted, $multi_markers );
            if ( ! $submitted_here ) {
                $skipped[] = $fid;
                continue;
            }

            $raw = array_key_exists( $key, $submitted ) ? $submitted[ $key ] : null;

            // Required check — interpretation depends on type.
            if ( $req ) {
                $missing = $this->isMissing( $type, $raw );
                if ( $missing ) {
                    $errors[] = [
                        'code'    => 'missing_custom_field',
                        'message' => sprintf(
                            /* translators: %s is the custom field label. */
                            __( 'The field "%s" is required.', 'talenttrack' ),
                            $label
                        ),
                        'details' => [ 'field_key' => $key ],
                    ];
                    continue;
                }
            }

            // Not required and empty → store null (delete any existing value).
            if ( $this->isEmpty( $type, $raw ) ) {
                $sanitized[ $fid ] = null;
                continue;
            }

            // Type-specific sanitisation & validation.
            $result = $this->sanitizeAndValidate( $field, $raw );
            if ( isset( $result['error'] ) ) {
                $errors[] = $result['error'];
                continue;
            }
            $sanitized[ $fid ] = $result['value'];
        }

        return [
            'errors'    => $errors,
            'sanitized' => $sanitized,
            'skipped'   => $skipped,
        ];
    }

    /**
     * Validate-and-persist. The canonical entry point for module save handlers.
     *
     * Pulls the entity's active fields, pulls POST['custom_fields'] and
     * POST['custom_fields_multi_marker'], validates, and upserts successful
     * values. Returns the error list so the caller can surface it via notice.
     * Fields that weren't on the form are preserved; empty fields are cleared.
     *
     * @return array<int, array{code:string, message:string, details:array<string,mixed>}>
     */
    public static function persistFromPost( string $entity_type, int $entity_id, array $post ): array {
        if ( $entity_id <= 0 ) return [];

        $fields_repo = new CustomFieldsRepository();
        $values_repo = new CustomValuesRepository();

        $fields = $fields_repo->getActive( $entity_type );
        if ( empty( $fields ) ) return [];

        $submitted = isset( $post['custom_fields'] ) && is_array( $post['custom_fields'] )
            ? $post['custom_fields']
            : [];
        $markers = isset( $post['custom_fields_multi_marker'] ) && is_array( $post['custom_fields_multi_marker'] )
            ? $post['custom_fields_multi_marker']
            : [];

        // wp_unslash everything once before sanitization.
        $submitted = wp_unslash( $submitted );
        $markers   = wp_unslash( $markers );

        $validator = new self();
        $result    = $validator->validate( $fields, $submitted, $markers );

        // Apply sanitized values. Skipped field_ids are left alone on disk.
        foreach ( $result['sanitized'] as $field_id => $value ) {
            $values_repo->upsert( $entity_type, $entity_id, (int) $field_id, $value );
        }

        return $result['errors'];
    }

    /* ═══════════════ Internals ═══════════════ */

    /**
     * Was this field on the submitted form? Absent → skip (don't wipe
     * stored value).
     *
     * @param array<string,mixed> $submitted
     * @param array<string,mixed> $multi_markers
     */
    private function wasFieldOnForm( string $type, string $key, array $submitted, array $multi_markers ): bool {
        switch ( $type ) {
            case CustomFieldsRepository::TYPE_MULTI_SELECT:
                // Marker is always emitted by the renderer, even when
                // nothing is ticked.
                return array_key_exists( $key, $multi_markers );
            case CustomFieldsRepository::TYPE_CHECKBOX:
                // Renderer emits a hidden "0" so the key is always present
                // when the field was rendered.
                return array_key_exists( $key, $submitted );
            default:
                return array_key_exists( $key, $submitted );
        }
    }

    /**
     * Is the submitted value considered "missing" for required purposes?
     *
     * @param mixed $raw
     */
    private function isMissing( string $type, $raw ): bool {
        if ( $type === CustomFieldsRepository::TYPE_CHECKBOX ) {
            return empty( $raw ) || $raw === '0' || $raw === 0 || $raw === false;
        }
        if ( $type === CustomFieldsRepository::TYPE_MULTI_SELECT ) {
            if ( ! is_array( $raw ) ) {
                $raw = strlen( (string) $raw ) > 0
                    ? array_filter( array_map( 'trim', explode( ',', (string) $raw ) ) )
                    : [];
            }
            return empty( $raw );
        }
        return ( $raw === null || $raw === '' || ( is_string( $raw ) && trim( $raw ) === '' ) );
    }

    /**
     * Is the submitted value considered empty for storage purposes?
     * (Empty here means "write null / delete the row".)
     *
     * @param mixed $raw
     */
    private function isEmpty( string $type, $raw ): bool {
        if ( $type === CustomFieldsRepository::TYPE_CHECKBOX ) {
            // Checkbox is NEVER "empty" for storage: explicit 0 or 1.
            return false;
        }
        if ( $type === CustomFieldsRepository::TYPE_MULTI_SELECT ) {
            if ( ! is_array( $raw ) ) return trim( (string) $raw ) === '';
            return empty( $raw );
        }
        return $raw === null || $raw === '' || ( is_string( $raw ) && trim( $raw ) === '' );
    }

    /**
     * Type-specific sanitize+validate. Returns either ['value' => string]
     * (success) or ['error' => array] (validation failure).
     *
     * @param mixed $raw
     * @return array{value?:string, error?:array{code:string, message:string, details:array<string,mixed>}}
     */
    private function sanitizeAndValidate( object $field, $raw ): array {
        $type  = (string) $field->field_type;
        $key   = (string) $field->field_key;
        $label = (string) $field->label;

        switch ( $type ) {
            case CustomFieldsRepository::TYPE_TEXT:
                return [ 'value' => sanitize_text_field( (string) $raw ) ];

            case CustomFieldsRepository::TYPE_TEXTAREA:
                return [ 'value' => sanitize_textarea_field( (string) $raw ) ];

            case CustomFieldsRepository::TYPE_NUMBER:
                if ( ! is_numeric( $raw ) ) {
                    return [ 'error' => [
                        'code'    => 'invalid_number',
                        'message' => sprintf(
                            /* translators: %s is the custom field label. */
                            __( 'The field "%s" must be a number.', 'talenttrack' ),
                            $label
                        ),
                        'details' => [ 'field_key' => $key ],
                    ] ];
                }
                return [ 'value' => (string) ( 0 + (float) $raw ) ];

            case CustomFieldsRepository::TYPE_DATE:
                $s = trim( (string) $raw );
                if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s )
                  || ! checkdate( (int) substr( $s, 5, 2 ), (int) substr( $s, 8, 2 ), (int) substr( $s, 0, 4 ) ) ) {
                    return [ 'error' => [
                        'code'    => 'invalid_date',
                        'message' => sprintf(
                            /* translators: %s is the custom field label. */
                            __( 'The field "%s" must be a valid date (YYYY-MM-DD).', 'talenttrack' ),
                            $label
                        ),
                        'details' => [ 'field_key' => $key ],
                    ] ];
                }
                return [ 'value' => $s ];

            case CustomFieldsRepository::TYPE_CHECKBOX:
                $truthy = in_array( $raw, [ 1, '1', true, 'on', 'true' ], true );
                return [ 'value' => $truthy ? '1' : '0' ];

            case CustomFieldsRepository::TYPE_SELECT:
                $allowed = array_map(
                    function ( $o ) { return $o['value']; },
                    CustomFieldsRepository::decodeOptions( $field->options ?? null )
                );
                if ( ! in_array( (string) $raw, $allowed, true ) ) {
                    return [ 'error' => [
                        'code'    => 'invalid_option',
                        'message' => sprintf(
                            /* translators: %s is the custom field label. */
                            __( 'The field "%s" has an invalid selection.', 'talenttrack' ),
                            $label
                        ),
                        'details' => [ 'field_key' => $key ],
                    ] ];
                }
                return [ 'value' => (string) $raw ];

            case CustomFieldsRepository::TYPE_MULTI_SELECT:
                $allowed = array_map(
                    function ( $o ) { return $o['value']; },
                    CustomFieldsRepository::decodeOptions( $field->options ?? null )
                );
                // Accept array or comma-joined string.
                if ( is_array( $raw ) ) {
                    $values = array_map( 'strval', $raw );
                } else {
                    $values = array_values( array_filter( array_map( 'trim', explode( ',', (string) $raw ) ) ) );
                }
                $clean = [];
                foreach ( $values as $v ) {
                    if ( $v === '' ) continue;
                    if ( ! in_array( $v, $allowed, true ) ) {
                        return [ 'error' => [
                            'code'    => 'invalid_option',
                            'message' => sprintf(
                                /* translators: %s is the custom field label. */
                                __( 'The field "%s" has an invalid selection.', 'talenttrack' ),
                                $label
                            ),
                            'details' => [ 'field_key' => $key ],
                        ] ];
                    }
                    $clean[] = $v;
                }
                // Dedup while preserving order.
                $clean = array_values( array_unique( $clean ) );
                return [ 'value' => implode( ',', $clean ) ];

            case CustomFieldsRepository::TYPE_URL:
                $url = esc_url_raw( (string) $raw );
                if ( $url === '' ) {
                    return [ 'error' => [
                        'code'    => 'invalid_url',
                        'message' => sprintf(
                            /* translators: %s is the custom field label. */
                            __( 'The field "%s" must be a valid URL.', 'talenttrack' ),
                            $label
                        ),
                        'details' => [ 'field_key' => $key ],
                    ] ];
                }
                return [ 'value' => $url ];

            case CustomFieldsRepository::TYPE_EMAIL:
                $email = sanitize_email( (string) $raw );
                if ( $email === '' || ! is_email( $email ) ) {
                    return [ 'error' => [
                        'code'    => 'invalid_email',
                        'message' => sprintf(
                            /* translators: %s is the custom field label. */
                            __( 'The field "%s" must be a valid email address.', 'talenttrack' ),
                            $label
                        ),
                        'details' => [ 'field_key' => $key ],
                    ] ];
                }
                return [ 'value' => $email ];

            case CustomFieldsRepository::TYPE_PHONE:
                $s = preg_replace( '/[^0-9+\-\s()]/', '', (string) $raw );
                $s = trim( (string) $s );
                if ( $s === '' ) {
                    return [ 'error' => [
                        'code'    => 'invalid_phone',
                        'message' => sprintf(
                            /* translators: %s is the custom field label. */
                            __( 'The field "%s" must be a valid phone number.', 'talenttrack' ),
                            $label
                        ),
                        'details' => [ 'field_key' => $key ],
                    ] ];
                }
                return [ 'value' => $s ];

            default:
                // Unknown type — skip. Never stores anything it doesn't recognise.
                return [ 'error' => [
                    'code'    => 'unknown_type',
                    'message' => sprintf( 'Unknown field type: %s', $type ),
                    'details' => [ 'field_key' => $key, 'field_type' => $type ],
                ] ];
        }
    }
}
