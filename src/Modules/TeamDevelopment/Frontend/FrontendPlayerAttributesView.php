<?php
namespace TT\Modules\TeamDevelopment\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\TeamDevelopment\Repositories\PlayerAttributesRepository;
use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * FrontendPlayerAttributesView (#1913, epic #1017 Phase 7) — the operator
 * workflow to populate a player's chemistry attributes. Without it the
 * reworked engine computes on NULLs.
 *
 * Slug: `player-attributes` (requires `player_id`). Renders the Phase-1
 * catalogue grouped (physical / technical / tactical / mental / behaviour /
 * development), one 0–100 input per attribute pre-filled with the current
 * value; a nonce-protected POST upserts the lot. Matrix-gated via
 * canEvaluatePlayer (same trust as recording an evaluation); Save + Cancel.
 */
final class FrontendPlayerAttributesView extends FrontendViewBase {

    public const NONCE_ACTION = 'tt_player_attributes_save';
    public const NONCE_FIELD  = '_tt_player_attributes_nonce';

    /** @var array<string, string> */
    private static array $groupLabels = [];

    public static function render( int $user_id, bool $is_admin ): void {
        $player_id = isset( $_GET['player_id'] ) ? absint( $_GET['player_id'] ) : 0;
        $title     = __( 'Chemistry attributes', 'talenttrack' );

        FrontendBreadcrumbs::fromDashboard( $title, [
            FrontendBreadcrumbs::viewCrumb( 'players', __( 'Players', 'talenttrack' ) ),
        ] );

        if ( $player_id <= 0 ) {
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'A player is required. Open this from a player\'s profile.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( ! AuthorizationService::canEvaluatePlayer( $user_id, $player_id ) ) {
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to edit this player\'s attributes.', 'talenttrack' ) . '</p>';
            return;
        }

        $player = QueryHelpers::get_player( $player_id );
        if ( ! $player ) {
            self::renderHeader( __( 'Player not found', 'talenttrack' ) );
            return;
        }

        $flash = '';
        if ( $_SERVER['REQUEST_METHOD'] === 'POST'
             && isset( $_POST[ self::NONCE_FIELD ] )
             && wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
            $flash = self::handlePost( $player_id );
        }

        $grouped = ( new PlayerAttributesRepository() )->forPlayer( $player_id );

        self::enqueueAssets();
        wp_enqueue_style(
            'tt-player-attributes-editor',
            TT_PLUGIN_URL . 'assets/css/components/player-attributes-editor.css',
            [ 'tt-frontend-mobile' ],
            TT_VERSION
        );
        $name = QueryHelpers::player_display_name( $player );
        self::renderHeader( $name !== '' ? sprintf(
            /* translators: %s: player name */
            __( 'Chemistry attributes — %s', 'talenttrack' ),
            $name
        ) : $title );

        if ( $flash !== '' ) {
            echo '<div class="tt-notice tt-notice-success">' . esc_html( $flash ) . '</div>';
        }
        if ( empty( $grouped ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No attribute catalogue is set up yet.', 'talenttrack' ) . '</p>';
            return;
        }

        $cancel_url = add_query_arg( [ 'tt_view' => 'players', 'id' => $player_id ], RecordLink::dashboardUrl() );
        ?>
        <p class="tt-pa-attr-intro">
            <?php esc_html_e( 'Rate each attribute 0–100. Leave blank to clear. These feed the team-chemistry engine.', 'talenttrack' ); ?>
        </p>
        <form method="post" class="tt-pa-attr-form">
            <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
            <?php foreach ( $grouped as $group => $items ) : ?>
                <fieldset class="tt-pa-attr-group">
                    <legend><?php echo esc_html( self::groupLabel( (string) $group ) ); ?></legend>
                    <?php foreach ( (array) $items as $it ) :
                        $def_id = (int) $it['def_id'];
                        $val    = $it['value'] !== null ? (string) (int) $it['value'] : '';
                        $fid    = 'tt-pa-attr-' . $def_id;
                        ?>
                        <label class="tt-pa-attr-row" for="<?php echo esc_attr( $fid ); ?>">
                            <span class="tt-pa-attr-label"><?php echo esc_html( (string) $it['label'] ); ?></span>
                            <input type="number" id="<?php echo esc_attr( $fid ); ?>"
                                   class="tt-input tt-pa-attr-input"
                                   name="attr[<?php echo $def_id; ?>]"
                                   min="<?php echo (int) $it['min']; ?>" max="<?php echo (int) $it['max']; ?>"
                                   inputmode="numeric" value="<?php echo esc_attr( $val ); ?>" />
                        </label>
                    <?php endforeach; ?>
                </fieldset>
            <?php endforeach; ?>
            <div class="tt-pa-attr-footer">
                <a class="tt-btn tt-btn-secondary" href="<?php echo esc_url( $cancel_url ); ?>">
                    <?php esc_html_e( 'Cancel', 'talenttrack' ); ?>
                </a>
                <button type="submit" class="tt-btn tt-btn-primary">
                    <?php esc_html_e( 'Save attributes', 'talenttrack' ); ?>
                </button>
            </div>
        </form>
        <?php
    }

    private static function handlePost( int $player_id ): string {
        $entries = isset( $_POST['attr'] ) && is_array( $_POST['attr'] ) ? wp_unslash( $_POST['attr'] ) : [];
        $repo  = new PlayerAttributesRepository();
        $saved = 0;
        foreach ( $entries as $def_id => $raw ) {
            $def_id = (int) $def_id;
            if ( $def_id <= 0 ) continue;
            $raw = is_string( $raw ) ? trim( $raw ) : '';
            $value = $raw === '' ? null : (int) $raw;
            if ( $repo->upsertValue( $player_id, $def_id, $value ) ) {
                $saved++;
            }
        }
        return sprintf(
            /* translators: %d: number of attributes saved */
            _n( '%d attribute saved.', '%d attributes saved.', $saved, 'talenttrack' ),
            $saved
        );
    }

    private static function groupLabel( string $group ): string {
        if ( empty( self::$groupLabels ) ) {
            self::$groupLabels = [
                'physical'    => __( 'Physical', 'talenttrack' ),
                'technical'   => __( 'Technical', 'talenttrack' ),
                'tactical'    => __( 'Tactical', 'talenttrack' ),
                'mental'      => __( 'Mental', 'talenttrack' ),
                'behaviour'   => __( 'Behaviour', 'talenttrack' ),
                'development' => __( 'Development', 'talenttrack' ),
            ];
        }
        return self::$groupLabels[ $group ] ?? ucfirst( $group );
    }
}
