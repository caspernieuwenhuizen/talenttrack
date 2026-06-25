<?php
namespace TT\Modules\DataBrowser;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Kernel;
use TT\Infrastructure\Audit\AuditService;

/**
 * DataBrowserService — the single domain entry point for the read-only
 * Data Browser. Both the REST controller and the PHP view call into this,
 * so a future SaaS front end consuming the REST API gets the same answers
 * as the rendered plugin (CLAUDE.md §4). No rendering, no WP-output here —
 * just shaped, portable data structures.
 */
class DataBrowserService {

    /**
     * Summaries for the index: every browsable table with its friendly
     * label, description, sensitivity, whether it's hand-curated, and an
     * approximate row count. Sorted curated-first, then alphabetically.
     *
     * @return array<int,array{key:string,label:string,description:string,sensitive:bool,curated:bool,approx_rows:int}>
     */
    public static function tablesOverview(): array {
        $out = [];
        foreach ( SchemaIntrospector::tables() as $key => $meta ) {
            $out[] = [
                'key'         => $key,
                'label'       => SemanticRegistry::tableLabel( $key ),
                'description' => SemanticRegistry::tableDescription( $key ),
                'sensitive'   => SemanticRegistry::isSensitive( $key ),
                'curated'     => SemanticRegistry::isCurated( $key ),
                'approx_rows' => (int) $meta->approx_rows,
            ];
        }

        usort( $out, static function ( array $a, array $b ): int {
            if ( $a['curated'] !== $b['curated'] ) return $a['curated'] ? -1 : 1;
            return strcmp( $a['label'], $b['label'] );
        } );

        return $out;
    }

    /**
     * Column descriptors for a table: friendly label, curated description,
     * raw type, primary-key flag, and resolved foreign-key target.
     *
     * @return array<int,array{name:string,label:string,description:string,type:string,nullable:bool,is_pk:bool,fk:?array{target:string,browsable:bool,label:string}}>
     */
    public static function columns( string $key ): array {
        $out = [];
        foreach ( SchemaIntrospector::columns( $key ) as $col ) {
            $target = RelationshipResolver::targetFor( $col->name );
            $fk = null;
            if ( $target !== null ) {
                $fk = [
                    'target'    => $target,
                    'browsable' => RelationshipResolver::isBrowsable( $target ),
                    'label'     => $target === 'wp_users'
                        ? __( 'WordPress accounts', 'talenttrack' )
                        : SemanticRegistry::tableLabel( $target ),
                ];
            }
            $out[] = [
                'name'        => $col->name,
                'label'       => SemanticRegistry::columnLabel( $key, $col->name ),
                'description' => SemanticRegistry::columnDescription( $key, $col->name ),
                'type'        => $col->type,
                'nullable'    => $col->nullable,
                'is_pk'       => $col->key === 'PRI',
                'fk'          => $fk,
            ];
        }
        return $out;
    }

    /**
     * Full payload for a table page: metadata, columns, a page of raw
     * rows, pagination, and relationships. Viewing a sensitive table is
     * audit-logged here, exactly once per call, so every consumer (REST
     * or rendered) records the access.
     *
     * @return array{
     *   key:string, label:string, description:string, sensitive:bool, full_name:string,
     *   columns:array<int,array<string,mixed>>,
     *   rows:array<int,array<string,?string>>,
     *   total:int, page:int, per_page:int, total_pages:int, search:string, pk:?int,
     *   relationships:array{outgoing:array<int,array<string,mixed>>,incoming:array<int,array<string,mixed>>}
     * }
     */
    public static function tableView( string $key, int $page = 1, int $per_page = DataBrowserRepository::PER_PAGE, string $search = '', ?int $pk = null ): array {
        $columns  = self::columns( $key );
        $total    = DataBrowserRepository::count( $key, $search, $pk );
        $per_page = max( 1, min( DataBrowserRepository::MAX_PER_PAGE, $per_page ) );
        $pages    = max( 1, (int) ceil( $total / $per_page ) );
        $page     = max( 1, min( $pages, $page ) );

        $raw  = DataBrowserRepository::rows( $key, $page, $per_page, $search, $pk );
        $rows = [];
        foreach ( $raw as $row ) {
            $rows[] = self::stringifyRow( (array) $row );
        }

        if ( SemanticRegistry::isSensitive( $key ) ) {
            self::auditSensitiveView( $key );
        }

        return [
            'key'           => $key,
            'label'         => SemanticRegistry::tableLabel( $key ),
            'description'   => SemanticRegistry::tableDescription( $key ),
            'sensitive'     => SemanticRegistry::isSensitive( $key ),
            'full_name'     => SchemaIntrospector::fullName( $key ),
            'columns'       => $columns,
            'rows'          => $rows,
            'total'         => $total,
            'page'          => $page,
            'per_page'      => $per_page,
            'total_pages'   => $pages,
            'search'        => $search,
            'pk'            => $pk,
            'relationships' => [
                'outgoing' => RelationshipResolver::outgoing( $key ),
                'incoming' => RelationshipResolver::incoming( $key ),
            ],
        ];
    }

    /**
     * Normalise a raw DB row to string|null values so consumers don't have
     * to care about column types. NULL stays null (rendered as "— empty —").
     *
     * @param array<string,mixed> $row
     * @return array<string,?string>
     */
    private static function stringifyRow( array $row ): array {
        $out = [];
        foreach ( $row as $col => $value ) {
            $out[ $col ] = $value === null ? null : (string) $value;
        }
        return $out;
    }

    /** Record a sensitive-table view in the audit log (best-effort). */
    private static function auditSensitiveView( string $key ): void {
        $audit = Kernel::instance()->container()->get( 'audit' );
        if ( $audit instanceof AuditService ) {
            $audit->record( 'data_browser.view', 'data_browser_table', 0, [
                'table' => $key,
            ] );
        }
    }
}
