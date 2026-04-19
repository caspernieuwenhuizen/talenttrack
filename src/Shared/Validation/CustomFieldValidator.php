<?php
namespace TT\Shared\Validation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\CustomFields\CustomFieldsRepository;

/**
 * CustomFieldValidator — server-side validation for custom field values.
 *
 * Called from two places:
 *   - Player admin/frontend save handler (halts save on error)
 *   - REST API player create/update (returns 422 via RestResponse::errors)
 *
 * Input contract: raw { field_key => submitted_value } map, plus the full
 * list of active field definitions for the entity type.
 *
 * Output contract:
 *   - errors: array<int, array{code, message, details}>  (empty on success)
 *   - sanitized: array<int, ?string>   ({ field_id => value_string_for_storage })
 *     where null means "delete any stored value".
 *
 * The sanitized map is what the save handler passes to CustomValuesRepository::upsert.
 */
class CustomFieldValidator {

    /**
     * @param object[]               $fields  Field definitions (output of CustomFieldsRepository::getActive)
     * @param array<string,mixed>    $submitted  Raw payload: field_key => value
     * @return array{errors: array<int, array{code:string, message:string, details:array<string,mixed>}>, sanitized: array<int, ?string>}
     */
    public function validate( array $fields, array $submitted ): array {
        $errors    = [];
        $sanitized = [];

        foreach ( $fields as $field ) {
            $key   = (string) $field->field_key;
            $type  = (string) $field->field_type;
            $req   = ! empty( $field->is_required );
            $fid   = (int) $field->id;
            $label = (string) $field->label;

            $raw = array_key_exists( $key, $submitted ) ? $submitted[ $key ] : null;

            // Required check — interpretation depends on type.
            if ( $req ) {
                $missing = false;
                if ( $type === CustomFieldsRepository::TYPE_CHECKBOX ) {
                    // "must be checked" — empty, '0', false, or missing all fail.
                    $missing = empty( $raw ) || $raw === '0' || $raw === 0 || $raw === false;
                } else {
                    $missing = ( $raw === null || $raw === '' || ( is_string( $raw ) && trim( $raw ) === '' ) );
                }
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
            if ( $raw === null || $raw === '' ) {
                $sanitized[ $fid ] = null;
                continue;
            }

            // Type-specific sanitisation & validation.
            switch ( $type ) {
                case CustomFieldsRepository::TYPE_TEXT:
                    $sanitized[ $fid ] = sanitize_text_field( (string) $raw );
                    break;

                case CustomFieldsRepository::TYPE_NUMBER:
                    if ( ! is_numeric( $raw ) ) {
                        $errors[] = [
                            'code'    => 'invalid_number',
                            'message' => sprintf(
                                /* translators: %s is the custom field label. */
                                __( 'The field "%s" must be a number.', 'talenttrack' ),
                                $label
                            ),
                            'details' => [ 'field_key' => $key ],
                        ];
                        continue 2;
                    }
                    $sanitized[ $fid ] = (string) $raw; // store canonical string
                    break;

                case CustomFieldsRepository::TYPE_SELECT:
                    $allowed = array_map(
                        function ( $o ) { return $o['value']; },
                        CustomFieldsRepository::decodeOptions( $field->options ?? null )
                    );
                    if ( ! in_array( (string) $raw, $allowed, true ) ) {
                        $errors[] = [
                            'code'    => 'invalid_option',
                            'message' => sprintf(
                                /* translators: %s is the custom field label. */
                                __( 'The field "%s" has an invalid selection.', 'talenttrack' ),
                                $label
                            ),
                            'details' => [ 'field_key' => $key ],
                        ];
                        continue 2;
                    }
                    $sanitized[ $fid ] = (string) $raw;
                    break;

                case CustomFieldsRepository::TYPE_CHECKBOX:
                    // Accepts truthy (1, "1", true, "on") or falsy (0, "0", false, ""); stored as '0'/'1'.
                    $truthy = in_array( $raw, [ 1, '1', true, 'on', 'true' ], true );
                    $sanitized[ $fid ] = $truthy ? '1' : '0';
                    break;

                case CustomFieldsRepository::TYPE_DATE:
                    $s = trim( (string) $raw );
                    // Accept YYYY-MM-DD (HTML5 <input type="date">).
                    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s ) ) {
                        $errors[] = [
                            'code'    => 'invalid_date',
                            'message' => sprintf(
                                /* translators: %s is the custom field label. */
                                __( 'The field "%s" must be a valid date (YYYY-MM-DD).', 'talenttrack' ),
                                $label
                            ),
                            'details' => [ 'field_key' => $key ],
                        ];
                        continue 2;
                    }
                    $parts = explode( '-', $s );
                    if ( ! checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ) {
                        $errors[] = [
                            'code'    => 'invalid_date',
                            'message' => sprintf(
                                /* translators: %s is the custom field label. */
                                __( 'The field "%s" must be a valid date (YYYY-MM-DD).', 'talenttrack' ),
                                $label
                            ),
                            'details' => [ 'field_key' => $key ],
                        ];
                        continue 2;
                    }
                    $sanitized[ $fid ] = $s;
                    break;

                default:
                    // Unknown type — defensive: skip. Validator never stores anything it doesn't recognise.
                    break;
            }
        }

        return [
            'errors'    => $errors,
            'sanitized' => $sanitized,
        ];
    }
}
