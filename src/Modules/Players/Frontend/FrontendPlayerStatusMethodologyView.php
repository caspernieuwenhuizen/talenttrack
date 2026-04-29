<?php
namespace TT\Modules\Players\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\PlayerStatus\MethodologyResolver;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * FrontendPlayerStatusMethodologyView (#0057 Sprint 3) — admin UI for
 * the per-age-group player-status methodology config.
 *
 *   ?tt_view=player-status-methodology
 *
 * Per age group, configure: which inputs are included (ratings /
 * behaviour / attendance / potential), per-input weight (must sum to
 * 100), amber + red thresholds, and the optional behaviour-floor rule.
 *
 * The default-row form (age_group_id = 0) is shown first, followed by
 * a per-age-group row. Saving an age-group row creates an explicit
 * override; deleting it falls back to the club-wide default. The
 * shipped default in MethodologyResolver applies when a club hasn't
 * touched the page yet.
 *
 * Cap-gated on `tt_edit_settings` — HoD or admin.
 */
final class FrontendPlayerStatusMethodologyView {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_edit_settings' ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to edit the player-status methodology.', 'talenttrack' ) . '</p>';
            return;
        }

        $saved = self::handlePost();
        if ( $saved ) {
            echo '<div class="tt-notice tt-notice-success" style="background:#dcfce7;color:#166534;padding:10px 14px;border-radius:6px;margin-bottom:16px;">'
                . esc_html__( 'Saved.', 'talenttrack' ) . '</div>';
        }

        echo '<section style="max-width:900px;">';
        echo '<h2 style="margin:0 0 12px;">' . esc_html__( 'Player status methodology', 'talenttrack' ) . '</h2>';
        echo '<p style="margin:0 0 16px;color:#5b6e75;">' . esc_html__( 'Configure how the traffic-light status is calculated. The shipped default (40% ratings / 25% behaviour / 20% attendance / 15% potential, amber below 60, red below 40, behaviour floor at 3.0) applies until you save an override.', 'talenttrack' ) . '</p>';

        // Render the club-wide default form, then per-age-group forms.
        $age_groups = QueryHelpers::get_lookups( 'age_group' );

        self::renderForm( 0, __( 'Club-wide default', 'talenttrack' ) );
        foreach ( $age_groups as $ag ) {
            $label = sprintf( __( 'Age group: %s', 'talenttrack' ), (string) $ag->name );
            self::renderForm( (int) $ag->id, $label );
        }

        echo '</section>';
    }

    private static function handlePost(): bool {
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) return false;
        if ( ! isset( $_POST['tt_psm_nonce'] ) ) return false;
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tt_psm_nonce'] ) ), 'tt_psm_save' ) ) return false;
        if ( ! current_user_can( 'tt_edit_settings' ) ) return false;

        $age_group_id = isset( $_POST['age_group_id'] ) ? absint( $_POST['age_group_id'] ) : 0;
        $reset        = isset( $_POST['reset'] );

        global $wpdb;
        $table = $wpdb->prefix . 'tt_player_status_methodology';

        if ( $reset ) {
            $wpdb->delete( $table, [ 'club_id' => CurrentClub::id(), 'age_group_id' => $age_group_id ] );
            return true;
        }

        $config = self::extractConfig( $_POST );
        $payload = [
            'club_id'      => CurrentClub::id(),
            'age_group_id' => $age_group_id,
            'config_json'  => (string) wp_json_encode( $config ),
            'updated_at'   => current_time( 'mysql' ),
            'updated_by'   => get_current_user_id(),
        ];

        $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE club_id = %d AND age_group_id = %d LIMIT 1",
            CurrentClub::id(), $age_group_id
        ) );
        if ( $existing_id > 0 ) {
            $wpdb->update( $table, $payload, [ 'id' => $existing_id ] );
        } else {
            $wpdb->insert( $table, $payload );
        }
        return true;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    private static function extractConfig( array $post ): array {
        $inputs = [];
        $total_weight = 0;
        foreach ( [ 'ratings', 'behaviour', 'attendance', 'potential' ] as $key ) {
            $enabled = ! empty( $post[ "input_{$key}_enabled" ] );
            $weight  = isset( $post[ "input_{$key}_weight" ] ) ? max( 0, min( 100, (int) $post[ "input_{$key}_weight" ] ) ) : 0;
            if ( ! $enabled ) $weight = 0;
            $inputs[ $key ] = [ 'enabled' => $enabled, 'weight' => $weight ];
            $total_weight += $weight;
        }
        // Normalise weights to sum to 100 if user submitted unbalanced values.
        if ( $total_weight > 0 && $total_weight !== 100 ) {
            foreach ( $inputs as $k => $row ) {
                if ( $row['enabled'] ) {
                    $inputs[ $k ]['weight'] = (int) round( ( $row['weight'] / $total_weight ) * 100 );
                }
            }
        }

        return [
            'inputs'      => $inputs,
            'thresholds'  => [
                'amber_below' => isset( $post['threshold_amber'] ) ? max( 0, min( 100, (int) $post['threshold_amber'] ) ) : 60,
                'red_below'   => isset( $post['threshold_red'] )   ? max( 0, min( 100, (int) $post['threshold_red'] ) )   : 40,
            ],
            'floor_rules' => [
                'behaviour_floor_below' => isset( $post['behaviour_floor'] ) ? max( 0.0, min( 5.0, (float) $post['behaviour_floor'] ) ) : 0.0,
            ],
        ];
    }

    private static function renderForm( int $age_group_id, string $heading ): void {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_player_status_methodology
              WHERE club_id = %d AND age_group_id = %d
              LIMIT 1",
            CurrentClub::id(), $age_group_id
        ) );

        $config = $row ? (array) json_decode( (string) $row->config_json, true ) : MethodologyResolver::shippedDefault();
        $is_override = $row !== null;

        $get = static function ( $config, $key, $default ) {
            $parts = explode( '.', $key );
            $cur   = $config;
            foreach ( $parts as $p ) {
                if ( ! is_array( $cur ) || ! isset( $cur[ $p ] ) ) return $default;
                $cur = $cur[ $p ];
            }
            return $cur;
        };

        ?>
        <details <?php echo $is_override ? 'open' : ''; ?> style="border:1px solid #d6dadd;border-radius:6px;padding:12px 14px;margin-bottom:14px;background:#fff;">
            <summary style="cursor:pointer;font-weight:600;">
                <?php echo esc_html( $heading ); ?>
                <?php if ( $is_override ) : ?>
                    <span style="display:inline-block;margin-left:8px;padding:1px 8px;background:#dbeafe;color:#1e3a8a;border-radius:4px;font-size:11px;"><?php esc_html_e( 'override', 'talenttrack' ); ?></span>
                <?php endif; ?>
            </summary>
            <form method="post" style="margin-top:12px;">
                <?php wp_nonce_field( 'tt_psm_save', 'tt_psm_nonce' ); ?>
                <input type="hidden" name="age_group_id" value="<?php echo (int) $age_group_id; ?>" />

                <h4 style="margin:0 0 8px;"><?php esc_html_e( 'Inputs and weights', 'talenttrack' ); ?></h4>
                <table style="width:100%;border-collapse:collapse;margin-bottom:14px;">
                    <thead><tr>
                        <th style="text-align:left;padding:4px 8px;"></th>
                        <th style="text-align:left;padding:4px 8px;"><?php esc_html_e( 'Input', 'talenttrack' ); ?></th>
                        <th style="text-align:left;padding:4px 8px;width:120px;"><?php esc_html_e( 'Weight (%)', 'talenttrack' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( [
                        'ratings'    => __( 'Evaluation ratings', 'talenttrack' ),
                        'behaviour'  => __( 'Behaviour observations', 'talenttrack' ),
                        'attendance' => __( 'Attendance', 'talenttrack' ),
                        'potential'  => __( 'Trainer-stated potential', 'talenttrack' ),
                    ] as $key => $label ) :
                        $enabled = (bool) $get( $config, "inputs.{$key}.enabled", true );
                        $weight  = (int) $get( $config, "inputs.{$key}.weight", 0 );
                    ?>
                        <tr>
                            <td style="padding:4px 8px;"><input type="checkbox" name="input_<?php echo esc_attr( $key ); ?>_enabled" value="1" <?php checked( $enabled ); ?> /></td>
                            <td style="padding:4px 8px;"><?php echo esc_html( $label ); ?></td>
                            <td style="padding:4px 8px;"><input type="number" name="input_<?php echo esc_attr( $key ); ?>_weight" value="<?php echo (int) $weight; ?>" min="0" max="100" inputmode="numeric" style="width:80px;" /></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <h4 style="margin:0 0 8px;"><?php esc_html_e( 'Thresholds', 'talenttrack' ); ?></h4>
                <p style="margin:0 0 14px;">
                    <label><?php esc_html_e( 'Amber below', 'talenttrack' ); ?>
                        <input type="number" name="threshold_amber" value="<?php echo (int) $get( $config, 'thresholds.amber_below', 60 ); ?>" min="0" max="100" inputmode="numeric" style="width:80px;" />
                    </label>
                    <label style="margin-left:16px;"><?php esc_html_e( 'Red below', 'talenttrack' ); ?>
                        <input type="number" name="threshold_red" value="<?php echo (int) $get( $config, 'thresholds.red_below', 40 ); ?>" min="0" max="100" inputmode="numeric" style="width:80px;" />
                    </label>
                </p>

                <h4 style="margin:0 0 8px;"><?php esc_html_e( 'Behaviour floor', 'talenttrack' ); ?></h4>
                <p style="margin:0 0 14px;">
                    <label><?php esc_html_e( 'Cap status at amber when behaviour is below', 'talenttrack' ); ?>
                        <input type="number" name="behaviour_floor" value="<?php echo esc_attr( (string) $get( $config, 'floor_rules.behaviour_floor_below', 3.0 ) ); ?>" min="0" max="5" step="0.1" inputmode="decimal" style="width:80px;" />
                    </label>
                    <small style="display:block;color:#5b6e75;margin-top:4px;"><?php esc_html_e( '0 disables the floor rule.', 'talenttrack' ); ?></small>
                </p>

                <p style="margin:0;display:flex;gap:8px;">
                    <button type="submit" class="tt-btn tt-btn-primary" style="min-height:48px;padding:0 22px;">
                        <?php esc_html_e( 'Save', 'talenttrack' ); ?>
                    </button>
                    <?php if ( $is_override ) : ?>
                        <button type="submit" name="reset" value="1" class="tt-btn tt-btn-secondary" style="min-height:48px;padding:0 22px;" onclick="return confirm('<?php echo esc_js( __( 'Reset this override to the default?', 'talenttrack' ) ); ?>');">
                            <?php esc_html_e( 'Reset to default', 'talenttrack' ); ?>
                        </button>
                    <?php endif; ?>
                </p>
            </form>
        </details>
        <?php
    }
}
