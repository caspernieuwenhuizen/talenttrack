<?php
namespace TT\Infrastructure\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Renders the module-completeness report (#0077 F4) as an admin
 * submenu under TalentTrack. Registers only when WP_DEBUG is true so
 * production installs never see it.
 */
final class ModuleCompletenessPage {

    public static function init(): void {
        if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) return;
        add_action( 'admin_menu', [ self::class, 'register' ], 25 );
    }

    public static function register(): void {
        add_submenu_page(
            'talenttrack',
            __( 'Module completeness (dev)', 'talenttrack' ),
            __( 'Module completeness', 'talenttrack' ),
            'manage_options',
            'tt-module-completeness',
            [ self::class, 'render' ]
        );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'talenttrack' ) );
        }
        $rows = ModuleCompletenessReport::report();
        $facets = [ 'list', 'detail', 'edit', 'widget', 'kpi', 'docs_en', 'docs_nl' ];
        $facet_labels = [
            'list'    => __( 'List', 'talenttrack' ),
            'detail'  => __( 'Detail', 'talenttrack' ),
            'edit'    => __( 'Edit', 'talenttrack' ),
            'widget'  => __( 'Widget', 'talenttrack' ),
            'kpi'     => __( 'KPI', 'talenttrack' ),
            'docs_en' => __( 'Docs (en)', 'talenttrack' ),
            'docs_nl' => __( 'Docs (nl)', 'talenttrack' ),
        ];
        echo '<div class="wrap"><h1>' . esc_html__( 'Module completeness (dev)', 'talenttrack' ) . '</h1>';
        echo '<p>' . esc_html__( 'Filesystem-heuristic check that each entity-bearing module ships the full surface set: list, detail, edit, widget, KPI, docs (en+nl). Visible only when WP_DEBUG is true.', 'talenttrack' ) . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Module', 'talenttrack' ) . '</th>';
        foreach ( $facets as $f ) {
            echo '<th>' . esc_html( $facet_labels[ $f ] ) . '</th>';
        }
        echo '<th>' . esc_html__( 'Score', 'talenttrack' ) . '</th></tr></thead><tbody>';
        foreach ( $rows as $row ) {
            echo '<tr><td><strong>' . esc_html( $row['name'] ) . '</strong></td>';
            foreach ( $facets as $f ) {
                $ok = ! empty( $row['facets'][ $f ] );
                $icon = $ok ? '✓' : '·';
                $color = $ok ? '#137333' : '#b32d2e';
                echo '<td style="color:' . esc_attr( $color ) . '; font-weight:600; text-align:center;">' . esc_html( $icon ) . '</td>';
            }
            $total = count( $facets );
            echo '<td>' . esc_html( $row['score'] ) . ' / ' . esc_html( (string) $total ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
