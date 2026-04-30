<?php
namespace TT\Modules\Wizards\Team;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 3 — Roster.
 *
 * Optional checkbox list of unassigned + currently-other-team players.
 * The user's punch-list (#0063) called this out: "team creation
 * wizard should also have a step to assign players". The review
 * step turns the resulting list of player ids into a bulk
 * `tt_players.team_id` update after the team row is created.
 *
 * Skippable. A team without a roster is legitimate (academy admin
 * sets it up first, coaches drag players in later).
 */
final class RosterStep implements WizardStepInterface {

    public function slug(): string { return 'roster'; }
    public function label(): string { return __( 'Roster', 'talenttrack' ); }

    public function render( array $state ): void {
        global $wpdb;
        $players = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.id, p.first_name, p.last_name, p.team_id, t.name AS team_name
               FROM {$wpdb->prefix}tt_players p
               LEFT JOIN {$wpdb->prefix}tt_teams t ON p.team_id = t.id AND t.club_id = p.club_id
              WHERE p.club_id = %d
                AND p.archived_at IS NULL
                AND ( p.status IS NULL OR p.status = 'active' )
              ORDER BY ( p.team_id IS NULL ) DESC, p.last_name ASC, p.first_name ASC
              LIMIT 500",
            \TT\Infrastructure\Tenancy\CurrentClub::id()
        ) );

        $picked = isset( $state['roster_player_ids'] ) && is_array( $state['roster_player_ids'] )
            ? array_map( 'intval', $state['roster_player_ids'] )
            : [];

        ?>
        <p><?php esc_html_e( 'Optionally tick the players who should join the new team. Players currently assigned to another team will be moved on save. Skip the step if you want to assign players later.', 'talenttrack' ); ?></p>

        <?php if ( empty( $players ) ) : ?>
            <p><em><?php esc_html_e( 'No players in the system yet.', 'talenttrack' ); ?></em></p>
            <input type="hidden" name="roster_player_ids[]" value="" />
        <?php else : ?>
            <div style="max-height: 360px; overflow-y: auto; border: 1px solid #d6dadd; border-radius: 6px; padding: 8px 12px;">
                <?php foreach ( $players as $pl ) :
                    $name      = QueryHelpers::player_display_name( $pl );
                    $team_name = (string) ( $pl->team_name ?? '' );
                    $checked   = in_array( (int) $pl->id, $picked, true );
                    ?>
                    <label style="display:flex; align-items:center; gap:8px; padding:4px 0; min-height:32px;">
                        <input type="checkbox" name="roster_player_ids[]" value="<?php echo (int) $pl->id; ?>" <?php checked( $checked ); ?> />
                        <span><?php echo esc_html( $name ); ?></span>
                        <?php if ( $team_name !== '' ) : ?>
                            <small style="color:#5b6e75;"><?php
                                printf(
                                    /* translators: %s = current team name */
                                    esc_html__( '(currently in %s)', 'talenttrack' ),
                                    esc_html( $team_name )
                                );
                            ?></small>
                        <?php else : ?>
                            <small style="color:#5b6e75;"><?php esc_html_e( '(no team)', 'talenttrack' ); ?></small>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php
    }

    public function validate( array $post, array $state ) {
        $raw = $post['roster_player_ids'] ?? [];
        $ids = is_array( $raw ) ? array_values( array_unique( array_filter( array_map( 'intval', $raw ) ) ) ) : [];
        return [ 'roster_player_ids' => $ids ];
    }

    public function nextStep( array $state ): ?string { return 'spond-group'; }
    public function submit( array $state ) { return null; }
}
