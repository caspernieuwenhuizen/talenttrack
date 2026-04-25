<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FrontendListTable — reusable list/search/filter/sort/paginate
 * component, backed by REST.
 *
 * #0019 Sprint 2 keystone. Sessions + Goals are the first consumers
 * (Sprint 2 sessions 2.3 + 2.4); Players + Teams (Sprint 3), People
 * (Sprint 4), and admin-tier (Sprint 5) reuse the same component.
 * The whole epic's frontend velocity downstream depends on the API
 * here being clean.
 *
 * Architecture:
 *   - Server renders the shell (filter form, table head, an initial
 *     row payload as JSON in a `<script type="application/json">`
 *     tag, pagination scaffolding) for the requested filter / page.
 *     No-JS users get the initial page and a working filter form
 *     that posts back as a full page reload.
 *   - `assets/js/components/frontend-list-table.js` hydrates: takes
 *     over filter changes, sort, pagination + per-page selector, and
 *     keeps the URL querystring in sync via `history.replaceState`.
 *
 * Usage:
 *
 *   FrontendListTable::render([
 *     'rest_path' => 'sessions',
 *     'columns'   => [
 *       'session_date' => [ 'label' => __('Date',  'talenttrack'), 'sortable' => true ],
 *       'team_name'    => [ 'label' => __('Team',  'talenttrack') ],
 *       'title'        => [ 'label' => __('Title', 'talenttrack'), 'sortable' => true ],
 *       'attendance'   => [ 'label' => __('Att.%', 'talenttrack'), 'sortable' => true, 'render' => 'percent', 'value_key' => 'attendance_pct' ],
 *     ],
 *     'filters' => [
 *       'team_id' => [ 'type' => 'select', 'label' => __('Team', 'talenttrack'), 'options' => $team_options ],
 *       'date'    => [ 'type' => 'date_range', 'label_from' => __('From', 'talenttrack'), 'label_to' => __('To', 'talenttrack'), 'param_from' => 'date_from', 'param_to' => 'date_to' ],
 *     ],
 *     'row_actions' => [
 *       'edit'   => [ 'label' => __('Edit',   'talenttrack'), 'href' => '?tt_view=sessions&edit={id}' ],
 *       'delete' => [ 'label' => __('Delete', 'talenttrack'), 'rest_method' => 'DELETE', 'rest_path' => 'sessions/{id}', 'confirm' => __('Delete this session?', 'talenttrack') ],
 *     ],
 *     'empty_state' => __('No sessions match your filters.', 'talenttrack'),
 *     'search'      => [ 'placeholder' => __('Search sessions…', 'talenttrack') ],
 *     'default_sort' => [ 'orderby' => 'session_date', 'order' => 'desc' ],
 *   ]);
 *
 * Column render keys (built-in, more can be added later):
 *   'text'    — escape and print (default).
 *   'date'    — `Y-m-d` rendered as locale date.
 *   'percent' — append `%` if non-null, otherwise em-dash.
 *
 * Filter `type` values supported:
 *   'select'     — single-select dropdown. Requires `options` (value=>label).
 *   'date_range' — two date inputs; param_from / param_to override the
 *                  default `<key>_from` / `<key>_to` URL keys.
 *   'text'       — free-text input bound to `filter[<key>]`.
 *
 * Each instance gets a unique DOM id so multiple list tables can
 * coexist on the same view.
 */
class FrontendListTable {

