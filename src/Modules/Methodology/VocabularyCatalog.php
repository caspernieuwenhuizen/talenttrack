<?php
namespace TT\Modules\Methodology;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\Repositories\FootballActionsRepository;

/**
 * VocabularyCatalog (#2976) — what an academy's own methodology vocabulary
 * is made of, described once.
 *
 * Nine wp-admin pages maintained these nine vocabularies, which made the one
 * thing this product is most opinionated about — an academy's own playing
 * methodology — the one thing an academy could not edit without leaving the
 * product. The frontend replacement is **one** surface with a picker, not
 * nine sibling routes; nine routes would copy the wp-admin sprawl onto the
 * frontend, which CLAUDE.md §5b exists to prevent.
 *
 * One surface over nine differently-shaped entities needs the shape written
 * down somewhere, and this is that place. Each entry names the REST resource
 * that already owns the entity's CRUD (`AbstractMethodologyRestController`
 * and its concrete children, all gated on `tt_edit_methodology`) and the
 * fields a form must show. Nothing here queries or decides — the view
 * composes from it and the browser client renders it, so the answer a SaaS
 * front end would get from the same endpoints is the answer rendered here.
 *
 * ## Field types
 *
 *   `text` / `number` / `select`   plain scalar
 *   `i18n_text` / `i18n_textarea`  one value per locale — `{ nl, en }`
 *   `i18n_list`                    one list per locale, edited a line at a
 *                                  time — `{ nl: [...], en: [...] }`
 *
 * ## Modes
 *
 *   `collection`  list + create + edit + delete
 *   `singleton`   one row per club; edit only (the REST layer answers 405
 *                 to POST and DELETE, so the client never offers them)
 *   `nested`      a child of a parent the operator picks first — formation
 *                 positions are the only one, and pretending otherwise
 *                 would mean either hiding them or inventing a tenth route
 *
 * @phpstan-type TTVocabularyField array<string,mixed>
 * @phpstan-type TTVocabulary array{
 *   label: string,
 *   mode: string,
 *   rest: string,
 *   collection_key: string,
 *   title_field: string,
 *   subtitle_field: string,
 *   blurb: string,
 *   parent?: array{rest:string, collection_key:string, label:string, title_field:string},
 *   fields: list<array<string,mixed>>
 * }
 */
final class VocabularyCatalog {

    /** Query parameter naming the open vocabulary. */
    public const PARAM = 'vocab';

    /** Opened when no vocabulary is named — the academy's own words come first. */
    public const DEFAULT_SLUG = 'vision';

    /**
     * Every vocabulary, in picker order.
     *
     * @return array<string, TTVocabulary>
     */
    public static function all(): array {
        return [
            'vision'            => self::vision(),
            'principles'        => self::principles(),
            'phases'            => self::phases(),
            'factors'           => self::factors(),
            'positions'         => self::positions(),
            'learning-goals'    => self::learningGoals(),
            'set-pieces'        => self::setPieces(),
            'primer'            => self::primer(),
            'football-actions'  => self::footballActions(),
        ];
    }

    /**
     * One vocabulary by slug, or null when the slug names none.
     *
     * @return TTVocabulary|null
     */
    public static function find( string $slug ): ?array {
        $all = self::all();
        return $all[ $slug ] ?? null;
    }

    /** The slug to open: the requested one when it exists, else the default. */
    public static function resolveSlug( string $requested ): string {
        return isset( self::all()[ $requested ] ) ? $requested : self::DEFAULT_SLUG;
    }

    // ── the nine ─────────────────────────────────────────────────────

    /** @return TTVocabulary */
    private static function vision(): array {
        return [
            'label'          => __( 'Vision', 'talenttrack' ),
            'mode'           => 'singleton',
            'rest'           => 'methodology/vision',
            'collection_key' => 'vision',
            'title_field'    => 'way_of_playing',
            'subtitle_field' => 'style_of_play_key',
            'blurb'          => __( 'How your academy wants to play, in its own words. One record for the whole academy.', 'talenttrack' ),
            'fields'         => [
                self::select( 'style_of_play_key', __( 'Style of play', 'talenttrack' ), MethodologyEnums::stylesOfPlay(), true ),
                self::i18n( 'way_of_playing', __( 'Way of playing', 'talenttrack' ), true ),
                self::i18n( 'notes', __( 'Notes', 'talenttrack' ), true ),
                self::i18nList( 'important_traits', __( 'Important traits', 'talenttrack' ), __( 'One trait per line.', 'talenttrack' ) ),
            ],
        ];
    }

