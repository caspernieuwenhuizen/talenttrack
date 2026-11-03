<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\GridLayout;
use TT\Modules\PersonaDashboard\Domain\PersonaTemplate;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;
use TT\Modules\PersonaDashboard\Frontend\GridRenderer;
use TT\Modules\PersonaDashboard\Registry\KpiDataSourceRegistry;
use TT\Modules\PersonaDashboard\Registry\WidgetRegistry;

/**
 * #4827 — every dashboard widget with its own title says it through
 * titleFor(): the phone fold's summary and the widget's heading come from
 * the same place, and neither is the widget type's generic name.
 */
final class DashboardWidgetTitlesTest extends WP_UnitTestCase {

    private int $admin = 0;

    public function set_up(): void {
        parent::set_up();
        $roles = new \TT\Infrastructure\Security\RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        \TT\Modules\Authorization\Matrix\MatrixRepository::clearCache();
        \TT\Infrastructure\Security\AuthorizationService::flushCache();
        $this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /** @return array<string, array{string, string, string}> widget, data source, title */
    public function widgets(): array {
        return [
            'action card'         => [ 'action_card', 'new_player', 'Add player' ],
            'week recap'          => [ 'hod_week_recap', '', 'This week at the academy' ],
            'info card'           => [ 'info_card', 'next_activity', 'Up next' ],
            'mini player list'    => [ 'mini_player_list', 'recent_evaluations', 'Recent evaluations' ],
            'quick actions'       => [ 'quick_actions_panel', '', 'Quick actions' ],
            'recent comments'     => [ 'recent_comments', '', 'Recent comments & notes' ],
            'task list'           => [ 'task_list_panel', '', 'My tasks' ],
            'team overview'       => [ 'team_overview_grid', '', 'Team overview' ],
            'team roster, no team' => [ 'team_roster_table', '', 'Team roster' ],
        ];
    }

    /** @dataProvider widgets */
    public function test_the_fold_and_the_heading_share_the_widgets_title( string $widget_id, string $source, string $title ): void {
        $widget = $this->widget( $widget_id );
        $slot   = $this->slot( $widget_id, $source );

        $this->assertSame( $title, $widget->titleFor( $slot ) );

        $html = $widget->render( $slot, $this->ctx() );
        if ( $this->shownInGrid( $widget ) ) {
            // #4844 — a widget with nothing to show gets no fold at all.
            $this->assertSame( $html === '' ? [] : [ $title ], $this->summaries( [ $slot ] ) );
        }
        if ( $html !== '' ) {
            $this->assertStringContainsString( esc_html( $title ), $html, 'the heading reads the same title' );
        }
    }

    /** @dataProvider widgets */
    public function test_a_slot_label_wins( string $widget_id, string $source, string $title ): void {
        $slot   = $this->slot( $widget_id, $source, 'Eigen titel' );
        $widget = $this->widget( $widget_id );
        $this->assertSame( 'Eigen titel', $widget->titleFor( $slot ) );
        if ( $this->shownInGrid( $widget ) ) {
            $empty = $widget->render( $slot, $this->ctx() ) === '';
            $this->assertSame( $empty ? [] : [ 'Eigen titel' ], $this->summaries( [ $slot ] ) );
        }
    }

    /**
     * GridRenderer leaves out a widget whose capability the viewer lacks.
     * `recent_comments` asks `tt_view_threads`, which no role holds today,
     * so its fold is checked through titleFor() and its heading only.
     */
    private function shownInGrid( AbstractWidget $widget ): bool {
        $cap = $widget->capRequired();
        return $cap === '' || user_can( $this->admin, $cap );
    }

    public function test_the_team_roster_names_its_team(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Rostertitel', 'age_group' => 'O12' ] );
        $slot   = $this->slot( 'team_roster_table', 'team_id=' . (int) $wpdb->insert_id . ',days=30' );
        $widget = $this->widget( 'team_roster_table' );

        $this->assertSame( 'Rostertitel (O12) roster', $widget->titleFor( $slot ) );
        $this->assertSame( [ 'Rostertitel (O12) roster' ], $this->summaries( [ $slot ] ) );
        $this->assertStringContainsString( 'Rostertitel (O12) roster', $widget->render( $slot, $this->ctx() ) );
    }

    public function test_a_kpi_card_is_titled_by_its_kpi(): void {
        $sources = KpiDataSourceRegistry::all();
        $this->assertNotEmpty( $sources, 'precondition: KPIs are registered' );
        $source = reset( $sources );
        $slot   = $this->slot( 'kpi_card', $source->id() );
        $widget = $this->widget( 'kpi_card' );

        $this->assertSame( $source->label(), $widget->titleFor( $slot ) );
        $this->assertSame( [ $source->label() ], $this->summaries( [ $slot ] ) );
        $this->assertStringContainsString( esc_html( $source->label() ), $widget->render( $slot, $this->ctx() ) );
    }

    public function test_a_navigation_tile_is_titled_by_its_tile(): void {
        $widget = $this->widget( 'navigation_tile' );
        $slot   = $this->slot( 'navigation_tile', 'players' );
        $title  = $widget->titleFor( $slot );

        $this->assertNotSame( '', $title, 'precondition: the admin has the players tile' );
        $this->assertStringContainsString( esc_html( $title ), $widget->render( $slot, $this->ctx() ) );
    }

    /** An unknown preset has no title and renders nothing, so it gets no fold (#4844). */
    public function test_an_unknown_preset_has_no_title_and_no_fold(): void {
        foreach ( [ 'info_card', 'mini_player_list', 'action_card' ] as $widget_id ) {
            $widget = $this->widget( $widget_id );
            $this->assertSame( '', $widget->titleFor( $this->slot( $widget_id, 'no_such_preset' ) ), $widget_id );
            $this->assertSame( [], $this->summaries( [ $this->slot( $widget_id, 'no_such_preset' ) ] ), $widget_id );
        }
    }

    private function widget( string $id ): AbstractWidget {
        $widget = WidgetRegistry::get( $id );
        $this->assertInstanceOf( AbstractWidget::class, $widget, $id . ' is registered' );
        return $widget;
    }

    private function slot( string $widget_id, string $source, string $label = '' ): WidgetSlot {
        return new WidgetSlot( $widget_id, $source, 'L', 0, 0, 1, 25, true, $label );
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
        return array_map( static fn( $s ) => html_entity_decode( $s, ENT_QUOTES ), $m[1] );
    }
}
