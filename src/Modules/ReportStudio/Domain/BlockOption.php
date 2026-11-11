<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BlockOption — one typed field in a block's options schema (#4568).
 *
 * Options are typed instead of free-form strings, so a stored report can
 * only ever hold a value the block declared. `measure` and `breakdown`
 * choices are grouped by the value of another option (`dependsOn`), which
 * is how "your own question" offers the measures of the fact picked first.
 */
final class BlockOption {

    public const TYPE_ENUM      = 'enum';
    public const TYPE_BOOL      = 'bool';
    public const TYPE_INT       = 'int';
    public const TYPE_MEASURE   = 'measure';
    public const TYPE_BREAKDOWN = 'breakdown';
    public const TYPE_TEXT      = 'text';
    public const TYPE_IDS       = 'ids';

    /**
     * @param array<string,string>|array<string,array<string,string>> $choices
     *        value => label; for a dependent option, parent value => (value => label)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly string $label,
        public readonly array $choices = [],
        public readonly bool $required = false,
        public readonly mixed $default = null,
        public readonly ?string $dependsOn = null,
        public readonly ?int $min = null,
        public readonly ?int $max = null
    ) {}

    /**
     * The JSON a client renders the field from.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array {
        $choices = [];
        if ( $this->dependsOn === null ) {
            foreach ( $this->choices as $value => $label ) {
                $choices[] = [ 'value' => (string) $value, 'label' => is_string( $label ) ? $label : '' ];
            }
        } else {
            foreach ( $this->choices as $parent => $set ) {
                $list = [];
                foreach ( is_array( $set ) ? $set : [] as $value => $label ) {
                    $list[] = [ 'value' => (string) $value, 'label' => $label ];
                }
                $choices[ (string) $parent ] = $list;
            }
        }
        return [
            'key'        => $this->key,
            'type'       => $this->type,
            'label'      => $this->label,
            'required'   => $this->required,
            'default'    => $this->default,
            'depends_on' => $this->dependsOn,
            'min'        => $this->min,
            'max'        => $this->max,
            'choices'    => $choices,
        ];
    }

    /**
     * Validate one raw value. Returns `[ true, value ]` or `[ false, null ]`.
     *
     * @param array<string,mixed> $siblings the other options, already validated
     * @return array{0:bool,1:mixed}
     */
    public function validate( mixed $raw, array $siblings ): array {
        if ( $raw === null || $raw === '' ) {
            return $this->required ? [ false, null ] : [ true, $this->default ];
        }
        switch ( $this->type ) {
            case self::TYPE_BOOL:
                if ( is_bool( $raw ) ) return [ true, $raw ];
                if ( in_array( $raw, [ 0, 1, '0', '1' ], true ) ) return [ true, (bool) (int) $raw ];
                return [ false, null ];
            case self::TYPE_INT:
                if ( ! is_int( $raw ) && ! ( is_string( $raw ) && preg_match( '/^-?\d+$/', $raw ) === 1 ) ) return [ false, null ];
                $n = (int) $raw;
                if ( ( $this->min !== null && $n < $this->min ) || ( $this->max !== null && $n > $this->max ) ) return [ false, null ];
                return [ true, $n ];
            case self::TYPE_TEXT:
                // Plain text about the document, never markup: tags are
                // dropped and `max` is the length in characters.
                if ( ! is_string( $raw ) ) return [ false, null ];
                $text = sanitize_textarea_field( $raw );
                if ( $this->max !== null && mb_strlen( $text ) > $this->max ) return [ false, null ];
                return $text === '' && $this->required ? [ false, null ] : [ true, $text ];
            case self::TYPE_IDS:
                // #4961 — record ids, as a list, a comma-separated string or
                // one id. An id that is no longer offered is kept: the
                // section's own option class reads it as its default, so a
                // stored report still opens after a test is archived.
                $items = is_array( $raw ) ? $raw : ( is_scalar( $raw ) ? explode( ',', (string) $raw ) : null );
                if ( $items === null ) return [ false, null ];
                $ids = [];
                foreach ( $items as $item ) {
                    $item = is_string( $item ) ? trim( $item ) : $item;
                    if ( $item === '' ) continue;
                    if ( ! is_int( $item ) && ! ( is_string( $item ) && preg_match( '/^\d+$/', $item ) === 1 ) ) return [ false, null ];
                    $id = (int) $item;
                    if ( $id <= 0 ) return [ false, null ];
                    $ids[ $id ] = $id;
                }
                if ( $this->max !== null && count( $ids ) > $this->max ) return [ false, null ];
                if ( $ids === [] ) return $this->required ? [ false, null ] : [ true, $this->default ];
                return [ true, array_values( $ids ) ];
            default:
                if ( ! is_string( $raw ) ) return [ false, null ];
                $set = $this->choices;
                if ( $this->dependsOn !== null ) {
                    $parent = (string) ( $siblings[ $this->dependsOn ] ?? '' );
                    $set    = isset( $this->choices[ $parent ] ) && is_array( $this->choices[ $parent ] ) ? $this->choices[ $parent ] : [];
                }
                return array_key_exists( $raw, $set ) ? [ true, $raw ] : [ false, null ];
        }
    }
}