    /**
     * @param array<string, mixed> $config
     */
    public static function render( array $config ): string {
        $id            = 'tt-list-' . substr( md5( (string) ( $config['rest_path'] ?? wp_rand() ) . wp_rand() ), 0, 8 );
        $rest_path     = (string) ( $config['rest_path'] ?? '' );
        $columns       = is_array( $config['columns']     ?? null ) ? $config['columns']     : [];
        $filters       = is_array( $config['filters']     ?? null ) ? $config['filters']     : [];
        $row_actions   = is_array( $config['row_actions'] ?? null ) ? $config['row_actions'] : [];
        $empty_state   = (string) ( $config['empty_state'] ?? __( 'No results.', 'talenttrack' ) );
        $search_cfg    = is_array( $config['search']      ?? null ) ? $config['search']      : [];
        $default_sort  = is_array( $config['default_sort']?? null ) ? $config['default_sort']: [];
        $per_page_opts = is_array( $config['per_page_options'] ?? null ) ? $config['per_page_options'] : [ 10, 25, 50, 100 ];

        if ( $rest_path === '' || ! $columns ) return '';

        $state = self::stateFromQuery( $filters, $default_sort );

        // Declarative config that the JS hydrator will consume — keeps
        // PHP and JS in sync without the JS having to inspect markup.
        $js_config = [
            'rest_path'        => $rest_path,
            'columns'          => self::columnsForJs( $columns ),
            'filters'          => self::filtersForJs( $filters ),
            'row_actions'      => self::rowActionsForJs( $row_actions ),
            'per_page_options' => array_values( $per_page_opts ),
            'default_sort'     => [
                'orderby' => (string) ( $default_sort['orderby'] ?? '' ),
                'order'   => (string) ( $default_sort['order']   ?? 'asc' ),
            ],
            'i18n'             => [
                'empty'   => $empty_state,
                'loading' => __( 'Loading…', 'talenttrack' ),
                'error'   => __( 'Could not load — retry?', 'talenttrack' ),
                'retry'   => __( 'Retry', 'talenttrack' ),
                'page_of' => __( 'Page %1$d of %2$d', 'talenttrack' ),
                'showing' => __( 'Showing %1$d–%2$d of %3$d', 'talenttrack' ),
                'per_page'=> __( 'Per page', 'talenttrack' ),
                'no_op'   => __( '—', 'talenttrack' ),
            ],
        ];

        $config_json = wp_json_encode( $js_config );
        $state_json  = wp_json_encode( $state );

        ob_start();
        ?>
        <div class="tt-list-table" id="<?php echo esc_attr( $id ); ?>" data-tt-list-table="1">
            <form class="tt-list-table-filters" data-tt-list-form="1" method="get">
                <?php
                // Preserve any non-list-table query params (e.g. tt_view) so the no-JS submit doesn't drop tile-router state.
                foreach ( self::passthroughQueryArgs( $filters ) as $k => $v ) {
                    printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $k ), esc_attr( $v ) );
                }
                if ( ! empty( $search_cfg ) ) :
                    $placeholder = (string) ( $search_cfg['placeholder'] ?? __( 'Search…', 'talenttrack' ) );
                    ?>
                    <label class="tt-list-table-search">
                        <span class="screen-reader-text"><?php echo esc_html( $placeholder ); ?></span>
                        <input type="search" name="search" value="<?php echo esc_attr( $state['search'] ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" />
                    </label>
                <?php endif; ?>
                <?php foreach ( $filters as $key => $filter ) : self::renderFilterControl( (string) $key, $filter, $state['filter'] ); endforeach; ?>
                <noscript><button type="submit" class="tt-btn tt-btn-secondary"><?php esc_html_e( 'Apply', 'talenttrack' ); ?></button></noscript>
            </form>

            <div class="tt-list-table-status" data-tt-list-status="1" aria-live="polite"></div>

            <div class="tt-list-table-wrap">
                <table class="tt-list-table-table" data-tt-list-table-el="1">
                    <thead><tr><?php foreach ( $columns as $key => $col ) : self::renderColumnHeader( (string) $key, $col, $state ); endforeach; ?>
                        <?php if ( $row_actions ) : ?><th class="tt-list-table-actions-col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'talenttrack' ); ?></span></th><?php endif; ?>
                    </tr></thead>
                    <tbody data-tt-list-body="1">
                        <tr class="tt-list-table-empty" data-tt-list-empty="1"><td colspan="<?php echo (int) ( count( $columns ) + ( $row_actions ? 1 : 0 ) ); ?>"><?php echo esc_html( $empty_state ); ?></td></tr>
                    </tbody>
                </table>
            </div>

            <div class="tt-list-table-footer" data-tt-list-footer="1">
                <span class="tt-list-table-summary" data-tt-list-summary="1"></span>
                <label class="tt-list-table-perpage">
                    <span><?php esc_html_e( 'Per page', 'talenttrack' ); ?></span>
                    <select data-tt-list-perpage="1">
                        <?php foreach ( $per_page_opts as $opt ) : ?>
                            <option value="<?php echo (int) $opt; ?>" <?php selected( (int) $state['per_page'], (int) $opt ); ?>><?php echo (int) $opt; ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <span class="tt-list-table-pager" data-tt-list-pager="1"></span>
            </div>

            <script type="application/json" data-tt-list-config="1"><?php echo $config_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
            <script type="application/json" data-tt-list-state="1"><?php echo $state_json;  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Build the initial state from `$_GET` so a bookmarked URL renders
     * the right slice on first paint.
     *
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $default_sort
     * @return array<string,mixed>
     */
    private static function stateFromQuery( array $filters, array $default_sort ): array {
        $get_filter = isset( $_GET['filter'] ) && is_array( $_GET['filter'] ) ? $_GET['filter'] : [];
        $clean_filter = [];
        foreach ( $filters as $key => $cfg ) {
            $type = (string) ( $cfg['type'] ?? 'text' );
            if ( $type === 'date_range' ) {
                $from = (string) ( $cfg['param_from'] ?? ( $key . '_from' ) );
                $to   = (string) ( $cfg['param_to']   ?? ( $key . '_to' ) );
                if ( ! empty( $get_filter[ $from ] ) ) $clean_filter[ $from ] = sanitize_text_field( wp_unslash( (string) $get_filter[ $from ] ) );
                if ( ! empty( $get_filter[ $to ] ) )   $clean_filter[ $to ]   = sanitize_text_field( wp_unslash( (string) $get_filter[ $to ] ) );
            } elseif ( ! empty( $get_filter[ $key ] ) ) {
                $clean_filter[ $key ] = sanitize_text_field( wp_unslash( (string) $get_filter[ $key ] ) );
            }
        }
        return [
            'search'   => isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['search'] ) ) : '',
            'filter'   => $clean_filter,
            'orderby'  => isset( $_GET['orderby'] ) ? sanitize_key( (string) $_GET['orderby'] ) : (string) ( $default_sort['orderby'] ?? '' ),
            'order'    => isset( $_GET['order'] ) && in_array( strtolower( (string) $_GET['order'] ), [ 'asc', 'desc' ], true )
                ? strtolower( (string) $_GET['order'] )
                : (string) ( $default_sort['order'] ?? 'asc' ),
            'page'     => max( 1, isset( $_GET['page'] ) ? absint( $_GET['page'] ) : 1 ),
            'per_page' => isset( $_GET['per_page'] ) ? absint( $_GET['per_page'] ) : 25,
        ];
    }

    /**
     * Pass-through query args that should survive a no-JS form submit.
     * Right now: anything that isn't owned by the list table itself.
     *
     * @param array<string,mixed> $filters
     * @return array<string,string>
     */
    private static function passthroughQueryArgs( array $filters ): array {
        $owned = [ 'search', 'filter', 'orderby', 'order', 'page', 'per_page' ];
        $out   = [];
        foreach ( $_GET as $k => $v ) {
            if ( in_array( (string) $k, $owned, true ) ) continue;
            if ( is_array( $v ) ) continue;
            $out[ (string) $k ] = sanitize_text_field( wp_unslash( (string) $v ) );
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $filter
     * @param array<string,string> $current
     */
    private static function renderFilterControl( string $key, array $filter, array $current ): void {
        $type  = (string) ( $filter['type']  ?? 'text' );
        $label = (string) ( $filter['label'] ?? $key );

        if ( $type === 'select' ) {
            $opts = is_array( $filter['options'] ?? null ) ? $filter['options'] : [];
            ?>
            <label class="tt-list-table-filter">
                <span><?php echo esc_html( $label ); ?></span>
                <select name="filter[<?php echo esc_attr( $key ); ?>]">
                    <option value=""><?php esc_html_e( 'All', 'talenttrack' ); ?></option>
                    <?php foreach ( $opts as $value => $text ) : ?>
                        <option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( (string) ( $current[ $key ] ?? '' ), (string) $value ); ?>><?php echo esc_html( (string) $text ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php
        } elseif ( $type === 'date_range' ) {
            $from = (string) ( $filter['param_from'] ?? ( $key . '_from' ) );
            $to   = (string) ( $filter['param_to']   ?? ( $key . '_to' ) );
            $label_from = (string) ( $filter['label_from'] ?? __( 'From', 'talenttrack' ) );
            $label_to   = (string) ( $filter['label_to']   ?? __( 'To', 'talenttrack' ) );
            ?>
            <label class="tt-list-table-filter">
                <span><?php echo esc_html( $label_from ); ?></span>
                <input type="date" name="filter[<?php echo esc_attr( $from ); ?>]" value="<?php echo esc_attr( (string) ( $current[ $from ] ?? '' ) ); ?>" />
            </label>
            <label class="tt-list-table-filter">
                <span><?php echo esc_html( $label_to ); ?></span>
                <input type="date" name="filter[<?php echo esc_attr( $to ); ?>]" value="<?php echo esc_attr( (string) ( $current[ $to ] ?? '' ) ); ?>" />
            </label>
            <?php
        } else {
            // text (default)
            ?>
            <label class="tt-list-table-filter">
                <span><?php echo esc_html( $label ); ?></span>
                <input type="text" name="filter[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) ( $current[ $key ] ?? '' ) ); ?>" />
            </label>
            <?php
        }
    }

    /**
     * @param array<string,mixed> $col
     * @param array<string,mixed> $state
     */
    private static function renderColumnHeader( string $key, array $col, array $state ): void {
        $label = (string) ( $col['label'] ?? $key );
        $sortable = ! empty( $col['sortable'] );
        if ( ! $sortable ) {
            printf( '<th>%s</th>', esc_html( $label ) );
            return;
        }
        $is_active = ( $state['orderby'] === $key );
        $next_order = $is_active && $state['order'] === 'asc' ? 'desc' : 'asc';
        $href = esc_url( add_query_arg( [
            'orderby' => $key,
            'order'   => $next_order,
        ] ) );
        $arrow = $is_active ? ( $state['order'] === 'asc' ? '↑' : '↓' ) : '';
        printf(
            '<th class="tt-list-table-sortable %s" data-tt-list-sort="%s"><a href="%s">%s <span aria-hidden="true">%s</span></a></th>',
            $is_active ? 'is-active' : '',
            esc_attr( $key ),
            $href,
            esc_html( $label ),
            esc_html( $arrow )
        );
    }

    /**
     * @param array<string,array<string,mixed>> $columns
     * @return array<string,array<string,mixed>>
     */
    private static function columnsForJs( array $columns ): array {
        $out = [];
        foreach ( $columns as $key => $col ) {
            $entry = [
                'label'     => (string) ( $col['label'] ?? $key ),
                'sortable'  => ! empty( $col['sortable'] ),
                'render'    => (string) ( $col['render']    ?? 'text' ),
                'value_key' => (string) ( $col['value_key'] ?? $key ),
            ];
            // #0019 Sprint 2 session 2.4 — inline_select renders an
            // editable dropdown in the cell. The hydrator binds change
            // events to PATCH the configured endpoint with the chosen
            // value. Used by the goals list for inline status edits;
            // generic enough that Sprint 3+ players/positions etc. can
            // reuse it.
            if ( ( $col['render'] ?? '' ) === 'inline_select' ) {
                $entry['options']     = is_array( $col['options'] ?? null ) ? $col['options'] : [];
                $entry['patch_path']  = (string) ( $col['patch_path']  ?? '' );
                $entry['patch_field'] = (string) ( $col['patch_field'] ?? $key );
            }
            $out[ (string) $key ] = $entry;
        }
        return $out;
    }

    /**
     * @param array<string,array<string,mixed>> $filters
     * @return array<string,array<string,mixed>>
     */
    private static function filtersForJs( array $filters ): array {
        $out = [];
        foreach ( $filters as $key => $f ) {
            $type = (string) ( $f['type'] ?? 'text' );
            $entry = [ 'type' => $type ];
            if ( $type === 'date_range' ) {
                $entry['param_from'] = (string) ( $f['param_from'] ?? ( $key . '_from' ) );
                $entry['param_to']   = (string) ( $f['param_to']   ?? ( $key . '_to' ) );
            }
            $out[ (string) $key ] = $entry;
        }
        return $out;
    }

    /**
     * @param array<string,array<string,mixed>> $actions
     * @return array<string,array<string,mixed>>
     */
    private static function rowActionsForJs( array $actions ): array {
        $out = [];
        foreach ( $actions as $key => $a ) {
            $entry = [
                'label' => (string) ( $a['label'] ?? $key ),
            ];
            if ( ! empty( $a['href'] ) ) $entry['href'] = (string) $a['href'];
            if ( ! empty( $a['rest_path'] ) ) {
                $entry['rest_path']   = (string) $a['rest_path'];
                $entry['rest_method'] = (string) ( $a['rest_method'] ?? 'POST' );
            }
            if ( ! empty( $a['confirm'] ) ) $entry['confirm'] = (string) $a['confirm'];
            if ( ! empty( $a['variant'] ) ) $entry['variant'] = (string) $a['variant'];
            $out[ (string) $key ] = $entry;
        }
        return $out;
    }
}
