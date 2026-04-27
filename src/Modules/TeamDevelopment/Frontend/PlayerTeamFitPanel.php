<?php
namespace TT\Modules\TeamDevelopment\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\TeamDevelopment\CompatibilityEngine;

/**
 * PlayerTeamFitPanel — sprint 5 of #0018.
 *
 * Renders a "Best fit positions" panel for a player. Hooks into the
 * existing player-profile / my-card flow via the
 * `tt_player_profile_extra_panels` filter, so this module can ship
 * the panel without touching the player profile view directly.
 *
 * Output is the top-3 slots ranked by fit score, with the rationale
 * line surfaced as a tooltip — same traceability principle as the
 * coach-side board.
 */
class PlayerTeamFitPanel {

    public static function init(): void {
        add_filter( 'tt_player_profile_extra_panels', [ __CLASS__, 'render' ], 10, 2 );
    }

    /**
     * @param array<int, string> $panels  HTML strings appended to profile.
     * @param object             $player  The tt_players row.
     * @return array<int, string>
     */
    public static function render( $panels, $player ): array {
        if ( ! is_array( $panels ) ) $panels = [];
        if ( ! is_object( $player ) ) return $panels;
        if ( ! current_user_can( 'tt_view_team_chemistry' ) ) return $panels;

        $player_id = (int) ( $player->id ?? 0 );
        if ( $player_id <= 0 ) return $panels;

        global $wpdb; $p = $wpdb->prefix;
        $template_id = 0;
        $team_id = (int) ( $player->team_id ?? 0 );
        if ( $team_id > 0 ) {
            $template_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT formation_template_id FROM {$p}tt_team_formations WHERE team_id = %d",
                $team_id
            ) );
        }
        if ( $template_id <= 0 ) {
            $template_id = (int) $wpdb->get_var(
                "SELECT id FROM {$p}tt_formation_templates WHERE is_seeded = 1 AND archived_at IS NULL ORDER BY id ASC LIMIT 1"
            );
        }
        if ( $template_id <= 0 ) return $panels;

        $engine = new CompatibilityEngine();
        $all = $engine->allSlotsFor( $player_id, $template_id );
        if ( empty( $all ) ) return $panels;

        $rows = [];
        foreach ( $all as $label => $result ) {
            $rows[] = [
                'label'     => $label,
                'score'     => $result->score,
                'rationale' => $result->rationale,
            ];
        }
        usort( $rows, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
        $top = array_slice( $rows, 0, 3 );

        ob_start();
        ?>
        <div class="tt-card" style="background:#fff; border:1px solid #e5e7ea; border-radius:8px; padding:14px 16px; margin-top:16px;">
            <h3 style="margin:0 0 8px; font-size:14px;"><?php esc_html_e( 'Best fit positions', 'talenttrack' ); ?></h3>
            <ol style="margin:0; padding-left:20px;">
                <?php foreach ( $top as $row ) : ?>
                    <li style="margin-bottom:4px;" title="<?php echo esc_attr( (string) $row['rationale'] ); ?>">
                        <strong><?php echo esc_html( (string) $row['label'] ); ?></strong>
                        <span style="color:#5b6e75; font-size:12px;">— <?php echo esc_html( number_format_i18n( (float) $row['score'], 2 ) ); ?> / 5</span>
                    </li>
                <?php endforeach; ?>
            </ol>
            <p style="margin:8px 0 0; color:#5b6e75; font-size:12px;">
                <?php esc_html_e( 'Hover an entry to see the underlying rating breakdown. Computed against the team\'s active formation template.', 'talenttrack' ); ?>
            </p>
        </div>
        <?php
        $panels[] = (string) ob_get_clean();
        return $panels;
    }
}
