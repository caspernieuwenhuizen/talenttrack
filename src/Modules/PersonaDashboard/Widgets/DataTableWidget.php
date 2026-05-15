<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;
use TT\Modules\PersonaDashboard\Registry\TableRowSourceRegistry;

/**
 * DataTableWidget — compact table with up to N rows + a "see all" link.
 *
 * data_source identifies the preset:
 *   "trials_needing_decision"  — HoD: open trial cases.
 *   "recent_scout_reports"     — Scout: my recent reports.
 *   "audit_log_recent"         — Admin: recent audit events.
 *   "upcoming_activities"      — HoD: forward schedule (rows wired in #0073).
 *
 * #0073 introduces `TableRowSourceRegistry`. When a source is registered
 * for the preset it provides real rows; presets without a registered
 * source render the empty-state row chrome (back-compat).
 */
class DataTableWidget extends AbstractWidget {

    public function id(): string { return 'data_table'; }

    public function label(): string { return __( 'Data table', 'talenttrack' ); }

    public function description(): string {
        return __( 'Compact tabular surface for "recent X" lists with a See-all link. Pick one of the registered presets in the data-source dropdown (upcoming activities, trials needing decision, my recent prospects, recent audit events, etc.). Rows come from a TableRowSource implementation scoped to the viewer + their club; See-all routes to the matching full list view.', 'talenttrack' );
    }

    /** @return list<string> */
    public function intendedPersonas(): array {
        return [ 'head_of_development', 'academy_admin', 'scout', 'head_coach', 'assistant_coach' ];
    }

    /** @return array<string,string> */
    public function dataSourceCatalogue(): array {
        return [
            'trials_needing_decision' => __( 'Trials needing decision', 'talenttrack' ),
            'recent_scout_reports'    => __( 'My recent scout reports', 'talenttrack' ),
            'my_recent_prospects'     => __( 'My recent prospects', 'talenttrack' ),
            'audit_log_recent'        => __( 'Recent audit events', 'talenttrack' ),
            'upcoming_activities'     => __( 'Upcoming activities', 'talenttrack' ),
            'goals_by_principle'      => __( 'Goals by principle', 'talenttrack' ),
        ];
    }

    public function defaultSize(): string { return Size::XL; }

    /** @return list<string> */
    public function allowedSizes(): array { return [ Size::L, Size::XL ]; }

    public function defaultMobilePriority(): int { return 25; }

    public function render( WidgetSlot $slot, RenderContext $ctx ): string {
        $preset = $slot->data_source;
        $config = $this->presetConfig( $preset );
        if ( $config === null ) return '';

        $title   = $slot->persona_label !== '' ? $slot->persona_label : (string) $config['title'];
        $see_all = $ctx->viewUrl( (string) $config['see_all_view'] );
        $head    = $this->renderHead( $config['columns'] );

        $rows_html = $this->rowsHtml( $preset, $ctx->user_id, $config );

        $inner = '<div class="tt-pd-panel-head">'
            . '<span class="tt-pd-panel-title">' . esc_html( $title ) . '</span>'
            . '<a class="tt-pd-panel-more" href="' . esc_url( $see_all ) . '">' . esc_html__( 'See all', 'talenttrack' ) . '</a>'
            . '</div>'
            . '<div class="tt-pd-table-wrap"><table class="tt-pd-table"><thead>' . $head . '</thead><tbody>' . $rows_html . '</tbody></table></div>';
        return $this->wrap( $slot, $inner, 'data-table' );
    }

