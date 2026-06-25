<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\DataBrowser\DataBrowserRepository;
use TT\Modules\DataBrowser\DataBrowserService;
use TT\Modules\DataBrowser\SchemaIntrospector;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * FrontendDataBrowserView (#1859) — read-only browser over the live `tt_*`
 * schema. Two surfaces behind one route (`?tt_view=data-browser`):
 *
 *   - index: a searchable list of tables (curated first), each with a
 *     friendly label, description, row count, and a sensitive badge;
 *   - table page (`&table=tt_…`): semantic column headers, raw paginated
 *     rows, a related-tables chip row, and clickable foreign-key cells.
 *
 * All shaping lives in {@see DataBrowserService} (CLAUDE.md §4); this view
 * only composes HTML. Server-rendered so it works without JS and paginates
 * via plain links. Gated on `tt_view_data_browser` — administrator (matrix
 * admin) and Club Admin (academy admin) only.
 */
class FrontendDataBrowserView extends FrontendViewBase {

    private const CAP = 'tt_view_data_browser';

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( self::CAP ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">'
                . esc_html__( 'You do not have permission to view this section.', 'talenttrack' )
                . '</p>';
            return;
        }

        self::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-data-browser',
            TT_PLUGIN_URL . 'assets/css/frontend-data-browser.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );

        $table = isset( $_GET['table'] ) ? sanitize_key( wp_unslash( (string) $_GET['table'] ) ) : '';

        if ( $table !== '' && SchemaIntrospector::exists( $table ) ) {
            self::renderTable( $table );
            return;
        }

        self::renderIndex();
    }

    /* ------------------------------------------------------------------ */
    /* Index                                                               */
    /* ------------------------------------------------------------------ */

    private static function renderIndex(): void {
        FrontendBreadcrumbs::fromDashboard( __( 'Data Browser', 'talenttrack' ) );
        self::renderHeader( __( 'Data Browser', 'talenttrack' ) );

        echo '<p class="tt-db-intro">'
            . esc_html__( 'Browse the raw data behind TalentTrack — read-only. Each table shows friendly column names with explanations, and how tables connect.', 'talenttrack' )
            . '</p>';

        echo '<div class="tt-db-callout">'
            . '<span class="tt-db-callout__ic" aria-hidden="true">&#128274;</span>'
            . '<span>' . esc_html__( 'Read-only. You cannot change any data or definitions here. Opening a sensitive table (medical, safeguarding, family) is recorded in the audit log.', 'talenttrack' ) . '</span>'
            . '</div>';

        $q      = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
        $tables = DataBrowserService::tablesOverview();

        echo '<form class="tt-db-search" method="get" role="search">';
        self::hiddenViewField();
        echo '<input type="search" name="q" inputmode="search" class="tt-db-search__input" '
            . 'value="' . esc_attr( $q ) . '" '
            . 'placeholder="' . esc_attr__( 'Filter tables by name or description…', 'talenttrack' ) . '" '
            . 'aria-label="' . esc_attr__( 'Filter tables', 'talenttrack' ) . '">';
        echo '</form>';

        if ( $q !== '' ) {
            $needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $q ) : strtolower( $q );
            $tables = array_values( array_filter( $tables, static function ( array $t ) use ( $needle ): bool {
                $hay = strtolower( $t['key'] . ' ' . $t['label'] . ' ' . $t['description'] );
                return strpos( $hay, $needle ) !== false;
            } ) );
            if ( empty( $tables ) ) {
                echo '<p class="tt-notice">' . esc_html__( 'No tables match your search.', 'talenttrack' ) . '</p>';
                return;
            }
        }

        $curated = array_values( array_filter( $tables, static fn( $t ) => $t['curated'] ) );
        $other   = array_values( array_filter( $tables, static fn( $t ) => ! $t['curated'] ) );

        if ( $curated ) {
            self::groupHeading( __( 'Core tables', 'talenttrack' ), count( $curated ) );
            echo '<div class="tt-db-list">';
            foreach ( $curated as $t ) self::tableRow( $t, false );
            echo '</div>';
        }
        if ( $other ) {
            self::groupHeading( __( 'Other tables', 'talenttrack' ), count( $other ) );
            echo '<div class="tt-db-list">';
            foreach ( $other as $t ) self::tableRow( $t, true );
            echo '</div>';
        }
    }

    private static function groupHeading( string $label, int $count ): void {
        echo '<h2 class="tt-db-group">' . esc_html( $label )
            . ' <span class="tt-db-group__cnt">' . esc_html( (string) $count ) . '</span></h2>';
    }

    /** @param array{key:string,label:string,description:string,sensitive:bool,curated:bool,approx_rows:int} $t */
    private static function tableRow( array $t, bool $mini ): void {
        $url = add_query_arg(
            [ 'tt_view' => 'data-browser', 'table' => $t['key'] ],
            RecordLink::dashboardUrl()
        );

        $cls = 'tt-db-row' . ( $mini ? ' tt-db-row--mini' : '' );
        echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( $url ) . '">';
        echo '<span class="tt-db-row__ic" aria-hidden="true">' . ( $t['sensitive'] ? '&#9888;' : '&#128202;' ) . '</span>';
        echo '<span class="tt-db-row__meta">';
        echo '<span class="tt-db-row__title">' . esc_html( $t['label'] )
            . ' <span class="tt-db-row__mono">' . esc_html( $t['key'] ) . '</span>';
        if ( $t['sensitive'] ) {
            echo ' <span class="tt-db-badge tt-db-badge--sensitive">' . esc_html__( 'Sensitive', 'talenttrack' ) . '</span>';
        }
        echo '</span>';
        if ( ! $mini && $t['description'] !== '' ) {
            echo '<span class="tt-db-row__desc">' . esc_html( $t['description'] ) . '</span>';
        }
        echo '</span>';
        echo '<span class="tt-db-row__rt">'
            . '<span class="tt-db-row__rows">' . esc_html( number_format_i18n( $t['approx_rows'] ) )
            . ' <small>' . esc_html__( 'rows', 'talenttrack' ) . '</small></span>'
            . '<span class="tt-db-row__chev" aria-hidden="true">&#8250;</span>'
            . '</span>';
        echo '</a>';
    }

    /* ------------------------------------------------------------------ */
    /* Table page                                                          */
    /* ------------------------------------------------------------------ */

    private static function renderTable( string $key ): void {
        $page   = isset( $_GET['page_n'] ) ? max( 1, absint( wp_unslash( $_GET['page_n'] ) ) ) : 1;
        $search = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
        $row    = isset( $_GET['row'] ) && $_GET['row'] !== '' ? absint( wp_unslash( $_GET['row'] ) ) : null;

        $data = DataBrowserService::tableView( $key, $page, DataBrowserRepository::PER_PAGE, $search, $row );

        FrontendBreadcrumbs::fromDashboard(
            $data['label'],
            [ FrontendBreadcrumbs::viewCrumb( 'data-browser', __( 'Data Browser', 'talenttrack' ) ) ]
        );
        self::renderHeader( $data['label'] );

        // Table identity line: raw name + total + sensitive badge.
        echo '<p class="tt-db-tablemeta">';
        echo '<span class="tt-db-row__mono">' . esc_html( $data['key'] ) . '</span> · ';
        echo esc_html( sprintf(
            /* translators: %s: formatted row count */
            __( '%s rows', 'talenttrack' ),
            number_format_i18n( $data['total'] )
        ) );
        if ( $data['sensitive'] ) {
            echo ' <span class="tt-db-badge tt-db-badge--sensitive">' . esc_html__( 'Sensitive', 'talenttrack' ) . '</span>';
        }
        echo '</p>';

        if ( $data['description'] !== '' ) {
            echo '<p class="tt-db-intro">' . esc_html( $data['description'] ) . '</p>';
        }

        if ( $data['sensitive'] ) {
            echo '<div class="tt-db-callout tt-db-callout--danger">'
                . '<span class="tt-db-callout__ic" aria-hidden="true">&#9888;</span>'
                . '<span>' . esc_html__( 'Sensitive data about minors. Opening this table has been recorded in the audit log.', 'talenttrack' ) . '</span>'
                . '</div>';
        }

        self::relationships( $data['relationships'] );

        if ( $row !== null ) {
            $clear = add_query_arg(
                [ 'tt_view' => 'data-browser', 'table' => $key ],
                RecordLink::dashboardUrl()
            );
            echo '<p class="tt-db-filternote">'
                . esc_html( sprintf( __( 'Showing the single linked row #%d.', 'talenttrack' ), $row ) )
                . ' <a href="' . esc_url( $clear ) . '">' . esc_html__( 'Clear filter', 'talenttrack' ) . '</a></p>';
        }

        // Search within the table.
        echo '<form class="tt-db-search" method="get" role="search">';
        self::hiddenViewField();
        echo '<input type="hidden" name="table" value="' . esc_attr( $key ) . '">';
        echo '<input type="search" name="q" inputmode="search" class="tt-db-search__input" '
            . 'value="' . esc_attr( $search ) . '" '
            . 'placeholder="' . esc_attr__( 'Search rows…', 'talenttrack' ) . '" '
            . 'aria-label="' . esc_attr__( 'Search rows', 'talenttrack' ) . '">';
        echo '</form>';

        self::dataTable( $data );
    }

    /** @param array{outgoing:array<int,array<string,mixed>>,incoming:array<int,array<string,mixed>>} $rels */
    private static function relationships( array $rels ): void {
        if ( empty( $rels['outgoing'] ) && empty( $rels['incoming'] ) ) return;

        echo '<div class="tt-db-related">';
        echo '<span class="tt-db-related__lbl">' . esc_html__( 'Connected to', 'talenttrack' ) . '</span>';

        foreach ( $rels['outgoing'] as $r ) {
            $inner = esc_html( (string) $r['label'] )
                . ' <span class="tt-db-relchip__dir" aria-hidden="true">&#8594;</span>'
                . ' <span class="tt-db-row__mono">' . esc_html( (string) $r['column'] ) . '</span>';
            if ( $r['browsable'] ) {
                $u = add_query_arg(
                    [ 'tt_view' => 'data-browser', 'table' => (string) $r['target'] ],
                    RecordLink::dashboardUrl()
                );
                echo '<a class="tt-db-relchip" href="' . esc_url( $u ) . '">' . $inner . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inner is built from esc_html parts.
            } else {
                echo '<span class="tt-db-relchip tt-db-relchip--static">' . $inner . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inner is built from esc_html parts.
            }
        }

        foreach ( $rels['incoming'] as $r ) {
            $u = add_query_arg(
                [ 'tt_view' => 'data-browser', 'table' => (string) $r['table'] ],
                RecordLink::dashboardUrl()
            );
            $inner = esc_html( (string) $r['label'] )
                . ' <span class="tt-db-relchip__dir" aria-hidden="true">&#8592;</span>'
                . ' <span class="tt-db-row__mono">' . esc_html( (string) $r['column'] ) . '</span>';
            echo '<a class="tt-db-relchip" href="' . esc_url( $u ) . '">' . $inner . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inner is built from esc_html parts.
        }

        echo '</div>';
    }

    /** @param array<string,mixed> $data */
    private static function dataTable( array $data ): void {
        $columns = $data['columns'];
        if ( empty( $columns ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'This table has no columns.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<div class="tt-db-tablewrap">';
        echo '<div class="tt-db-scroll">';
        echo '<table class="tt-db-table">';

        // Header: friendly label (+ description tooltip) + raw name/type.
        echo '<thead><tr>';
        foreach ( $columns as $c ) {
            echo '<th>';
            echo '<span class="tt-db-th__lbl">' . esc_html( (string) $c['label'] );
            if ( (string) $c['description'] !== '' ) {
                echo ' <span class="tt-db-info" tabindex="0" role="note" title="' . esc_attr( (string) $c['description'] )
                    . '" aria-label="' . esc_attr( (string) $c['description'] ) . '">?</span>';
            }
            echo '</span>';
            $meta = (string) $c['name'];
            if ( ! empty( $c['fk'] ) && is_array( $c['fk'] ) ) {
                $meta .= ' → ' . (string) $c['fk']['target'];
            } else {
                $meta .= ' · ' . self::shortType( (string) $c['type'] );
            }
            echo '<span class="tt-db-th__mono">' . esc_html( $meta ) . '</span>';
            echo '</th>';
        }
        echo '</tr></thead>';

        echo '<tbody>';
        if ( empty( $data['rows'] ) ) {
            echo '<tr><td class="tt-db-empty" colspan="' . esc_attr( (string) count( $columns ) ) . '">'
                . esc_html__( 'No rows found.', 'talenttrack' ) . '</td></tr>';
        } else {
            foreach ( $data['rows'] as $rowvals ) {
                echo '<tr>';
                foreach ( $columns as $c ) {
                    self::cell( $c, $rowvals );
                }
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        echo '</div>'; // scroll

        self::pager( $data );
        echo '</div>'; // tablewrap

        echo '<p class="tt-db-foot">'
            . esc_html__( 'Raw values as stored. Foreign keys are clickable to the referenced row. Available via the REST API at /talenttrack/v1/data-browser.', 'talenttrack' )
            . '</p>';
    }

    /**
     * @param array<string,mixed>  $c       column descriptor
     * @param array<string,?string> $rowvals row values keyed by column name
     */
    private static function cell( array $c, array $rowvals ): void {
        $name  = (string) $c['name'];
        $value = $rowvals[ $name ] ?? null;

        $classes = 'tt-db-td';
        if ( ! empty( $c['is_pk'] ) ) $classes .= ' tt-db-td--pk';

        if ( $value === null ) {
            echo '<td class="' . esc_attr( $classes ) . '"><span class="tt-db-null">'
                . esc_html__( '— empty —', 'talenttrack' ) . '</span></td>';
            return;
        }

        // Clickable foreign key → the referenced row in the target table.
        if ( ! empty( $c['fk'] ) && is_array( $c['fk'] ) && $c['fk']['browsable'] && $value !== '' && ctype_digit( $value ) ) {
            $u = add_query_arg(
                [ 'tt_view' => 'data-browser', 'table' => (string) $c['fk']['target'], 'row' => $value ],
                RecordLink::dashboardUrl()
            );
            echo '<td class="' . esc_attr( $classes ) . '">'
                . '<a class="tt-db-fk" href="' . esc_url( $u ) . '">' . esc_html( $value )
                . ' <span class="tt-db-fk__lk" aria-hidden="true">&#128279;</span></a></td>';
            return;
        }

        echo '<td class="' . esc_attr( $classes ) . '">' . esc_html( self::truncate( $value ) ) . '</td>';
    }

    /** @param array<string,mixed> $data */
    private static function pager( array $data ): void {
        $pages = (int) $data['total_pages'];
        $page  = (int) $data['page'];
        if ( $pages <= 1 ) return;

        $from = ( ( $page - 1 ) * (int) $data['per_page'] ) + 1;
        $to   = min( (int) $data['total'], $page * (int) $data['per_page'] );

        echo '<div class="tt-db-pager">';
        echo '<span class="tt-db-pager__info">' . esc_html( sprintf(
            /* translators: 1: first row, 2: last row, 3: total, 4: current page, 5: total pages */
            __( '%1$s–%2$s of %3$s · page %4$s of %5$s', 'talenttrack' ),
            number_format_i18n( $from ),
            number_format_i18n( $to ),
            number_format_i18n( (int) $data['total'] ),
            number_format_i18n( $page ),
            number_format_i18n( $pages )
        ) ) . '</span>';

        echo '<span class="tt-db-pager__ctrls">';
        self::pagerLink( $data, $page - 1, '&#8249;', $page <= 1, false );
        $window = self::pageWindow( $page, $pages );
        foreach ( $window as $p ) {
            self::pagerLink( $data, $p, (string) number_format_i18n( $p ), false, $p === $page );
        }
        self::pagerLink( $data, $page + 1, '&#8250;', $page >= $pages, false );
        echo '</span>';
        echo '</div>';
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function pagerLink( array $data, int $target, string $label, bool $disabled, bool $current ): void {
        $cls = 'tt-db-pgbtn';
        if ( $current )  $cls .= ' tt-db-pgbtn--cur';
        if ( $disabled ) $cls .= ' tt-db-pgbtn--off';

        if ( $disabled || $current ) {
            echo '<span class="' . esc_attr( $cls ) . '" aria-hidden="' . ( $disabled ? 'true' : 'false' ) . '">'
                . wp_kses( $label, [] ) . '</span>';
            return;
        }

        $args = [ 'tt_view' => 'data-browser', 'table' => (string) $data['key'], 'page_n' => $target ];
        if ( (string) $data['search'] !== '' ) $args['q'] = (string) $data['search'];
        if ( $data['pk'] !== null ) $args['row'] = (int) $data['pk'];
        $u = add_query_arg( $args, RecordLink::dashboardUrl() );

        echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( $u ) . '">' . wp_kses( $label, [] ) . '</a>';
    }

    /** A compact page-number window around the current page. @return list<int> */
    private static function pageWindow( int $page, int $pages ): array {
        $start = max( 1, $page - 2 );
        $end   = min( $pages, $start + 4 );
        $start = max( 1, $end - 4 );
        return range( $start, $end );
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private static function hiddenViewField(): void {
        echo '<input type="hidden" name="tt_view" value="data-browser">';
        $page_id = get_queried_object_id();
        if ( $page_id ) {
            echo '<input type="hidden" name="page_id" value="' . esc_attr( (string) $page_id ) . '">';
        }
    }

    /** Trim the leading size/precision noise off a column type for the header. */
    private static function shortType( string $type ): string {
        $type = strtolower( $type );
        $space = strpos( $type, ' ' );
        if ( $space !== false ) $type = substr( $type, 0, $space );
        $paren = strpos( $type, '(' );
        if ( $paren !== false ) $type = substr( $type, 0, $paren );
        return $type;
    }

    /** Keep long text cells from blowing out the row. */
    private static function truncate( string $value, int $max = 120 ): string {
        if ( function_exists( 'mb_strlen' ) ? mb_strlen( $value ) <= $max : strlen( $value ) <= $max ) {
            return $value;
        }
        $cut = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
        return $cut . '…';
    }
}
