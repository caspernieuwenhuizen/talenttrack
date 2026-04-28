<?php
namespace TT\Modules\Reports\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendScoutAccessView — HoD UI for assigning players to scout users.
 *
 * #0014 Sprint 5. Lists every user with the `tt_scout` role and the
 * players each is currently allowed to view. Assignments live in
 * user-meta (key `tt_scout_player_ids`, JSON-encoded array of int).
 * Adding/removing is a simple POST → meta update.
 */
class FrontendScoutAccessView extends FrontendViewBase {

    private const META_KEY = 'tt_scout_player_ids';

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();
        self::renderHeader( __( 'Scout access', 'talenttrack' ) );

        if ( ! current_user_can( 'tt_generate_scout_report' ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'You need scout-management permission to view this page.', 'talenttrack' ) . '</p>';
            return;
        }

        // Handle assignment add/remove POSTs.
        if ( ! empty( $_POST['tt_scout_action'] ) && check_admin_referer( 'tt_scout_assign', 'tt_scout_nonce' ) ) {
            $scout_id  = absint( $_POST['scout_user_id'] ?? 0 );
            $player_id = absint( $_POST['player_id'] ?? 0 );
            $action    = sanitize_key( (string) $_POST['tt_scout_action'] );
            if ( $scout_id > 0 && $player_id > 0 && in_array( $action, [ 'assign', 'unassign' ], true ) ) {
                self::updateAssignment( $scout_id, $player_id, $action === 'assign' );
                $msg = $action === 'assign'
                    ? __( 'Player assigned.', 'talenttrack' )
                    : __( 'Assignment removed.', 'talenttrack' );
                echo '<p class="tt-notice notice-success" style="background:#e9f5e9;border-left:4px solid #2c8a2c;padding:8px 12px;margin:8px 0 16px;">'
                    . esc_html( $msg ) . '</p>';
            }
        }

        $scouts  = get_users( [ 'role__in' => [ 'tt_scout' ], 'orderby' => 'display_name', 'order' => 'ASC' ] );
        $players = QueryHelpers::get_players();

        ?>
        <p class="tt-help-text"><?php esc_html_e( 'Assign specific players to a scout user. The scout sees only the players you assign here, on demand.', 'talenttrack' ); ?></p>
        <?php if ( empty( $scouts ) ) : ?>
            <p class="tt-notice"><?php esc_html_e( 'No scout users yet. Create a WordPress user and assign them the Scout role to get started.', 'talenttrack' ); ?></p>
        <?php else : ?>
            <?php foreach ( $scouts as $scout ) :
                $assigned_ids = self::assignedPlayerIds( (int) $scout->ID );
                ?>
                <div class="tt-scout-card" style="border:1px solid #e5e7ea;border-radius:10px;padding:16px 20px;margin-bottom:14px;background:#fff;">
                    <h3 style="margin:0 0 4px;font-size:15px;"><?php echo esc_html( (string) $scout->display_name ); ?></h3>
                    <p style="margin:0 0 10px;font-size:12px;color:#5b6470;"><?php echo esc_html( (string) $scout->user_email ); ?></p>

                    <?php if ( empty( $assigned_ids ) ) : ?>
                        <p style="margin:0 0 10px;font-size:13px;color:#5b6470;font-style:italic;">
                            <?php esc_html_e( 'No players assigned yet.', 'talenttrack' ); ?>
                        </p>
                    <?php else : ?>
                        <ul style="list-style:none;padding:0;margin:0 0 10px;">
                            <?php foreach ( $assigned_ids as $pid ) :
                                $pl = QueryHelpers::get_player( $pid );
                                if ( ! $pl ) continue;
                                ?>
                                <li style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid #f0f0f0;">
                                    <span><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></span>
                                    <form method="post" style="margin:0;">
                                        <?php wp_nonce_field( 'tt_scout_assign', 'tt_scout_nonce' ); ?>
                                        <input type="hidden" name="scout_user_id" value="<?php echo (int) $scout->ID; ?>" />
                                        <input type="hidden" name="player_id" value="<?php echo (int) $pl->id; ?>" />
                                        <input type="hidden" name="tt_scout_action" value="unassign" />
                                        <button type="submit" class="tt-btn tt-btn-secondary" style="font-size:12px;color:#b32d2e;"><?php esc_html_e( 'Remove', 'talenttrack' ); ?></button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <form method="post" style="display:flex;gap:8px;margin-top:8px;">
                        <?php wp_nonce_field( 'tt_scout_assign', 'tt_scout_nonce' ); ?>
                        <input type="hidden" name="scout_user_id" value="<?php echo (int) $scout->ID; ?>" />
                        <input type="hidden" name="tt_scout_action" value="assign" />
                        <select name="player_id" required style="flex:1;padding:6px 8px;border:1px solid #c3c4c7;border-radius:4px;">
                            <option value=""><?php esc_html_e( '— Pick a player to assign —', 'talenttrack' ); ?></option>
                            <?php foreach ( $players as $pl ) :
                                if ( in_array( (int) $pl->id, $assigned_ids, true ) ) continue;
                                ?>
                                <option value="<?php echo (int) $pl->id; ?>"><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="tt-btn tt-btn-primary"><?php esc_html_e( 'Assign', 'talenttrack' ); ?></button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php
    }

    /**
     * @return int[]
     */
    public static function assignedPlayerIds( int $scout_user_id ): array {
        $raw = get_user_meta( $scout_user_id, self::META_KEY, true );
        if ( ! is_string( $raw ) || $raw === '' ) return [];
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) return [];
        $ids = array_map( 'intval', $decoded );
        return array_values( array_unique( array_filter( $ids, static fn( $i ) => $i > 0 ) ) );
    }

    private static function updateAssignment( int $scout_user_id, int $player_id, bool $assign ): void {
        $current = self::assignedPlayerIds( $scout_user_id );
        if ( $assign ) {
            if ( ! in_array( $player_id, $current, true ) ) {
                $current[] = $player_id;
            }
        } else {
            $current = array_values( array_filter( $current, static fn( $i ) => $i !== $player_id ) );
        }
        update_user_meta( $scout_user_id, self::META_KEY, (string) wp_json_encode( $current ) );
    }
}
