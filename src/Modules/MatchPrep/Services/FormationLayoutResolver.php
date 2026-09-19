<?php
namespace TT\Modules\MatchPrep\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Teams\FootballFormResolver;

/**
 * FormationLayoutResolver (#3574) — which slots a match line-up is drawn on.
 *
 * Five surfaces draw a line-up: the match-prep screen, its print, the
 * team-sheet PDF, the live match sheet and the prep REST projection. They
 * each resolved the layout themselves and disagreed — the prep screen fell
 * back to 4-2-3-1, the live sheet to 4-3-3, the print to the activity's own
 * formation string — and none of them knew a small-sided shape, so an 8v8
 * team's line-up was drawn on an eleven-slot pitch with three empty
 * attacking slots. They now all ask this class, in one order:
 *
 *   1. the bound template's own numbered slots (`slots_json`, #2099), so a
 *      template's geometry — a 3-4-3 diamond — stays authoritative;
 *   2. the default layout for the bound template's shape;
 *   3. the default layout for the team's football form, when nothing is
 *      bound (an 8v8 team lines up 3-3-1, not 4-3-3);
 *   4. the eleven-a-side fallback.
 *
 * Coordinates are percentages of the pitch (0 = top/left, 100 =
 * bottom/right). The small-sided defaults carry the geometry of the
 * templates migration 0243 seeds, which store no slot numbers — so a bound
 * small-sided template resolves through step 2. Six-a-side is played
 * without keepers, so its slots are numbered from 1 with no GK; nothing in
 * match prep or execution treats slot 1 as the keeper.
 *
 * The templates table is install-global (no club_id column), so no tenancy
 * filter applies to the template reads here.
 */
final class FormationLayoutResolver {

    public const FALLBACK_SHAPE = '4-3-3';

    /** The shape a team of this football form lines up in when no template is bound. */
    private const FORM_DEFAULT_SHAPE = [
        '6v6' => '3-2-1',
        '8v8' => '3-3-1',
    ];

