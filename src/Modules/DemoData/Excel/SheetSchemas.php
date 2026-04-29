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

    /**
     * @return array<string,array{
     *   sheet:string,
     *   entity:string,
     *   columns:array<string,array{label:string,type:string,required:bool,fk?:string}>
     * }>
     */
    public static function all(): array {
        return [
            'teams'   => [
                'sheet'   => 'Teams',
                'entity'  => 'team',
                'columns' => [
                    'auto_key'  => [ 'label' => 'auto_key',  'type' => self::TYPE_KEY,    'required' => false ],
                    'name'      => [ 'label' => 'Name',      'type' => self::TYPE_STRING, 'required' => true  ],
                    'age_group' => [ 'label' => 'Age group', 'type' => self::TYPE_STRING, 'required' => false ],
                    'notes'     => [ 'label' => 'Notes',     'type' => self::TYPE_STRING, 'required' => false ],
                ],
            ],
            'players' => [
                'sheet'   => 'Players',
                'entity'  => 'player',
                'columns' => [
                    'auto_key'        => [ 'label' => 'auto_key',        'type' => self::TYPE_KEY,    'required' => false ],
                    'first_name'      => [ 'label' => 'First name',      'type' => self::TYPE_STRING, 'required' => true  ],
                    'last_name'       => [ 'label' => 'Last name',       'type' => self::TYPE_STRING, 'required' => true  ],
                    'date_of_birth'   => [ 'label' => 'Date of birth',   'type' => self::TYPE_DATE,   'required' => false ],
                    'team_key'        => [ 'label' => 'Team key',        'type' => self::TYPE_KEY,    'required' => false, 'fk' => 'teams.auto_key' ],
                    'jersey_number'   => [ 'label' => 'Jersey number',   'type' => self::TYPE_INT,    'required' => false ],
                    'preferred_foot'  => [ 'label' => 'Preferred foot',  'type' => self::TYPE_STRING, 'required' => false ],
                    'status'          => [ 'label' => 'Status',          'type' => self::TYPE_STRING, 'required' => false ],
                ],
            ],
        ];
    }

    public static function byKey( string $key ): ?array {
        $all = self::all();
        return $all[ $key ] ?? null;
    }
}
