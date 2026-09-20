<?php
namespace TT\Infrastructure\Journey;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * EventTypeDefinition — taxonomy entry for one journey event type.
 *
 * Loaded from `tt_lookups[lookup_type='journey_event_type']` rows. The
 * `payload_schema` is hardcoded per type because callers populate
 * payload fields directly; the lookup row carries the rendering meta
 * (icon / color / severity / default_visibility / group).
 */
final class EventTypeDefinition {

    public const SEVERITY_INFO      = 'info';
    public const SEVERITY_WARNING   = 'warning';
    public const SEVERITY_MILESTONE = 'milestone';

    public const VISIBILITY_PUBLIC          = 'public';
    public const VISIBILITY_COACHING_STAFF  = 'coaching_staff';
    public const VISIBILITY_MEDICAL         = 'medical';
    public const VISIBILITY_SAFEGUARDING    = 'safeguarding';

    /**
     * @param array<string, string> $payloadSchema field => 'int' | 'float' | 'string' | 'bool',
     *   optionally prefixed with `?` for a field a caller may leave out.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $severity,
        public readonly string $defaultVisibility,
        public readonly string $group,
        public readonly string $icon,
        public readonly string $color,
        public readonly array $payloadSchema = []
    ) {}

    /**
     * Loose validation: each declared field must (a) be present, (b) match
     * the declared scalar type. Unknown fields are tolerated. Return true
     * when valid, false otherwise.
     *
     * A type prefixed with `?` marks the field optional: leaving it out is
     * valid, and so is a null. #3767 — an evaluation with nothing rated on
     * it has no overall score, and the payload said `0` because the field
     * was mandatory. A reader cannot tell a real zero from a placeholder,
     * so an absent key is the honest answer and the schema has to allow it.
     *
     * @param array<string, mixed> $payload
     */
    public function validatePayload( array $payload ): bool {
        foreach ( $this->payloadSchema as $field => $declared ) {
            $optional = str_starts_with( $declared, '?' );
            $type     = $optional ? substr( $declared, 1 ) : $declared;
            if ( ! array_key_exists( $field, $payload ) ) {
                if ( $optional ) continue;
                return false;
            }
            $val = $payload[ $field ];
            if ( $optional && $val === null ) continue;
            switch ( $type ) {
                case 'int':    if ( ! is_int( $val ) && ! ( is_numeric( $val ) && (string) (int) $val === (string) $val ) ) return false; break;
                case 'float':  if ( ! is_numeric( $val ) ) return false; break;
                case 'string': if ( ! is_string( $val ) ) return false; break;
                case 'bool':   if ( ! is_bool( $val ) && ! in_array( $val, [ 0, 1, '0', '1' ], true ) ) return false; break;
            }
        }
        return true;
    }
}