    /** @return TTVocabulary */
    private static function principles(): array {
        return [
            'label'          => __( 'Principles', 'talenttrack' ),
            'mode'           => 'collection',
            'rest'           => 'methodology/principles',
            'collection_key' => 'principles',
            'title_field'    => 'title',
            'subtitle_field' => 'code',
            'blurb'          => __( 'The game principles your coaching is built on, one per team function and task.', 'talenttrack' ),
            'fields'         => [
                self::text( 'code', __( 'Code', 'talenttrack' ), true, __( 'Short reference used in exercises and evaluations.', 'talenttrack' ) ),
                self::select( 'team_function_key', __( 'Team function', 'talenttrack' ), MethodologyEnums::teamFunctions() ),
                self::select( 'team_task_key', __( 'Team task', 'talenttrack' ), MethodologyEnums::teamTasks() ),
                self::i18n( 'title', __( 'Title', 'talenttrack' ) ),
                self::i18n( 'explanation', __( 'Explanation', 'talenttrack' ), true ),
                self::i18n( 'team_guidance', __( 'Team guidance', 'talenttrack' ), true ),
            ],
        ];
    }

    /** @return TTVocabulary */
    private static function phases(): array {
        return [
            'label'          => __( 'Phases', 'talenttrack' ),
            'mode'           => 'collection',
            'rest'           => 'methodology/phases',
            'collection_key' => 'phases',
            'title_field'    => 'title',
            'subtitle_field' => 'side',
            'blurb'          => __( 'The phases of play the primer walks through, attacking and defending.', 'talenttrack' ),
            'fields'         => [
                self::select( 'side', __( 'Side', 'talenttrack' ), MethodologyEnums::sides() ),
                self::number( 'phase_number', __( 'Phase number', 'talenttrack' ), 1, 4 ),
                self::i18n( 'title', __( 'Title', 'talenttrack' ) ),
                self::i18n( 'goal', __( 'Goal', 'talenttrack' ), true ),
            ],
        ];
    }

    /** @return TTVocabulary */
    private static function factors(): array {
        return [
            'label'          => __( 'Influence factors', 'talenttrack' ),
            'mode'           => 'collection',
            'rest'           => 'methodology/influence-factors',
            'collection_key' => 'influence_factors',
            'title_field'    => 'title',
            'subtitle_field' => 'slug',
            'blurb'          => __( 'What your academy believes moves a performance — the factors coaches weigh.', 'talenttrack' ),
            'fields'         => [
                self::text( 'slug', __( 'Slug', 'talenttrack' ), true ),
                self::number( 'sort_order', __( 'Sort order', 'talenttrack' ), 0, 999 ),
                self::i18n( 'title', __( 'Title', 'talenttrack' ) ),
                self::i18n( 'description', __( 'Description', 'talenttrack' ), true ),
            ],
        ];
    }

    /** @return TTVocabulary */
    private static function positions(): array {
        return [
            'label'          => __( 'Positions', 'talenttrack' ),
            'mode'           => 'nested',
            'rest'           => 'methodology/formations/{parent}/positions',
            'collection_key' => 'positions',
            'title_field'    => 'long_name',
            'subtitle_field' => 'short_name',
            'blurb'          => __( 'What each shirt is asked to do, per formation. Pick a formation to see its positions.', 'talenttrack' ),
            'parent'         => [
                'rest'           => 'methodology/formations',
                'collection_key' => 'formations',
                'label'          => __( 'Formation', 'talenttrack' ),
                'title_field'    => 'name',
            ],
            'fields'         => [
                self::number( 'jersey_number', __( 'Shirt number', 'talenttrack' ), 1, 11 ),
                self::i18n( 'short_name', __( 'Short name', 'talenttrack' ) ),
                self::i18n( 'long_name', __( 'Long name', 'talenttrack' ) ),
                self::i18nList( 'attacking_tasks', __( 'Attacking tasks', 'talenttrack' ), __( 'One task per line.', 'talenttrack' ) ),
                self::i18nList( 'defending_tasks', __( 'Defending tasks', 'talenttrack' ), __( 'One task per line.', 'talenttrack' ) ),
            ],
        ];
    }

    /** @return TTVocabulary */
    private static function learningGoals(): array {
        return [
            'label'          => __( 'Learning goals', 'talenttrack' ),
            'mode'           => 'collection',
            'rest'           => 'methodology/learning-goals',
            'collection_key' => 'learning_goals',
            'title_field'    => 'title',
            'subtitle_field' => 'slug',
            'blurb'          => __( 'What a player should be able to do by the end of a phase, in your own wording.', 'talenttrack' ),
            'fields'         => [
                self::text( 'slug', __( 'Slug', 'talenttrack' ), true ),
                self::select( 'side', __( 'Side', 'talenttrack' ), MethodologyEnums::sides() ),
                self::select( 'team_task_key', __( 'Team task', 'talenttrack' ), MethodologyEnums::teamTasks(), true ),
                self::number( 'sort_order', __( 'Sort order', 'talenttrack' ), 0, 999 ),
                self::i18n( 'title', __( 'Title', 'talenttrack' ) ),
                self::i18nList( 'bullets', __( 'Bullets', 'talenttrack' ), __( 'One bullet per line.', 'talenttrack' ) ),
            ],
        ];
    }

