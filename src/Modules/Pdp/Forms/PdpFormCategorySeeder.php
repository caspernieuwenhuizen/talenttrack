<?php
namespace TT\Modules\Pdp\Forms;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Infrastructure\Evaluations\EvalTypeCategoriesRepository;
use TT\Infrastructure\Evaluations\RatingScale;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\I18n\TranslatableFieldRegistry;
use TT\Modules\I18n\TranslationsRepository;

/**
 * PdpFormCategorySeeder (#4976, epic #4975) — the evaluation categories and
 * types a PDP conversation form writes its trainer scores to.
 *
 * The trainer's scores on a form are saved as an ordinary evaluation (owner
 * decision 2 on #4975), so the radar, the history and the reports read them.
 * That needs, for each form the academy uses:
 *
 *  - one sub-category per item, under the item's main (one of the four
 *    existing mains, found by key), with a form-qualified key
 *    (`jg4it_of_<item>`), `is_system = 1`, and the form-owned marker in
 *    `meta` that keeps it out of the ordinary rating inputs;
 *  - one evaluation type per position group ("PDP conversation, outfield",
 *    "PDP conversation, goalkeeper"), mapped to the four mains and hidden from
 *    the ordinary type pickers, with its Dutch name in `tt_translations`.
 *
 * Not a migration: a migration cannot know which install uses which forms.
 * `ensure()` runs when the academy selects its forms, and again before the
 * first form write. It is idempotent. A new version that renames an item
 * updates the category's label and definition; a version never deactivates a
 * category, because older evaluations still point at it.
 *
 * The item text is the academy's own and gets no `tt_translations` row, as a
 * category an admin has renamed: it shows as written in every locale.
 */
final class PdpFormCategorySeeder {

    /** English base names of the two types; the Dutch is in `tt_translations`. */
    public const TYPE_NAMES = [
        PdpFormRegistry::GROUP_OUTFIELD   => 'PDP conversation, outfield',
        PdpFormRegistry::GROUP_GOALKEEPER => 'PDP conversation, goalkeeper',
    ];

    private const TYPE_NAMES_NL = [
        PdpFormRegistry::GROUP_OUTFIELD   => 'POP-gesprek veldspeler',
        PdpFormRegistry::GROUP_GOALKEEPER => 'POP-gesprek keeper',
    ];

    /** `meta` key naming the position group a PDP evaluation type belongs to. */
    public const META_GROUP = 'pdp_position_group';

    /**
     * Can the forms be used at this academy at all? An error naming what is in
     * the way, or null. Checked before anything is written.
     */
    public static function preflight(): ?\WP_Error {
        if ( ! RatingScale::current()->fitsFormSteps() ) {
            return new \WP_Error(
                'rating_scale',
                __( 'The conversation forms score on five steps, and the academy\'s rating scale does not have five. Set the rating scale to five steps first.', 'talenttrack' )
            );
        }
        $missing = [];
        foreach ( self::mainRows() as $key => $row ) {
            if ( $row !== null && (int) ( $row['is_active'] ?? 0 ) === 1 ) continue;
            // A main that exists but is switched off is named as the admin
            // sees it; only one that does not exist at all has just its key.
            $missing[] = $row !== null
                ? EvalCategoriesRepository::displayLabel( (string) ( $row['label'] ?? $key ), (int) ( $row['id'] ?? 0 ) )
                : $key;
        }
        if ( $missing !== [] ) {
            return new \WP_Error(
                'missing_main',
                sprintf(
                    /* translators: %s: the main evaluation categories that are missing or switched off. */
                    __( 'The conversation forms score under the four main evaluation categories, and these are missing or switched off: %s.', 'talenttrack' ),
                    implode( ', ', $missing )
                )
            );
        }
        return null;
    }

    private static function seedFailed(): \WP_Error {
        return new \WP_Error(
            'seed_failed',
            __( 'The evaluation categories of the conversation forms could not be saved, so the forms were not switched on. Try again; if it keeps failing, ask your administrator.', 'talenttrack' )
        );
    }

