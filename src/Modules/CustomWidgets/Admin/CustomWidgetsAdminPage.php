<?php
namespace TT\Modules\CustomWidgets\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\CustomWidgets\Cache\CustomWidgetCache;
use TT\Modules\CustomWidgets\CustomDataSourceRegistry;
use TT\Modules\CustomWidgets\CustomWidgetService;
use TT\Modules\CustomWidgets\Domain\CustomWidget;

/**
 * CustomWidgetsAdminPage (#0078 Phase 3) — TalentTrack → Custom widgets.
 *
 * Two views inside the same wp-admin slug:
 *
 *   1. **List view** (default) — every saved widget for the current
 *      club, with edit / archive / preview buttons.
 *   2. **Builder view** (`?action=new` or `?action=edit&id=N`) — the
 *      multi-step authoring UX:
 *
 *           [ 1. Source ] → [ 2. Columns ] → [ 3. Filters ]
 *                        → [ 4. Format ] → [ 5. Preview ] → [ 6. Save ]
 *
 * Server-rendered shell + small `assets/js/custom-widgets-builder.js`
 * progressive-enhancement layer. The JS handles step navigation,
 * dynamic column / filter / aggregation rendering as the operator
 * picks a source, and the live preview that hits the Phase 2 REST
 * endpoint `/wp-json/talenttrack/v1/custom-widgets/{uuid}/data` after
 * the draft is saved.
 *
 * Cap-gated on `tt_edit_persona_templates` for Phase 3; Phase 5 swaps
 * for the dedicated `tt_author_custom_widgets` cap.
 */
final class CustomWidgetsAdminPage {

    public const SLUG = 'tt-custom-widgets';

    public static function render(): void {
        if ( ! self::canManage() ) {
            wp_die( esc_html__( 'You do not have permission to manage custom widgets.', 'talenttrack' ) );
        }

        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
        if ( $action === 'new' || $action === 'edit' ) {
            self::renderBuilder();
            return;
        }
        self::renderList();
    }

    private static function renderList(): void {
        $service = new CustomWidgetService();
        $widgets = $service->listAll( false );

        echo '<div class="wrap tt-cw-list">';
        echo '<h1 class="wp-heading-inline">' . esc_html__( 'Custom widgets', 'talenttrack' ) . '</h1>';
        echo ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&action=new' ) ) . '" class="page-title-action">'
            . esc_html__( 'New custom widget', 'talenttrack' ) . '</a>';
        echo '<hr class="wp-header-end">';

        if ( empty( $widgets ) ) {
            echo '<div class="notice notice-info inline" style="margin-top:16px;"><p>'
                . esc_html__( 'No custom widgets yet. Build one to surface it on a persona dashboard.', 'talenttrack' )
                . '</p></div>';
            self::renderHelpBox();
            echo '</div>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped" style="margin-top:16px;">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Name', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Data source', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Chart type', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Updated', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Actions', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $widgets as $w ) {
            $source       = CustomDataSourceRegistry::find( $w->dataSourceId );
            $source_label = $source ? $source->label() : $w->dataSourceId;
            $edit_url     = admin_url( 'admin.php?page=' . self::SLUG . '&action=edit&id=' . $w->id );
            $archive_url  = wp_nonce_url(
                admin_url( 'admin-post.php?action=tt_custom_widget_archive&id=' . $w->id ),
                'tt_custom_widget_archive_' . $w->id
            );

            echo '<tr>';
            echo '<td><strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( $w->name ) . '</a></strong>';
            echo '<div style="font-size:11px; color:#5b6e75; font-family:monospace;">' . esc_html( $w->uuid ) . '</div></td>';
            echo '<td>' . esc_html( $source_label ) . '</td>';
            echo '<td>' . esc_html( self::chartTypeLabel( $w->chartType ) ) . '</td>';
            echo '<td>' . esc_html( $w->updatedAt ?? $w->createdAt ) . '</td>';
            echo '<td>';
            $flush_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=tt_custom_widget_clear_cache&id=' . $w->id ),
                'tt_custom_widget_clear_cache_' . $w->id
            );
            echo '<a href="' . esc_url( $edit_url ) . '" class="button">' . esc_html__( 'Edit', 'talenttrack' ) . '</a> ';
            echo '<a href="' . esc_url( $flush_url ) . '" class="button">'
                . esc_html__( 'Clear cache', 'talenttrack' ) . '</a> ';
            echo '<a href="' . esc_url( $archive_url ) . '" class="button button-link-delete" '
                . 'onclick="return confirm(' . esc_attr( wp_json_encode( __( 'Archive this widget? It will disappear from dashboards.', 'talenttrack' ) ) ) . ');">'
                . esc_html__( 'Archive', 'talenttrack' ) . '</a>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        self::renderHelpBox();
        echo '</div>';
    }

