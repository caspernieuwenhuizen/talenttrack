<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\GridLayout;
use TT\Modules\PersonaDashboard\Domain\PersonaTemplate;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;
use TT\Modules\PersonaDashboard\Frontend\GridRenderer;
use TT\Modules\PersonaDashboard\Registry\WidgetRegistry;
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
        if ( $this->registered !== [] ) {
            WidgetRegistry::clear();
            foreach ( $this->registered as $widget ) WidgetRegistry::register( $widget );
        }
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
        $this->registerStub( 'tt_test_untitled', '<p>Inhoud</p>' );
        $summaries = $this->summaries( [ new WidgetSlot( 'tt_test_untitled', '', 'L', 0, 0, 1, 25, true ) ] );
        $this->assertSame( [ 'Stub widget type' ], $summaries );
    }

    /** #4844 — a widget with nothing to show leaves no fold on a phone. */
    public function test_a_widget_that_renders_nothing_gets_no_fold(): void {
        $this->registerStub( 'tt_test_empty', '' );
        $this->registerStub( 'tt_test_full', '<p>Inhoud</p>' );

        $html = $this->grid( [
            new WidgetSlot( 'tt_test_empty', '', 'L', 0, 0, 1, 25, true, 'Leeg' ),
            new WidgetSlot( 'tt_test_full', '', 'L', 0, 1, 1, 25, true, 'Vol' ),
        ] );

        $this->assertSame( 1, substr_count( $html, '<details class="tt-pd-fold"' ), 'only the widget with output folds' );
        $this->assertStringNotContainsString( '>Leeg</summary>', $html );
        $this->assertStringContainsString( '>Vol</summary>', $html );
    }

    public function test_an_unknown_data_table_preset_gets_no_fold(): void {
        $this->assertSame( '', ( new DataTableWidget() )->titleFor( new WidgetSlot( 'data_table', 'no_such_preset' ) ) );
        $this->assertSame( [], $this->summaries( [ $this->slot( 'no_such_preset', 0 ) ] ) );
    }

    /** @var array<string,\TT\Modules\PersonaDashboard\Domain\Widget> */
    private array $registered = [];

    private function registerStub( string $id, string $html ): void {
        if ( $this->registered === [] ) $this->registered = WidgetRegistry::all();
        WidgetRegistry::register( new class( $id, $html ) extends AbstractWidget {
            private string $stub_id;
            private string $html;
            public function __construct( string $id, string $html ) { $this->stub_id = $id; $this->html = $html; }
            public function id(): string { return $this->stub_id; }
            public function label(): string { return 'Stub widget type'; }
            public function render( WidgetSlot $slot, RenderContext $ctx ): string { return $this->html; }
        } );
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
        preg_match_all( '#<summary class="tt-pd-fold-summary">([^<]*)</summary>#', $this->grid( $slots ), $m );
        return array_map( 'html_entity_decode', $m[1] );
    }

    /** @param list<WidgetSlot> $slots */
    private function grid( array $slots ): string {
        $template = new PersonaTemplate( 'academy_admin', 1, null, null, new GridLayout( $slots ) );
        ob_start();
        GridRenderer::render( $template, $this->ctx() );
        return (string) ob_get_clean();
    }
}