    /**
     * Slot layouts per formation shape.
     *
     * @return array<string, list<array{num:int,label:string,x:float,y:float}>>
     */
    public static function defaults(): array {
        return [
            '4-3-3' => [
                [ 'num' =>  9, 'label' => 'ST',  'x' => 50, 'y' => 12 ],
                [ 'num' => 11, 'label' => 'LW',  'x' => 18, 'y' => 28 ],
                [ 'num' => 10, 'label' => 'AM',  'x' => 50, 'y' => 28 ],
                [ 'num' =>  7, 'label' => 'RW',  'x' => 82, 'y' => 28 ],
                [ 'num' =>  8, 'label' => 'LCM', 'x' => 36, 'y' => 45 ],
                [ 'num' =>  6, 'label' => 'RCM', 'x' => 64, 'y' => 45 ],
                [ 'num' =>  5, 'label' => 'LB',  'x' => 16, 'y' => 64 ],
                [ 'num' =>  4, 'label' => 'LCB', 'x' => 38, 'y' => 64 ],
                [ 'num' =>  3, 'label' => 'RCB', 'x' => 62, 'y' => 64 ],
                [ 'num' =>  2, 'label' => 'RB',  'x' => 84, 'y' => 64 ],
                [ 'num' =>  1, 'label' => 'GK',  'x' => 50, 'y' => 88 ],
            ],
            '4-2-3-1' => [
                [ 'num' =>  9, 'label' => 'ST',  'x' => 50, 'y' => 14 ],
                [ 'num' => 11, 'label' => 'LAM', 'x' => 20, 'y' => 30 ],
                [ 'num' => 10, 'label' => 'AM',  'x' => 50, 'y' => 30 ],
                [ 'num' =>  7, 'label' => 'RAM', 'x' => 80, 'y' => 30 ],
                [ 'num' =>  8, 'label' => 'LDM', 'x' => 36, 'y' => 50 ],
                [ 'num' =>  6, 'label' => 'RDM', 'x' => 64, 'y' => 50 ],
                [ 'num' =>  5, 'label' => 'LB',  'x' => 16, 'y' => 68 ],
                [ 'num' =>  4, 'label' => 'LCB', 'x' => 38, 'y' => 68 ],
                [ 'num' =>  3, 'label' => 'RCB', 'x' => 62, 'y' => 68 ],
                [ 'num' =>  2, 'label' => 'RB',  'x' => 84, 'y' => 68 ],
                [ 'num' =>  1, 'label' => 'GK',  'x' => 50, 'y' => 88 ],
            ],
            '4-4-2' => [
                [ 'num' =>  9, 'label' => 'ST',  'x' => 38, 'y' => 14 ],
                [ 'num' => 10, 'label' => 'ST',  'x' => 62, 'y' => 14 ],
                [ 'num' => 11, 'label' => 'LM',  'x' => 14, 'y' => 38 ],
                [ 'num' =>  8, 'label' => 'LCM', 'x' => 38, 'y' => 38 ],
                [ 'num' =>  6, 'label' => 'RCM', 'x' => 62, 'y' => 38 ],
                [ 'num' =>  7, 'label' => 'RM',  'x' => 86, 'y' => 38 ],
                [ 'num' =>  5, 'label' => 'LB',  'x' => 16, 'y' => 64 ],
                [ 'num' =>  4, 'label' => 'LCB', 'x' => 38, 'y' => 64 ],
                [ 'num' =>  3, 'label' => 'RCB', 'x' => 62, 'y' => 64 ],
                [ 'num' =>  2, 'label' => 'RB',  'x' => 84, 'y' => 64 ],
                [ 'num' =>  1, 'label' => 'GK',  'x' => 50, 'y' => 88 ],
            ],
            '3-5-2' => [
                [ 'num' =>  9, 'label' => 'ST',  'x' => 38, 'y' => 14 ],
                [ 'num' => 10, 'label' => 'ST',  'x' => 62, 'y' => 14 ],
                [ 'num' =>  7, 'label' => 'RWB', 'x' => 88, 'y' => 38 ],
                [ 'num' =>  8, 'label' => 'CM',  'x' => 36, 'y' => 40 ],
                [ 'num' =>  6, 'label' => 'CM',  'x' => 50, 'y' => 46 ],
                [ 'num' =>  4, 'label' => 'CM',  'x' => 64, 'y' => 40 ],
                [ 'num' => 11, 'label' => 'LWB', 'x' => 12, 'y' => 38 ],
                [ 'num' =>  5, 'label' => 'LCB', 'x' => 26, 'y' => 66 ],
                [ 'num' =>  3, 'label' => 'CB',  'x' => 50, 'y' => 68 ],
                [ 'num' =>  2, 'label' => 'RCB', 'x' => 74, 'y' => 66 ],
                [ 'num' =>  1, 'label' => 'GK',  'x' => 50, 'y' => 88 ],
            ],
            '3-4-3' => [
                [ 'num' =>  9, 'label' => 'ST',  'x' => 50, 'y' => 14 ],
                [ 'num' => 11, 'label' => 'LW',  'x' => 20, 'y' => 22 ],
                [ 'num' =>  7, 'label' => 'RW',  'x' => 80, 'y' => 22 ],
                [ 'num' => 10, 'label' => 'LM',  'x' => 26, 'y' => 44 ],
                [ 'num' =>  8, 'label' => 'LCM', 'x' => 42, 'y' => 46 ],
                [ 'num' =>  6, 'label' => 'RCM', 'x' => 58, 'y' => 46 ],
                [ 'num' =>  4, 'label' => 'RM',  'x' => 74, 'y' => 44 ],
                [ 'num' =>  5, 'label' => 'LCB', 'x' => 26, 'y' => 68 ],
                [ 'num' =>  3, 'label' => 'CB',  'x' => 50, 'y' => 68 ],
                [ 'num' =>  2, 'label' => 'RCB', 'x' => 74, 'y' => 68 ],
                [ 'num' =>  1, 'label' => 'GK',  'x' => 50, 'y' => 88 ],
            ],
            '4-1-4-1' => [
                [ 'num' =>  9, 'label' => 'ST',  'x' => 50, 'y' => 14 ],
                [ 'num' => 11, 'label' => 'LM',  'x' => 14, 'y' => 34 ],
                [ 'num' => 10, 'label' => 'LCM', 'x' => 38, 'y' => 34 ],
                [ 'num' =>  8, 'label' => 'RCM', 'x' => 62, 'y' => 34 ],
                [ 'num' =>  7, 'label' => 'RM',  'x' => 86, 'y' => 34 ],
                [ 'num' =>  6, 'label' => 'CDM', 'x' => 50, 'y' => 50 ],
                [ 'num' =>  5, 'label' => 'LB',  'x' => 16, 'y' => 66 ],
                [ 'num' =>  4, 'label' => 'LCB', 'x' => 38, 'y' => 66 ],
                [ 'num' =>  3, 'label' => 'RCB', 'x' => 62, 'y' => 66 ],
                [ 'num' =>  2, 'label' => 'RB',  'x' => 84, 'y' => 66 ],
                [ 'num' =>  1, 'label' => 'GK',  'x' => 50, 'y' => 88 ],
            ],
            // Eight-a-side, keeper in slot 1. Geometry from migration 0243.
            '3-3-1' => [
                [ 'num' => 8, 'label' => 'ST', 'x' => 50, 'y' => 15 ],
                [ 'num' => 5, 'label' => 'LM', 'x' => 22, 'y' => 48 ],
                [ 'num' => 6, 'label' => 'CM', 'x' => 50, 'y' => 52 ],
                [ 'num' => 7, 'label' => 'RM', 'x' => 78, 'y' => 48 ],
                [ 'num' => 2, 'label' => 'LB', 'x' => 22, 'y' => 80 ],
                [ 'num' => 3, 'label' => 'CB', 'x' => 50, 'y' => 84 ],
                [ 'num' => 4, 'label' => 'RB', 'x' => 78, 'y' => 80 ],
                [ 'num' => 1, 'label' => 'GK', 'x' => 50, 'y' => 95 ],
            ],
            '3-2-2' => [
                [ 'num' => 7, 'label' => 'LF',  'x' => 32, 'y' => 18 ],
                [ 'num' => 8, 'label' => 'RF',  'x' => 68, 'y' => 18 ],
                [ 'num' => 5, 'label' => 'LCM', 'x' => 34, 'y' => 52 ],
                [ 'num' => 6, 'label' => 'RCM', 'x' => 66, 'y' => 52 ],
                [ 'num' => 2, 'label' => 'LB',  'x' => 22, 'y' => 80 ],
                [ 'num' => 3, 'label' => 'CB',  'x' => 50, 'y' => 84 ],
                [ 'num' => 4, 'label' => 'RB',  'x' => 78, 'y' => 80 ],
                [ 'num' => 1, 'label' => 'GK',  'x' => 50, 'y' => 95 ],
            ],
            // Six-a-side, no keeper: numbered from 1, back to front.
            '3-2-1' => [
                [ 'num' => 6, 'label' => 'ST', 'x' => 50, 'y' => 16 ],
                [ 'num' => 4, 'label' => 'LM', 'x' => 32, 'y' => 50 ],
                [ 'num' => 5, 'label' => 'RM', 'x' => 68, 'y' => 50 ],
                [ 'num' => 1, 'label' => 'LB', 'x' => 20, 'y' => 82 ],
                [ 'num' => 2, 'label' => 'CB', 'x' => 50, 'y' => 88 ],
                [ 'num' => 3, 'label' => 'RB', 'x' => 80, 'y' => 82 ],
            ],
            '2-3-1' => [
                [ 'num' => 6, 'label' => 'ST', 'x' => 50, 'y' => 15 ],
                [ 'num' => 3, 'label' => 'LM', 'x' => 20, 'y' => 50 ],
                [ 'num' => 4, 'label' => 'CM', 'x' => 50, 'y' => 55 ],
                [ 'num' => 5, 'label' => 'RM', 'x' => 80, 'y' => 50 ],
                [ 'num' => 1, 'label' => 'LB', 'x' => 32, 'y' => 85 ],
                [ 'num' => 2, 'label' => 'RB', 'x' => 68, 'y' => 85 ],
            ],
        ];
    }

