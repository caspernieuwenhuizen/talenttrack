<?php
namespace TT\Modules\I18n;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;

/**
 * I18nModule (#0090 Phase 1) — data-row internationalisation
 * foundation. Owns:
 *
 *   - `tt_translations` table (migration 0080).
 *   - `TranslatableFieldRegistry` — software allowlist of
 *     translatable (entity_type, field) pairs.
 *   - `TranslationsRepository` — read/write API + cache.
 *   - `tt_edit_translations` cap + `translations` matrix entity
 *     (top-up migration 0081 backfills existing installs).
 *   - `REGISTERED_LOCALES` constant — the set of locales the
 *     translation editor surfaces. Adding a locale here lights it
 *     up across every translatable entity's admin tab + the
 *     seed-review Excel.
 *
 * Per-entity registration (lookups → name + description, eval
 * categories → label, roles → label, functional roles → label) lands
 * in Phases 2-4 from each entity's owning module's `boot()`. Phase 1
 * ships the foundation only; no entity rolls over yet.
 *
 * **`.po` is unchanged by this module.** UI strings continue to flow
 * through `__()` / `_n()` / `_x()` resolved by gettext from `nl_NL.po`.
 * Phase 6 cleans up `nl_NL.po` after Phases 2-4 migrate the four v1
 * entities — until then, both channels coexist (because no entity
 * has migrated yet).
 */
class I18nModule implements ModuleInterface {

    /**
     * Locales the translation editor surfaces. Adding a locale here
     * lights it up across every translatable entity's admin tab + the
     * seed-review Excel — no schema change. The locale strings match
     * WordPress `WPLANG` codes.
     *
     * Phase 7 (v3.110.31) added `fr_FR`, `de_DE`, `es_ES` per spec
     * Decision Q10. The data-row channel is now ready for FR/DE/ES
     * operators to author translations row-by-row via the admin form
     * + the seed-review Excel; the actual `.po` rollout for UI
     * strings (button labels, headings) is `#0010` territory and
     * ships independently. The export/import/admin form pick up the
     * new columns automatically.
     *
     * @var list<string>
     */
    public const REGISTERED_LOCALES = [ 'en_US', 'nl_NL', 'fr_FR', 'de_DE', 'es_ES' ];

    public function getName(): string { return 'i18n'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        // Cap-ensure runs every request — idempotent. Bridges the
        // tt_edit_translations cap onto administrator + tt_club_admin
        // + tt_head_dev so role-based callers (admin pages, REST
        // gates) work alongside the matrix layer.
        add_action( 'init', [ self::class, 'ensureCapabilities' ] );

        // Phase 2 — register the `lookup` entity with `name` and
        // `description` translatable fields. `LookupTranslator`
        // consults `TranslationsRepository` first, then falls back to
        // the legacy `tt_lookups.translations` JSON column, then to
        // gettext. Phases 3-4 add `eval_category`, `role`,
        // `functional_role` here as each entity migrates.
        TranslatableFieldRegistry::register(
            TranslatableFieldRegistry::ENTITY_LOOKUP,
            [ 'name', 'description' ]
        );

        // Phase 3 — register the `eval_category` entity with the
        // single translatable field `label` (per spec Decision Q6).
        // `EvalCategoriesRepository::displayLabel()` consults
        // `TranslationsRepository::translate()` first when the caller
        // passes an entity_id, then falls back to gettext for legacy
        // call sites that only have the raw string. Description is
        // intentionally not translatable in v1.
        TranslatableFieldRegistry::register(
            TranslatableFieldRegistry::ENTITY_EVAL_CATEGORY,
            [ 'label' ]
        );

        // Phase 4 — register the `role` + `functional_role` entities
        // (per spec Decision Q6: both translatable on `label` only).
        // `RolesPage::roleLabel()` and `FunctionalRolesPage::roleLabel()`
        // consult `TranslationsRepository::translate()` first when the
        // caller passes an entity_id, then fall back to the existing
        // gettext switch / humanised-key fallback.
        TranslatableFieldRegistry::register(
            TranslatableFieldRegistry::ENTITY_ROLE,
            [ 'label' ]
        );
        TranslatableFieldRegistry::register(
            TranslatableFieldRegistry::ENTITY_FUNCTIONAL_ROLE,
            [ 'label' ]
        );
    }

    /**
     * tt_edit_translations — granted to administrator + tt_club_admin
     * + tt_head_dev by default. Mirrors the persona-dashboard editor
     * (#0060) + custom widgets (#0078) cap-ensure pattern.
     */
    public static function ensureCapabilities(): void {
        $roles = [
            'administrator' => [ 'tt_edit_translations' ],
            'tt_club_admin' => [ 'tt_edit_translations' ],
            'tt_head_dev'   => [ 'tt_edit_translations' ],
        ];
        foreach ( $roles as $role_key => $caps ) {
            $role = get_role( $role_key );
            if ( ! $role ) continue;
            foreach ( $caps as $cap ) {
                if ( ! $role->has_cap( $cap ) ) $role->add_cap( $cap );
            }
        }
    }
}