    /**
     * Make sure the categories and types of the given forms exist and are up
     * to date. `$forms` is position group => form key; null means the forms
     * the academy uses.
     *
     * @param array<string, ?string>|null $forms
     * @return array{categories_created:int, categories_updated:int, types_created:int}|\WP_Error
     */
    public static function ensure( ?array $forms = null ) {
        $forms   = $forms ?? PdpFormRegistry::formsForClub();
        $summary = [ 'categories_created' => 0, 'categories_updated' => 0, 'types_created' => 0 ];
        if ( array_filter( $forms ) === [] ) return $summary;

        $refused = self::preflight();
        if ( $refused !== null ) return $refused;

        $mains = self::mains();
        $repo  = new EvalCategoriesRepository();

        foreach ( PdpFormRegistry::GROUPS as $group ) {
            $form_key = (string) ( $forms[ $group ] ?? '' );
            if ( $form_key === '' ) continue;
            $definition = PdpFormRegistry::definition( $form_key );
            if ( $definition === null ) {
                return new \WP_Error( 'unknown_form', __( 'That conversation form is not available.', 'talenttrack' ) );
            }

            foreach ( PdpFormRegistry::items( $definition ) as $order => $item ) {
                $key  = PdpFormRegistry::categoryKey( $definition, $item['key'] );
                $data = [
                    'label'         => $item['label'],
                    'description'   => $item['definition'],
                    'display_order' => 100 + (int) $order,
                    'is_system'     => 1,
                    'meta'          => [ EvalCategoriesRepository::META_FORM => $form_key, 'pdp_form_item' => $item['key'] ],
                ];
                $existing = $repo->getByKey( $key );
                if ( $existing === null ) {
                    // A refused insert (a key another club on this install
                    // already holds, a database error) stops the save: the
                    // choice is stored only when every category is there.
                    // Rows created before it stay; a retry completes them.
                    $created = $repo->create( $data + [ 'category_key' => $key, 'parent_id' => $mains[ $item['main'] ], 'is_active' => 1 ] );
                    if ( $created <= 0 ) return self::seedFailed();
                    $summary['categories_created']++;
                } elseif ( self::differs( (array) $existing, $data ) ) {
                    $repo->update( (int) ( ( (array) $existing )['id'] ?? 0 ), $data );
                    $summary['categories_updated']++;
                }
            }

            $type = self::ensureType( $group, array_values( $mains ) );
            if ( $type instanceof \WP_Error ) return $type;
            if ( $type ) $summary['types_created']++;
        }

        return $summary;
    }

    /**
     * The evaluation type of a position group, or 0 when it has not been
     * created at this academy.
     */
    public static function typeIdFor( string $group ): int {
        foreach ( QueryHelpers::get_eval_types() as $type ) {
            if ( ( QueryHelpers::lookup_meta( $type )[ self::META_GROUP ] ?? '' ) === $group ) return (int) ( ( (array) $type )['id'] ?? 0 );
        }
        return 0;
    }

    /**
     * The ids of the four mains by key; 0 for one that is missing or switched
     * off at this academy.
     *
     * @return array<string,int>
     */
    private static function mains(): array {
        $out = [];
        foreach ( self::mainRows() as $key => $row ) {
            $out[ $key ] = ( $row !== null && (int) ( $row['is_active'] ?? 0 ) === 1 ) ? (int) ( $row['id'] ?? 0 ) : 0;
        }
        return $out;
    }

    /**
     * The four mains by key, as rows; null for one that does not exist at
     * this academy as a main.
     *
     * @return array<string, array<string,mixed>|null>
     */
    private static function mainRows(): array {
        $repo = new EvalCategoriesRepository();
        $out  = [];
        foreach ( PdpFormRegistry::MAINS as $key ) {
            $found = $repo->getByKey( $key );
            $row   = $found !== null ? (array) $found : [];
            $out[ $key ] = ( $row !== [] && ( $row['parent_id'] ?? null ) === null ) ? $row : null;
        }
        return $out;
    }

    /**
     * Create the group's type when it is missing, and map it to the mains.
     * True when it was created, false when it was there; an error when it
     * could not be written.
     *
     * @param list<int> $main_ids
     * @return bool|\WP_Error
     */
    private static function ensureType( string $group, array $main_ids ) {
        global $wpdb;
        $created = false;
        $type_id = self::typeIdFor( $group );
        if ( $type_id <= 0 ) {
            $ok = $wpdb->insert( "{$wpdb->prefix}tt_lookups", [
                'club_id'     => CurrentClub::id(),
                'lookup_type' => 'eval_type',
                'name'        => self::TYPE_NAMES[ $group ],
                'meta'        => (string) wp_json_encode( [ self::META_GROUP => $group, 'picker_hidden' => true ] ),
                'sort_order'  => 900,
            ] );
            $type_id = $ok === false ? 0 : (int) $wpdb->insert_id;
            if ( $type_id <= 0 ) return self::seedFailed();
            $created = true;
            if ( TranslatableFieldRegistry::isRegistered( TranslatableFieldRegistry::ENTITY_LOOKUP, 'name' ) ) {
                ( new TranslationsRepository() )->upsert( TranslatableFieldRegistry::ENTITY_LOOKUP, $type_id, 'name', 'nl_NL', self::TYPE_NAMES_NL[ $group ], 0 );
            }
        }

        $mapping = new EvalTypeCategoriesRepository();
        $current = $mapping->categoryIdsFor( $type_id );
        $wanted  = $main_ids;
        sort( $wanted );
        if ( $current !== $wanted ) $mapping->setCategoriesFor( $type_id, $wanted );

        return $created;
    }

    /**
     * @param array<mixed>        $row
     * @param array<string,mixed> $data
     */
    private static function differs( array $row, array $data ): bool {
        $meta_now = json_decode( (string) ( $row['meta'] ?? '' ), true );
        return (string) ( $row['label'] ?? '' ) !== $data['label']
            || (string) ( $row['description'] ?? '' ) !== $data['description']
            || (int) ( $row['display_order'] ?? 0 ) !== $data['display_order']
            || (int) ( $row['is_system'] ?? 0 ) !== 1
            || $meta_now !== $data['meta'];
    }
}