    private static function renderBuilder(): void {
        $service = new CustomWidgetService();
        $is_edit = isset( $_GET['action'] ) && $_GET['action'] === 'edit';
        $widget  = null;
        if ( $is_edit && isset( $_GET['id'] ) ) {
            $widget = $service->findByIdOrUuid( sanitize_key( (string) $_GET['id'] ) );
            if ( $widget === null ) {
                wp_die( esc_html__( 'Custom widget not found.', 'talenttrack' ) );
            }
        }

        // Catalogue of sources sent into JS so the builder doesn't need
        // a round-trip on first paint. Each source ships its full
        // columns / filters / aggregations metadata so the builder UI
        // re-renders as the operator picks one.
        $sources = [];
        foreach ( CustomDataSourceRegistry::all() as $id => $src ) {
            $sources[] = [
                'id'           => $id,
                'label'        => $src->label(),
                'columns'      => $src->columns(),
                'filters'      => $src->filters(),
                'aggregations' => $src->aggregations(),
            ];
        }

        $bootstrap = [
            'restRoot'    => esc_url_raw( rest_url( 'talenttrack/v1' ) ),
            'restNonce'   => wp_create_nonce( 'wp_rest' ),
            'listUrl'     => admin_url( 'admin.php?page=' . self::SLUG ),
            'sources'     => $sources,
            'widget'      => $widget ? $widget->toArray() : null,
            'chartTypes'  => [
                CustomWidget::CHART_TABLE => __( 'Table', 'talenttrack' ),
                CustomWidget::CHART_KPI   => __( 'KPI', 'talenttrack' ),
                CustomWidget::CHART_BAR   => __( 'Bar chart', 'talenttrack' ),
                CustomWidget::CHART_LINE  => __( 'Line chart', 'talenttrack' ),
            ],
            'i18n'        => [
                'pickSource'      => __( 'Pick a data source', 'talenttrack' ),
                'pickColumns'     => __( 'Pick columns to show', 'talenttrack' ),
                'configureFilters'=> __( 'Configure filters', 'talenttrack' ),
                'pickFormat'      => __( 'Choose chart type', 'talenttrack' ),
                'preview'         => __( 'Preview', 'talenttrack' ),
                'name'            => __( 'Name and save', 'talenttrack' ),
                'next'            => __( 'Next', 'talenttrack' ),
                'back'            => __( 'Back', 'talenttrack' ),
                'save'            => __( 'Save widget', 'talenttrack' ),
                'saving'          => __( 'Saving…', 'talenttrack' ),
                'saved'           => __( 'Widget saved.', 'talenttrack' ),
                'saveFailed'      => __( 'Save failed:', 'talenttrack' ),
                'previewLoading'  => __( 'Loading preview…', 'talenttrack' ),
                'previewFailed'   => __( 'Could not load preview.', 'talenttrack' ),
                'noRows'          => __( 'No rows to show with the current filters.', 'talenttrack' ),
                'requireName'     => __( 'Give the widget a name before saving.', 'talenttrack' ),
                'requireSource'   => __( 'Pick a data source first.', 'talenttrack' ),
                'requireFormat'   => __( 'Pick a chart type first.', 'talenttrack' ),
                'requireColumns'  => __( 'Pick at least one column for a table widget.', 'talenttrack' ),
                'requireAgg'      => __( 'KPI / bar / line widgets need an aggregation.', 'talenttrack' ),
                'cacheTtl'        => __( 'Cache TTL (minutes)', 'talenttrack' ),
                'aggregation'     => __( 'Aggregation', 'talenttrack' ),
                'noAggregation'   => __( 'No aggregations available for this source.', 'talenttrack' ),
            ],
        ];

        echo '<div class="wrap tt-cw-builder" data-tt-cw-builder>';
        echo '<h1>' . esc_html( $is_edit
            ? sprintf( __( 'Edit custom widget — %s', 'talenttrack' ), $widget->name )
            : __( 'New custom widget', 'talenttrack' )
        ) . '</h1>';
        echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">'
            . esc_html__( '← Back to custom widgets', 'talenttrack' )
            . '</a></p>';

        // Steps shell — the JS wires step transitions and form rendering.
        echo '<ol class="tt-cw-stepper" data-tt-cw="stepper" aria-label="' . esc_attr__( 'Builder steps', 'talenttrack' ) . '">';
        echo '<li class="is-active" data-step="source">' . esc_html__( '1. Source', 'talenttrack' ) . '</li>';
        echo '<li data-step="columns">' . esc_html__( '2. Columns', 'talenttrack' ) . '</li>';
        echo '<li data-step="filters">' . esc_html__( '3. Filters', 'talenttrack' ) . '</li>';
        echo '<li data-step="format">' . esc_html__( '4. Format', 'talenttrack' ) . '</li>';
        echo '<li data-step="preview">' . esc_html__( '5. Preview', 'talenttrack' ) . '</li>';
        echo '<li data-step="save">' . esc_html__( '6. Save', 'talenttrack' ) . '</li>';
        echo '</ol>';

        echo '<div class="tt-cw-step-body" data-tt-cw="body"></div>';

        echo '<div class="tt-cw-actions">';
        echo '<button type="button" class="button" data-tt-cw="prev" disabled>' . esc_html__( '← Back', 'talenttrack' ) . '</button> ';
        echo '<button type="button" class="button button-primary" data-tt-cw="next">' . esc_html__( 'Next →', 'talenttrack' ) . '</button>';
        echo '</div>';

        echo '<div class="tt-cw-status" data-tt-cw="status" aria-live="polite"></div>';

        echo '</div>';

        wp_localize_script( 'tt-custom-widgets-builder', 'TTCustomWidgetsBootstrap', $bootstrap );
    }

