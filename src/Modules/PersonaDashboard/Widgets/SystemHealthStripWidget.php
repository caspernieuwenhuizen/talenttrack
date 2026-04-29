<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;

/**
 * SystemHealthStripWidget — Admin hero.
 *
 * Four panels: backup status, pending invitations, license tier,
 * modules summary. Sprint 1 ships the chrome; sprint 3 wires real
 * counts from the BackupModule + Invitations + License + ModuleRegistry.
 */
class SystemHealthStripWidget extends AbstractWidget {

    public function id(): string { return 'system_health_strip'; }

    public function label(): string { return __( 'System health strip', 'talenttrack' ); }

    public function defaultSize(): string { return Size::XL; }

    /** @return list<string> */
    public function allowedSizes(): array { return [ Size::XL ]; }

    public function defaultMobilePriority(): int { return 1; }

    public function capRequired(): string { return 'tt_edit_settings'; }

    public function render( WidgetSlot $slot, RenderContext $ctx ): string {
        $panels = [
            [ 'label' => __( 'Last backup', 'talenttrack' ),         'value' => '—', 'state' => 'ok' ],
            [ 'label' => __( 'Pending invitations', 'talenttrack' ), 'value' => '0', 'state' => 'ok' ],
            [ 'label' => __( 'License', 'talenttrack' ),             'value' => __( 'Pro', 'talenttrack' ), 'state' => 'info' ],
            [ 'label' => __( 'Modules active', 'talenttrack' ),      'value' => '—', 'state' => 'info' ],
        ];

        $cards = '';
        foreach ( $panels as $p ) {
            $cards .= '<div class="tt-pd-health-panel tt-pd-health-' . sanitize_html_class( $p['state'] ) . '">'
                . '<div class="tt-pd-health-label">' . esc_html( (string) $p['label'] ) . '</div>'
                . '<div class="tt-pd-health-value">' . esc_html( (string) $p['value'] ) . '</div>'
                . '</div>';
        }

        $inner = '<div class="tt-pd-hero-eyebrow">' . esc_html__( 'System health', 'talenttrack' ) . '</div>'
            . '<div class="tt-pd-health-row">' . $cards . '</div>';
        return $this->wrap( $slot, $inner, 'hero hero-system-health' );
    }
}