    /** @return array<string,mixed>|null */
    private function presetConfig( string $preset ): ?array {
        $presets = [
            'trials_needing_decision' => [
                'title'         => __( 'Trials needing decision', 'talenttrack' ),
                'columns'       => [ __( 'Player', 'talenttrack' ), __( 'Team', 'talenttrack' ), __( 'Day', 'talenttrack' ), __( 'Coach', 'talenttrack' ), '' ],
                'see_all_view'  => 'trials',
                'empty_message' => __( 'No open trial cases.', 'talenttrack' ),
            ],
            'recent_scout_reports' => [
                'title'         => __( 'My recent reports', 'talenttrack' ),
                'columns'       => [ __( 'Date', 'talenttrack' ), __( 'Player', 'talenttrack' ), __( 'Status', 'talenttrack' ), '' ],
                'see_all_view'  => 'scout-history',
                'empty_message' => __( 'You have no scout reports yet.', 'talenttrack' ),
            ],
            // v3.110.78 — scout-persona "the prospects I just logged" table.
            // v3.110.99 — Show-all targets the new prospects-overview
            // (rich list with FrontendListTable filters), not the kanban.
            // Pilot feedback: kanban is grouped by stage, scouts wanted
            // a flat searchable list.
            'my_recent_prospects' => [
                'title'         => __( 'My recent prospects', 'talenttrack' ),
                'columns'       => [ __( 'Date', 'talenttrack' ), __( 'Name', 'talenttrack' ), __( 'Status', 'talenttrack' ), '' ],
                'see_all_view'  => 'prospects-overview',
                'empty_message' => __( 'You have not logged any prospects yet. Use the “+ New prospect” hero above to start.', 'talenttrack' ),
            ],
            'audit_log_recent' => [
                'title'         => __( 'Recent audit events', 'talenttrack' ),
                'columns'       => [ __( 'When', 'talenttrack' ), __( 'Who', 'talenttrack' ), __( 'What', 'talenttrack' ) ],
                'see_all_view'  => 'audit-log',
                'empty_message' => __( 'No audit events recorded yet.', 'talenttrack' ),
            ],
            'upcoming_activities' => [
                'title'         => __( 'Upcoming activities', 'talenttrack' ),
                'columns'       => [ __( 'Team', 'talenttrack' ), __( 'Type', 'talenttrack' ), __( 'Date & time', 'talenttrack' ), __( 'Location', 'talenttrack' ) ],
                'see_all_view'  => 'activities',
                'empty_message' => __( 'No upcoming activities in this window.', 'talenttrack' ),
            ],
            // #0077 M3 — methodology coverage. Lists each principle
            // with its active + completed goal counts.
            'goals_by_principle' => [
                'title'         => __( 'Goals by principle', 'talenttrack' ),
                'columns'       => [ __( 'Principle', 'talenttrack' ), __( 'Active', 'talenttrack' ), __( 'Completed', 'talenttrack' ), '' ],
                'see_all_view'  => 'goals',
                'empty_message' => __( 'No principles configured yet.', 'talenttrack' ),
            ],
        ];
        return $presets[ $preset ] ?? null;
    }

    /**
     * @param array<string,mixed> $config
     */
    private function rowsHtml( string $preset, int $user_id, array $config ): string {
        $col_count = count( $config['columns'] );
        $source    = TableRowSourceRegistry::resolve( $preset );
        if ( $source === null ) {
            return $this->emptyRow( $col_count, (string) $config['empty_message'] );
        }
        $rows = $source->rowsFor( $user_id, [ 'days' => 14, 'limit' => 15 ] );
        if ( $rows === [] ) {
            return $this->emptyRow( $col_count, (string) $config['empty_message'] );
        }
        $html = '';
        foreach ( $rows as $row ) {
            $cells = '';
            foreach ( $row as $cell ) {
                $cells .= '<td>' . $cell . '</td>';
            }
            $html .= '<tr>' . $cells . '</tr>';
        }
        return $html;
    }

    private function emptyRow( int $col_count, string $message ): string {
        return '<tr><td colspan="' . $col_count . '" class="tt-pd-table-empty">'
            . esc_html( $message )
            . '</td></tr>';
    }

    /** @param list<string> $cols */
    private function renderHead( array $cols ): string {
        $cells = '';
        foreach ( $cols as $c ) {
            $cells .= '<th>' . esc_html( $c ) . '</th>';
        }
        return '<tr>' . $cells . '</tr>';
    }
}