    /** @return TTVocabulary */
    private static function setPieces(): array {
        return [
            'label'          => __( 'Set pieces', 'talenttrack' ),
            'mode'           => 'collection',
            'rest'           => 'methodology/set-pieces',
            'collection_key' => 'set_pieces',
            'title_field'    => 'title',
            'subtitle_field' => 'kind_key',
            'blurb'          => __( 'Corners, free kicks, throw-ins and penalties — how your academy takes and defends them.', 'talenttrack' ),
            'fields'         => [
                self::select( 'kind_key', __( 'Kind', 'talenttrack' ), MethodologyEnums::setPieceKinds() ),
                self::select( 'side', __( 'Side', 'talenttrack' ), MethodologyEnums::sides() ),
                self::i18n( 'title', __( 'Title', 'talenttrack' ) ),
                self::i18nList( 'bullets', __( 'Bullets', 'talenttrack' ), __( 'One bullet per line.', 'talenttrack' ) ),
            ],
        ];
    }

    /** @return TTVocabulary */
    private static function primer(): array {
        return [
            'label'          => __( 'Primer', 'talenttrack' ),
            'mode'           => 'singleton',
            'rest'           => 'methodology/framework-primer',
            'collection_key' => 'framework_primer',
            'title_field'    => 'title',
            'subtitle_field' => 'tagline',
            'blurb'          => __( 'The introduction a new coach reads first, and the text between each section of the methodology.', 'talenttrack' ),
            'fields'         => [
                self::i18n( 'title', __( 'Title', 'talenttrack' ) ),
                self::i18n( 'tagline', __( 'Tagline', 'talenttrack' ) ),
                self::i18n( 'intro', __( 'Introduction', 'talenttrack' ), true ),
                self::i18n( 'voetbalmodel_intro', __( 'Football model — introduction', 'talenttrack' ), true ),
                self::i18n( 'voetbalhandelingen_intro', __( 'Football actions — introduction', 'talenttrack' ), true ),
                self::i18n( 'phases_intro', __( 'Phases — introduction', 'talenttrack' ), true ),
                self::i18n( 'learning_goals_intro', __( 'Learning goals — introduction', 'talenttrack' ), true ),
                self::i18n( 'influence_factors_intro', __( 'Influence factors — introduction', 'talenttrack' ), true ),
                self::i18n( 'reflection', __( 'Reflection', 'talenttrack' ), true ),
                self::i18n( 'future', __( 'Looking ahead', 'talenttrack' ), true ),
            ],
        ];
    }

    /** @return TTVocabulary */
    private static function footballActions(): array {
        return [
            'label'          => __( 'Football actions', 'talenttrack' ),
            'mode'           => 'collection',
            'rest'           => 'methodology/football-actions',
            'collection_key' => 'football_actions',
            'title_field'    => 'name',
            'subtitle_field' => 'slug',
            'blurb'          => __( 'The actions a player performs on the pitch. Goals and evaluations are written in these words.', 'talenttrack' ),
            'fields'         => [
                self::text( 'slug', __( 'Slug', 'talenttrack' ), true ),
                self::select( 'category_key', __( 'Category', 'talenttrack' ), FootballActionsRepository::categories() ),
                self::number( 'sort_order', __( 'Sort order', 'talenttrack' ), 0, 999 ),
                self::i18n( 'name', __( 'Name', 'talenttrack' ) ),
                self::i18n( 'description', __( 'Description', 'talenttrack' ), true ),
            ],
        ];
    }

    // ── field builders ───────────────────────────────────────────────

    /** @return array<string,mixed> */
    private static function text( string $name, string $label, bool $required = false, string $help = '' ): array {
        return [ 'name' => $name, 'type' => 'text', 'label' => $label, 'required' => $required, 'help' => $help ];
    }

    /** @return array<string,mixed> */
    private static function number( string $name, string $label, int $min, int $max ): array {
        return [ 'name' => $name, 'type' => 'number', 'label' => $label, 'min' => $min, 'max' => $max, 'required' => false, 'help' => '' ];
    }

    /**
     * @param array<string,string> $options key => translated label
     * @return array<string,mixed>
     */
    private static function select( string $name, string $label, array $options, bool $optional = false ): array {
        return [
            'name'     => $name,
            'type'     => 'select',
            'label'    => $label,
            'options'  => $options,
            'optional' => $optional,
            'required' => ! $optional,
            'help'     => '',
        ];
    }

    /** @return array<string,mixed> */
    private static function i18n( string $name, string $label, bool $long = false ): array {
        return [
            'name'     => $name,
            'type'     => $long ? 'i18n_textarea' : 'i18n_text',
            'label'    => $label,
            'required' => false,
            'help'     => '',
        ];
    }

    /** @return array<string,mixed> */
    private static function i18nList( string $name, string $label, string $help ): array {
        return [ 'name' => $name, 'type' => 'i18n_list', 'label' => $label, 'required' => false, 'help' => $help ];
    }
}
