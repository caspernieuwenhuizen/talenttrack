<?php
namespace TT\Modules\I18n;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TranslatableFieldRegistry (#0090 Phase 1) — central registry of
 * which entity-types have which translatable fields.
 *
 * Replaces a hardcoded ENUM column on `tt_translations`. `entity_type`
 * is a free-form `VARCHAR(32)` at the schema layer; this registry is
 * the software-enforced allowlist. Adding a new translatable entity
 * = one `register()` call from the owning module's `boot()`. Adding
 * a new field on an existing entity = same.
 *
 * The registry is consumed by:
 *   - `TranslationsRepository::translate()` — refuses to look up
 *     fields that aren't registered (defensive against typos in
 *     call sites).
 *   - The seed-review Excel exporter (Phase 5) — emits
 *     `<field>_<locale>` columns per registered field.
 *   - The per-entity admin "Translations" tabs (Phases 2-4) —
 *     renders one row per registered field.
 *
 * Empty-string return from `fieldsFor()` means "this entity has no
 * registered translatable fields" — caller should fall through to
 * the canonical column without consulting the translations table.
 */
final class TranslatableFieldRegistry {

    public const ENTITY_LOOKUP          = 'lookup';
    public const ENTITY_EVAL_CATEGORY   = 'eval_category';
    public const ENTITY_ROLE            = 'role';
    public const ENTITY_FUNCTIONAL_ROLE = 'functional_role';

    /** @var array<string, list<string>> */
    private static array $fields = [];

    /**
     * Register a translatable entity. Idempotent — last call wins.
     * Plugin authors who want to add their own translatable entity
     * call this from their module's `boot()`:
     *
     *   TranslatableFieldRegistry::register( 'my_entity', [ 'label', 'description' ] );
     *
     * @param string   $entity_type
     * @param string[] $fields
     */
    public static function register( string $entity_type, array $fields ): void {
        $entity_type = trim( $entity_type );
        if ( $entity_type === '' ) return;
        $clean = [];
        foreach ( $fields as $f ) {
            $f = trim( (string) $f );
            if ( $f !== '' ) $clean[] = $f;
        }
        self::$fields[ $entity_type ] = array_values( array_unique( $clean ) );
    }

    /**
     * @return string[]
     */
    public static function fieldsFor( string $entity_type ): array {
        return self::$fields[ $entity_type ] ?? [];
    }

    public static function isRegistered( string $entity_type, string $field ): bool {
        return in_array( $field, self::fieldsFor( $entity_type ), true );
    }

    /**
     * @return string[]
     */
    public static function entities(): array {
        return array_keys( self::$fields );
    }

    public static function clear(): void {
        self::$fields = [];
    }
}