    /**
     * The shape a line-up is drawn in: the bound template's, else the
     * team's football-form default, else the eleven-a-side fallback.
     */
    public static function shapeFor( int $formation_template_id, int $team_id = 0 ): string {
        $shape = self::templateShape( $formation_template_id );
        if ( $shape !== '' ) return $shape;

        return self::formDefaultShape( $team_id );
    }

    /**
     * The slots a line-up is drawn on, in the order described on the class.
     *
     * @return list<array{num:int,label:string,x:float,y:float}>
     */
    public static function layoutFor( int $formation_template_id, int $team_id = 0 ): array {
        $own = self::templateLayout( $formation_template_id );
        if ( $own !== null ) return $own;

        $defaults = self::defaults();
        $shape    = self::templateShape( $formation_template_id );
        if ( $shape !== '' && isset( $defaults[ $shape ] ) ) return $defaults[ $shape ];

        $form_shape = self::formDefaultShape( $team_id );
        return $defaults[ $form_shape ] ?? $defaults[ self::FALLBACK_SHAPE ];
    }

    /**
     * Slot number → position label for the resolved layout.
     *
     * @return array<int,string>
     */
    public static function labelsFor( int $formation_template_id, int $team_id = 0 ): array {
        $labels = [];
        foreach ( self::layoutFor( $formation_template_id, $team_id ) as $slot ) {
            $labels[ (int) $slot['num'] ] = (string) $slot['label'];
        }
        return $labels;
    }

