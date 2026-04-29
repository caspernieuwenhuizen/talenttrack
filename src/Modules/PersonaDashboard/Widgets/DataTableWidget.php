<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;

/**
 * DataTableWidget — compact table with up to 5 rows + a "see all" link.
 *
 * data_source identifies the preset:
 *   "trials_needing_decision"  — HoD: open trial cases, max 5.
 *   "recent_scout_reports"     — Scout: my recent reports.
 *   "audit_log_recent"         — Admin: recent audit events.
 *
 * Sprint 1 ships the table chrome + empty-state per preset; sprint 3
 * wires the live queries.
 */
class DataTableWidget extends AbstractWidget {

    public function id(): string { return 'data_table'; }

    public function label(): string { return __( 'Data table', 'talenttrack' ); }

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

        $rows_html = '<tr><td colspan="' . count( $config['columns'] ) . '" class="tt-pd-table-empty">'
            . esc_html( (string) $config['empty_message'] )
            . '</td></tr>';

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
            'audit_log_recent' => [
                'title'         => __( 'Recent audit events', 'talenttrack' ),
                'columns'       => [ __( 'When', 'talenttrack' ), __( 'Who', 'talenttrack' ), __( 'What', 'talenttrack' ) ],
                'see_all_view'  => 'audit-log',
                'empty_message' => __( 'No audit events recorded yet.', 'talenttrack' ),
            ],
        ];
        return $presets[ $preset ] ?? null;
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