    private static function renderHelpBox(): void {
        echo '<div style="margin-top:24px; padding:14px 16px; background:#f0f6fc; border-left:4px solid #2271b1; max-width:760px;">';
        echo '<p style="margin:0 0 6px 0;"><strong>' . esc_html__( 'How custom widgets work', 'talenttrack' ) . '</strong></p>';
        echo '<p style="margin:0;">' . esc_html__(
            'Pick a data source (Players, Evaluations, Goals, Activities, PDP), choose columns and filters, and pick a chart type. Save the widget and drop it onto any persona dashboard from the editor palette. Each widget is club-scoped and respects the viewer\'s read access on the underlying records.',
            'talenttrack'
        ) . '</p>';
        echo '</div>';
    }

    public static function enqueueAssets( string $hook ): void {
        if ( strpos( $hook, self::SLUG ) === false ) return;

        wp_enqueue_style(
            'tt-custom-widgets-builder',
            TT_PLUGIN_URL . 'assets/css/custom-widgets-builder.css',
            [],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-custom-widgets-builder',
            TT_PLUGIN_URL . 'assets/js/custom-widgets-builder.js',
            [],
            TT_VERSION,
            true
        );
    }

    /**
     * `admin-post.php?action=tt_custom_widget_archive&id=N` — archive
     * action behind a per-row nonce. Phase 5 routes this through the
     * audit log + cache flush; Phase 3 keeps it simple.
     */
    /**
     * `admin-post.php?action=tt_custom_widget_clear_cache&id=N` —
     * manual cache flush behind a per-row nonce. Bumps the per-uuid
     * version counter; cached rows orphan immediately.
     */
    public static function handleClearCache(): void {
        if ( ! self::canManage() ) {
            wp_die( esc_html__( 'You do not have permission to clear the cache.', 'talenttrack' ), '', [ 'response' => 403 ] );
        }
        $id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
        if ( $id <= 0 ) wp_die( esc_html__( 'Bad widget id.', 'talenttrack' ), '', [ 'response' => 400 ] );
        check_admin_referer( 'tt_custom_widget_clear_cache_' . $id );

        $widget = ( new CustomWidgetService() )->findByIdOrUuid( (string) $id );
        if ( $widget !== null ) {
            CustomWidgetCache::flush( $widget->uuid );
            do_action( 'tt_custom_widget_cache_flush_requested', $widget );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tt_msg=cache_cleared' ) );
        exit;
    }

    /**
     * Cap check used across the admin page entry points. Phase 5
     * introduced `tt_author_custom_widgets`; keeps the legacy
     * `tt_edit_persona_templates` as a back-compat fallthrough so
     * upgrades that haven't run the seed top-up migration yet stay
     * functional for one release window.
     */
    private static function canManage(): bool {
        return current_user_can( 'tt_author_custom_widgets' )
            || current_user_can( 'tt_edit_persona_templates' );
    }

    public static function handleArchive(): void {
        if ( ! self::canManage() ) {
            wp_die( esc_html__( 'You do not have permission to archive custom widgets.', 'talenttrack' ), '', [ 'response' => 403 ] );
        }
        $id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
        if ( $id <= 0 ) wp_die( esc_html__( 'Bad widget id.', 'talenttrack' ), '', [ 'response' => 400 ] );
        check_admin_referer( 'tt_custom_widget_archive_' . $id );

        $service = new CustomWidgetService();
        try {
            $service->archive( $id, get_current_user_id() );
        } catch ( \Throwable $e ) {
            wp_die( esc_html( $e->getMessage() ) );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tt_msg=archived' ) );
        exit;
    }

    private static function chartTypeLabel( string $type ): string {
        $labels = [
            CustomWidget::CHART_TABLE => __( 'Table', 'talenttrack' ),
            CustomWidget::CHART_KPI   => __( 'KPI', 'talenttrack' ),
            CustomWidget::CHART_BAR   => __( 'Bar chart', 'talenttrack' ),
            CustomWidget::CHART_LINE  => __( 'Line chart', 'talenttrack' ),
        ];
        return $labels[ $type ] ?? $type;
    }
}
