<?php
namespace TT\Modules\DemoData\Excel;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SheetSchemas (#0059) — single source of truth for the Excel template
 * layout and the importer's validation rules.
 *
 * Each schema declares: sheet name, columns (key + label + type +
 * required flag), and the entity type the importer creates rows of.
 *
 * V1 ships Teams + Players. The other 13 sheets in the original spec
 * (People, Sessions, Evaluations, etc.) are deferred to follow-up;
 * the procedural #0020 generator fills them when the user picks
 * "Hybrid: upload + procedural top-up" in the wizard.
 */
final class SheetSchemas {

    public const TYPE_STRING = 'string';
    public const TYPE_INT    = 'int';
    public const TYPE_DATE   = 'date';
    public const TYPE_KEY    = 'key';

    /** Tab-colour groups per the spec's design. */
    public const GROUP_MASTER        = 'master';        // green
    public const GROUP_TRANSACTIONAL = 'transactional'; // blue
    public const GROUP_CONFIG        = 'config';        // purple
    public const GROUP_REFERENCE     = 'reference';     // grey

    /**
     * Full sheet inventory per the #0059 spec. The template renders all
     * 15 sheets so admins see the complete scope; the importer (v1.5)
     * processes the entity sheets — Teams, Players, Activities,
     * Session_Attendance, Evaluations, Evaluation_Ratings, Goals,
     * Trial_Cases, Player_Journey, People — and reads
     * Generation_Settings to bound dates. The reference sheets
     * (Eval_Categories, Category_Weights, _Lookups) are documentation-
     * only in v1.5; admin-edit them via the existing Configuration
     * surfaces.
     *
     * @return array<string,array{
     *   sheet:string,
     *   entity:string,
     *   group:string,
     *   columns:array<string,array{label:string,type:string,required:bool,fk?:string}>
     * }>
     */
    public static function all(): array {
        return [
            'teams' => [
                'sheet'   => 'Teams',
                'entity'  => 'team',
                'group'   => self::GROUP_MASTER,
                'columns' => [
                    'auto_key'        => [ 'label' => 'auto_key',         'type' => self::TYPE_KEY,    'required' => false ],
                    'name'            => [ 'label' => 'Name',             'type' => self::TYPE_STRING, 'required' => true  ],
                    'age_group'       => [ 'label' => 'Age group',        'type' => self::TYPE_STRING, 'required' => false ],
                    'level'           => [ 'label' => 'Level',            'type' => self::TYPE_STRING, 'required' => false ],
                    'head_coach_key'  => [ 'label' => 'Head coach key',   'type' => self::TYPE_KEY,    'required' => false, 'fk' => 'people.auto_key' ],
                    'notes'           => [ 'label' => 'Notes',            'type' => self::TYPE_STRING, 'required' => false ],
                ],
            ],
            'people' => [
                'sheet'   => 'People',
                'entity'  => 'person',
                'group'   => self::GROUP_MASTER,
                'columns' => [
                    'auto_key'   => [ 'label' => 'auto_key',   'type' => self::TYPE_KEY,    'required' => false ],
                    'first_name' => [ 'label' => 'First name', 'type' => self::TYPE_STRING, 'required' => true  ],
                    'last_name'  => [ 'label' => 'Last name',  'type' => self::TYPE_STRING, 'required' => true  ],
                    'role'       => [ 'label' => 'Role',       'type' => self::TYPE_STRING, 'required' => false ],
                    'team_key'   => [ 'label' => 'Team key',   'type' => self::TYPE_KEY,    'required' => false, 'fk' => 'teams.auto_key' ],
                    'email'      => [ 'label' => 'Email',      'type' => self::TYPE_STRING, 'required' => false ],
                    'phone'      => [ 'label' => 'Phone',      'type' => self::TYPE_STRING, 'required' => false ],
                    'status'     => [ 'label' => 'Status',     'type' => self::TYPE_STRING, 'required' => false ],
                ],
            ],
            'players' => [
                'sheet'   => 'Players',
                'entity'  => 'player',
                'group'   => self::GROUP_MASTER,
                'columns' => [
                    // #0063 — widened to match the player edit form's
                    // primary fields. Importer v1.5 consumes the original
                    // 8 columns; new columns (height_cm / weight_kg /
                    // photo_url / nationality / preferred_positions /
                    // guardian_*) land in the workbook so the user can
                    // capture them at template-fill time, and the
                    // importer follow-up wires them to insert.
                    'auto_key'             => [ 'label' => 'auto_key',             'type' => self::TYPE_KEY,    'required' => false ],
                    'first_name'           => [ 'label' => 'First name',           'type' => self::TYPE_STRING, 'required' => true  ],
                    'last_name'            => [ 'label' => 'Last name',            'type' => self::TYPE_STRING, 'required' => true  ],
                    'date_of_birth'        => [ 'label' => 'Date of birth',        'type' => self::TYPE_DATE,   'required' => false ],
                    'nationality'          => [ 'label' => 'Nationality',          'type' => self::TYPE_STRING, 'required' => false ],
                    'team_key'             => [ 'label' => 'Team key',             'type' => self::TYPE_KEY,    'required' => false, 'fk' => 'teams.auto_key' ],
                    'jersey_number'        => [ 'label' => 'Jersey number',        'type' => self::TYPE_INT,    'required' => false ],
                    'preferred_foot'       => [ 'label' => 'Preferred foot',       'type' => self::TYPE_STRING, 'required' => false ],
                    'preferred_positions'  => [ 'label' => 'Preferred positions',  'type' => self::TYPE_STRING, 'required' => false ],
                    'height_cm'            => [ 'label' => 'Height (cm)',          'type' => self::TYPE_INT,    'required' => false ],
                    'weight_kg'            => [ 'label' => 'Weight (kg)',          'type' => self::TYPE_INT,    'required' => false ],
                    'photo_url'            => [ 'label' => 'Photo URL',            'type' => self::TYPE_STRING, 'required' => false ],
                    'guardian_name'        => [ 'label' => 'Guardian name',        'type' => self::TYPE_STRING, 'required' => false ],
                    'guardian_email'       => [ 'label' => 'Guardian email',       'type' => self::TYPE_STRING, 'required' => false ],
                    'guardian_phone'       => [ 'label' => 'Guardian phone',       'type' => self::TYPE_STRING, 'required' => false ],
                    'date_joined'          => [ 'label' => 'Date joined',          'type' => self::TYPE_DATE,   'required' => false ],
                    'status'               => [ 'label' => 'Status',               'type' => self::TYPE_STRING, 'required' => false ],
                ],
            ],
            'trial_cases' => [
                'sheet'   => 'Trial_Cases',
                'entity'  => 'trial_case',
                'group'   => self::GROUP_MASTER,
                'columns' => [
                    'auto_key'   => [ 'label' => 'auto_key',   'type' => self::TYPE_KEY,    'required' => false ],
                    'player_key' => [ 'label' => 'Player key', 'type' => self::TYPE_KEY,    'required' => true,  'fk' => 'players.auto_key' ],
                    'team_key'   => [ 'label' => 'Team key',   'type' => self::TYPE_KEY,    'required' => true,  'fk' => 'teams.auto_key' ],
                    'start_date' => [ 'label' => 'Start date', 'type' => self::TYPE_DATE,   'required' => true  ],
                    'end_date'   => [ 'label' => 'End date',   'type' => self::TYPE_DATE,   'required' => false ],
                    'decision'   => [ 'label' => 'Decision',   'type' => self::TYPE_STRING, 'required' => false ],
                    'notes'      => [ 'label' => 'Notes',      'type' => self::TYPE_STRING, 'required' => false ],
                ],
            ],
            'sessions' => [
                'sheet'   => 'Sessions',
                'entity'  => 'activity',
                'group'   => self::GROUP_TRANSACTIONAL,
                'columns' => [
                    'auto_key'         => [ 'label' => 'auto_key',         'type' => self::TYPE_KEY,    'required' => false ],
                    'team_key'         => [ 'label' => 'Team key',         'type' => self::TYPE_KEY,    'required' => true,  'fk' => 'teams.auto_key' ],
                    'session_date'     => [ 'label' => 'Date',             'type' => self::TYPE_DATE,   'required' => true  ],
                    'title'            => [ 'label' => 'Title',            'type' => self::TYPE_STRING, 'required' => true  ],
                    'location'         => [ 'label' => 'Location',         'type' => self::TYPE_STRING, 'required' => false ],
                    'activity_type'    => [ 'label' => 'Activity type',    'type' => self::TYPE_STRING, 'required' => false ],
                    'notes'            => [ 'label' => 'Notes',            'type' => self::TYPE_STRING, 'required' => false ],
                ],
            ],
            'session_attendance' => [
                'sheet'   => 'Session_Attendance',
                'entity'  => 'attendance',
                'group'   => self::GROUP_TRANSACTIONAL,
                'columns' => [
                    'session_key' => [ 'label' => 'Session key', 'type' => self::TYPE_KEY,    'required' => true, 'fk' => 'sessions.auto_key' ],
                    'player_key'  => [ 'label' => 'Player key',  'type' => self::TYPE_KEY,    'required' => true, 'fk' => 'players.auto_key' ],
                    'status'      => [ 'label' => 'Status',      'type' => self::TYPE_STRING, 'required' => true  ],
                    'notes'       => [ 'label' => 'Notes',       'type' => self::TYPE_STRING, 'required' => false ],
                ],
            ],
            'evaluations' => [
                'sheet'   => 'Evaluations',
                'entity'  => 'evaluation',
                'group'   => self::GROUP_TRANSACTIONAL,
                'columns' => [
                    'auto_key'   => [ 'label' => 'auto_key',   'type' => self::TYPE_KEY,    'required' => false ],
                    'player_key' => [ 'label' => 'Player key', 'type' => self::TYPE_KEY,    'required' => true, 'fk' => 'players.auto_key' ],
                    'eval_date'  => [ 'label' => 'Date',       'type' => self::TYPE_DATE,   'required' => true  ],
                    'eval_type'  => [ 'label' => 'Type',       'type' => self::TYPE_STRING, 'required' => false ],
                    'notes'      => [ 'label' => 'Notes',      'type' => self::TYPE_STRING, 'required' => false ],
                ],
            ],
            'evaluation_ratings' => [
                'sheet'   => 'Evaluation_Ratings',
                'entity'  => 'eval_rating',
                'group'   => self::GROUP_TRANSACTIONAL,
                'columns' => [
                    'evaluation_key' => [ 'label' => 'Evaluation key', 'type' => self::TYPE_KEY,    'required' => true, 'fk' => 'evaluations.auto_key' ],
                    'category'       => [ 'label' => 'Category',       'type' => self::TYPE_STRING, 'required' => true  ],
                    'rating'         => [ 'label' => 'Rating',         'type' => self::TYPE_INT,    'required' => true  ],
                    'comment'        => [ 'label' => 'Comment',        'type' => self::TYPE_STRING, 'required' => false ],
                ],
            ],
            'goals' => [
                'sheet'   => 'Goals',
                'entity'  => 'goal',
                'group'   => self::GROUP_TRANSACTIONAL,
                'columns' => [
                    'auto_key'    => [ 'label' => 'auto_key',    'type' => self::TYPE_KEY,    'required' => false ],
                    'player_key'  => [ 'label' => 'Player key',  'type' => self::TYPE_KEY,    'required' => true, 'fk' => 'players.auto_key' ],
                    'title'       => [ 'label' => 'Title',       'type' => self::TYPE_STRING, 'required' => true  ],
                    'description' => [ 'label' => 'Description', 'type' => self::TYPE_STRING, 'required' => false ],
                    'status'      => [ 'label' => 'Status',      'type' => self::TYPE_STRING, 'required' => false ],
                    'created_at'  => [ 'label' => 'Created',     'type' => self::TYPE_DATE,   'required' => false ],
                ],
            ],
            'player_journey' => [
                'sheet'   => 'Player_Journey',
                'entity'  => 'player_event',
                'group'   => self::GROUP_TRANSACTIONAL,
                'columns' => [
                    'player_key' => [ 'label' => 'Player key', 'type' => self::TYPE_KEY,    'required' => true, 'fk' => 'players.auto_key' ],
                    'event_type' => [ 'label' => 'Event type', 'type' => self::TYPE_STRING, 'required' => true  ],
                    'event_date' => [ 'label' => 'Date',       'type' => self::TYPE_DATE,   'required' => true  ],
                    'summary'    => [ 'label' => 'Summary',    'type' => self::TYPE_STRING, 'required' => false ],
                    'visibility' => [ 'label' => 'Visibility', 'type' => self::TYPE_STRING, 'required' => false ],
                ],
            ],
            'eval_categories' => [
                'sheet'   => 'Eval_Categories',
                'entity'  => 'eval_category',
                'group'   => self::GROUP_CONFIG,
                'columns' => [
                    'name'   => [ 'label' => 'Name',   'type' => self::TYPE_STRING, 'required' => true ],
                    'parent' => [ 'label' => 'Parent', 'type' => self::TYPE_STRING, 'required' => false ],
                    'order'  => [ 'label' => 'Order',  'type' => self::TYPE_INT,    'required' => false ],
                ],
            ],
            'category_weights' => [
                'sheet'   => 'Category_Weights',
                'entity'  => 'category_weight',
                'group'   => self::GROUP_CONFIG,
                'columns' => [
                    'age_group' => [ 'label' => 'Age group', 'type' => self::TYPE_STRING, 'required' => true ],
                    'category'  => [ 'label' => 'Category',  'type' => self::TYPE_STRING, 'required' => true ],
                    'weight'    => [ 'label' => 'Weight (%)', 'type' => self::TYPE_INT,    'required' => true ],
                ],
            ],
            'generation_settings' => [
                'sheet'   => 'Generation_Settings',
                'entity'  => 'config',
                'group'   => self::GROUP_CONFIG,
                'columns' => [
                    'key'   => [ 'label' => 'Key',   'type' => self::TYPE_STRING, 'required' => true ],
                    'value' => [ 'label' => 'Value', 'type' => self::TYPE_STRING, 'required' => true ],
                ],
            ],
            'lookups' => [
                'sheet'   => '_Lookups',
                'entity'  => 'lookup',
                'group'   => self::GROUP_REFERENCE,
                'columns' => [
                    'lookup_type' => [ 'label' => 'Lookup type', 'type' => self::TYPE_STRING, 'required' => true ],
                    'name'        => [ 'label' => 'Name',        'type' => self::TYPE_STRING, 'required' => true ],
                    'sort_order'  => [ 'label' => 'Sort order',  'type' => self::TYPE_INT,    'required' => false ],
                ],
            ],
        ];
    }

    /** Sheets the importer processes in v1.5 (others are documentation-only). */
    public const IMPORTABLE_SHEETS = [
        'teams', 'people', 'players', 'trial_cases',
        'sessions', 'session_attendance',
        'evaluations', 'evaluation_ratings',
        'goals', 'player_journey',
        'generation_settings',
    ];

    public static function byKey( string $key ): ?array {
        $all = self::all();
        return $all[ $key ] ?? null;
    }

    /** Hex tab-colour for the sheet group. Used by TemplateBuilder. */
    public static function tabColor( string $group ): string {
        switch ( $group ) {
            case self::GROUP_MASTER:        return '92D050'; // green
            case self::GROUP_TRANSACTIONAL: return '4F81BD'; // blue
            case self::GROUP_CONFIG:        return '8064A2'; // purple
            case self::GROUP_REFERENCE:     return '808080'; // grey
            default:                        return 'FFFFFF';
        }
    }
}