    /**
     * Per-template slot layout read from `slots_json`, when that JSON
     * carries explicit slot numbers (#2099). `null` when it does not.
     *
     * @return list<array{num:int,label:string,x:float,y:float}>|null
     */
    public static function templateLayout( int $template_id ): ?array {
        if ( $template_id <= 0 ) return null;
        global $wpdb;
        $p = $wpdb->prefix;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . 'tt_formation_templates' ) ) !== $p . 'tt_formation_templates' ) {
            return null;
        }
        $json = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT slots_json FROM {$p}tt_formation_templates WHERE id = %d LIMIT 1",
            $template_id
        ) );
        return self::parseSlotsJson( $json );
    }

    /**
     * Parse a template `slots_json` string into a num-keyed slot layout.
     * Returns `null` unless every slot carries a numeric `num` — a layout
     * without slot numbers can't be aligned to the lineup. `pos.x` /
     * `pos.y` are 0..1 in the stored JSON and scaled to percentages.
     *
     * @return list<array{num:int,label:string,x:float,y:float}>|null
     */
    public static function parseSlotsJson( string $json ): ?array {
        if ( $json === '' ) return null;
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) || $data === [] ) return null;

        $out = [];
        foreach ( $data as $slot ) {
            if ( ! is_array( $slot ) || ! isset( $slot['num'] ) || ! is_numeric( $slot['num'] ) ) {
                return null;
            }
            $pos = is_array( $slot['pos'] ?? null ) ? $slot['pos'] : [];
            $out[] = [
                'num'   => (int) $slot['num'],
                'label' => (string) ( $slot['label'] ?? '' ),
                'x'     => (float) ( $pos['x'] ?? 0.5 ) * 100,
                'y'     => (float) ( $pos['y'] ?? 0.5 ) * 100,
            ];
        }
        return $out;
    }

    /** The bound template's `formation_shape`, or '' when none is bound or it is unknown. */
    public static function templateShape( int $formation_template_id ): string {
        if ( $formation_template_id <= 0 ) return '';
        global $wpdb;
        $p = $wpdb->prefix;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . 'tt_formation_templates' ) ) !== $p . 'tt_formation_templates' ) {
            return '';
        }
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT formation_shape FROM {$p}tt_formation_templates WHERE id = %d LIMIT 1",
            $formation_template_id
        ) );
    }

    /** The shape a team of this team's football form lines up in by default. */
    private static function formDefaultShape( int $team_id ): string {
        if ( $team_id <= 0 ) return self::FALLBACK_SHAPE;
        return self::FORM_DEFAULT_SHAPE[ FootballFormResolver::forTeam( $team_id ) ] ?? self::FALLBACK_SHAPE;
    }
}
