<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\PersonaDashboard\Domain\GridLayout;
use TT\Modules\PersonaDashboard\Domain\PersonaTemplate;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;
use TT\Modules\PersonaDashboard\Frontend\GridRenderer;
use TT\Modules\PersonaDashboard\Widgets\DataTableWidget;

/**
 * #4818 — a dashboard widget folded on a phone says what is inside it: the
 * fold's summary is the widget's own title, not the widget type's name.
 */
final class DashboardFoldTitleTest extends WP_UnitTestCase {

    private int $admin = 0;

    public function set_up(): void {
        parent::set_up();
        $this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_folded_data_table_is_summarised_by_its_title(): void {
        $summaries = $this->summaries( [
            $this->slot( 'behaviour_pending', 0 ),
            $this->slot( 'my_recent_prospects', 1 ),
        ] );

        $this->assertSame( [ 'Behaviour pending', 'My recent prospects' ], $summaries );
        $this->assertNotContains( ( new DataTableWidget() )->label(), $summaries );
    }

    public function test_a_slot_label_wins_over_the_preset_title(): void {
        $summaries = $this->summaries( [ $this->slot( 'behaviour_pending', 0, 'Wie mist een gedragsscore' ) ] );
        $this->assertSame( [ 'Wie mist een gedragsscore' ], $summaries );
    }

    public function test_the_type_name_is_the_fallback_when_there_is_no_title(): void {
        $this->assertSame( '', ( new DataTableWidget() )->titleFor( new WidgetSlot( 'data_table', 'no_such_preset' ) ) );
        $summaries = $this->summaries( [ $this->slot( 'no_such_preset', 0 ) ] );
        $this->assertSame( [ ( new DataTableWidget() )->label() ], $summaries );
    }

    public function test_the_title_matches_the_widget_heading(): void {
        $slot = $this->slot( 'behaviour_pending', 0 );
        $html = ( new DataTableWidget() )->render( $slot, $this->ctx() );
        $this->assertStringContainsString(
            '<span class="tt-pd-panel-title">' . esc_html( ( new DataTableWidget() )->titleFor( $slot ) ) . '</span>',
            $html
        );
    }

    private function slot( string $preset, int $y, string $label = '' ): WidgetSlot {
        return new WidgetSlot( 'data_table', $preset, 'XL', 0, $y, 1, 25, true, $label );
    }

    private function ctx(): RenderContext {
        return new RenderContext( $this->admin, 1, 'academy_admin', home_url( '/' ) );
    }

    /**
     * @param list<WidgetSlot> $slots
     * @return list<string>
     */
    private function summaries( array $slots ): array {
        $template = new PersonaTemplate( 'academy_admin', 1, null, null, new GridLayout( $slots ) );
        ob_start();
        GridRenderer::render( $template, $this->ctx() );
        $html = (string) ob_get_clean();
        preg_match_all( '#<summary class="tt-pd-fold-summary">([^<]*)</summary>#', $html, $m );
        return array_map( 'html_entity_decode', $m[1] );
    }
}
