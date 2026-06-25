<?php
namespace TT\Modules\DataBrowser;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * RelationshipResolver — derives table relationships without DB foreign
 * keys (this schema enforces integrity at the app layer, so there are no
 * SQL FKs to read). Relationships are inferred from `*_id` columns:
 *
 *   1. a curated alias map for columns whose name doesn't match their
 *      table (e.g. `wp_user_id` → wp_users, `created_by` → wp_users);
 *   2. a generic guess for `<thing>_id` → `tt_<thing>s`, accepted only
 *      when that table actually exists in the live schema.
 *
 * Produces, for any table: the outgoing links (its FK columns → target
 * table) and the incoming links (other tables whose FK points back here).
 * Targets that aren't browsable `tt_*` tables (e.g. wp_users) resolve but
 * are marked non-browsable so the UI renders them as plain, unlinked text.
 */
class RelationshipResolver {

    /**
     * Columns whose target table can't be guessed from the name.
     * Maps column name → full (unprefixed where TT) target table key.
     * `wp_users` is intentionally non-tt — flagged non-browsable below.
     *
     * @var array<string,string>
     */
    private const ALIASES = [
        'wp_user_id'     => 'wp_users',
        'user_id'        => 'wp_users',
        'parent_user_id' => 'wp_users',
        'created_by'     => 'wp_users',
        'updated_by'     => 'wp_users',
        'actor_user_id'  => 'wp_users',
        'category_id'    => 'tt_eval_categories',
        'evaluation_id'  => 'tt_evaluations',
    ];

    /** @var array<string,array<string,?string>> per-request cache: table => col => target|null */
    private static $cache = [];

    /**
     * Resolve the target table key for an FK column, or null if the
     * column isn't a recognised reference.
     */
    public static function targetFor( string $column ): ?string {
        if ( isset( self::ALIASES[ $column ] ) ) {
            $target = self::ALIASES[ $column ];
            return ( $target === 'wp_users' || SchemaIntrospector::exists( $target ) ) ? $target : null;
        }

        // Generic <thing>_id → tt_<thing>s, validated against the schema.
        if ( substr( $column, -3 ) !== '_id' ) return null;
        $thing = substr( $column, 0, -3 );
        if ( $thing === '' || $thing === 'club' ) return null; // club_id is tenancy, not a relationship

        foreach ( [ 'tt_' . $thing . 's', 'tt_' . $thing ] as $candidate ) {
            if ( SchemaIntrospector::exists( $candidate ) ) return $candidate;
        }
        return null;
    }

    /** A resolved target is clickable only when it's a browsable tt_ table. */
    public static function isBrowsable( ?string $target ): bool {
        return $target !== null && $target !== 'wp_users' && SchemaIntrospector::exists( $target );
    }

    /**
     * Outgoing relationships for a table: its FK columns and their targets.
     *
     * @return array<int,array{column:string,target:string,browsable:bool,label:string}>
     */
    public static function outgoing( string $key ): array {
        $out = [];
        foreach ( SchemaIntrospector::columns( $key ) as $col ) {
            $target = self::targetFor( $col->name );
            if ( $target === null ) continue;
            $out[] = [
                'column'    => $col->name,
                'target'    => $target,
                'browsable' => self::isBrowsable( $target ),
                'label'     => self::targetLabel( $target ),
            ];
        }
        return $out;
    }

    /**
     * Incoming relationships: browsable tables whose FK column points at
     * this table. Scans the full schema once (cached).
     *
     * @return array<int,array{table:string,column:string,label:string}>
     */
    public static function incoming( string $key ): array {
        $in = [];
        foreach ( SchemaIntrospector::tables() as $other_key => $_meta ) {
            if ( $other_key === $key ) continue;
            foreach ( SchemaIntrospector::columns( $other_key ) as $col ) {
                if ( self::targetFor( $col->name ) === $key ) {
                    $in[] = [
                        'table'  => $other_key,
                        'column' => $col->name,
                        'label'  => self::targetLabel( $other_key ),
                    ];
                }
            }
        }
        return $in;
    }

    /** Friendly label for a target table (wp_users gets a plain label). */
    private static function targetLabel( string $target ): string {
        if ( $target === 'wp_users' ) return __( 'WordPress accounts', 'talenttrack' );
        return SemanticRegistry::tableLabel( $target );
    }
}
