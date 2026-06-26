<?php
namespace TT\Modules\TeamDevelopment\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\TeamDevelopment\Repositories\ChemistryConfig;
use TT\Modules\TeamDevelopment\Repositories\ChemistryPositionMatrixRepository;
use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;

/**
 * FrontendChemistryConfigView (#1017 Phase 5) — the admin surfaces for the
 * reworked chemistry engine: the five component weights, the Position
 * Relationship Matrix, and the `chemistry_engine_v2` enable toggle.
 *
 * Slug: `chemistry-config`. Matrix-gated on `team_chemistry` change at
 * global scope (HoD / admin). A settings sub-form (Save-only — §6
 * exemption (a)); nonce-protected POST.
 */
final class FrontendChemistryConfigView extends FrontendViewBase {

    public const NONCE_ACTION = 'tt_chemistry_config_save';
    public const NONCE_FIELD  = '_tt_chemistry_config_nonce';

    public static function render( int $user_id, bool $is_admin ): void {
        $title = __( 'Chemistry settings', 'talenttrack' );
        FrontendBreadcrumbs::fromDashboard( $title );

        if ( ! $is_admin && ! MatrixGate::can( $user_id, 'team_chemistry', 'change', 'global' ) ) {
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to manage chemistry settings.', 'talenttrack' ) . '</p>';
            return;
        }

        $flash = '';
        if ( $_SERVER['REQUEST_METHOD'] === 'POST'
             && isset( $_POST[ self::NONCE_FIELD ] )
             && wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
            $flash = self::handlePost();
        }

        $cfg     = new ConfigService();
        $enabled = $cfg->getBool( 'chemistry_engine_v2', false );
        $weights = ( new ChemistryConfig() )->weights();
        $matrix  = ( new ChemistryPositionMatrixRepository() )->all();

        self::enqueueAssets();
        wp_enqueue_style(
            'tt-chemistry-config',
            TT_PLUGIN_URL . 'assets/css/components/chemistry-config.css',
            [ 'tt-frontend-mobile' ],
            TT_VERSION
        );
        self::renderHeader( $title );

        if ( $flash !== '' ) {
            echo '<div class="tt-notice tt-notice-success">' . esc_html( $flash ) . '</div>';
        }
        ?>
        <form method="post" class="tt-chem-cfg">
            <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>

            <fieldset class="tt-chem-cfg-block">
                <legend><?php esc_html_e( 'Engine', 'talenttrack' ); ?></legend>
                <label class="tt-chem-cfg-toggle">
                    <input type="checkbox" name="engine_v2" value="1" <?php checked( $enabled ); ?> />
                    <span><?php esc_html_e( 'Use the reworked chemistry engine for this academy', 'talenttrack' ); ?></span>
                </label>
                <p class="tt-chem-cfg-hint"><?php esc_html_e( 'Off by default. Turn on once you have populated player chemistry attributes.', 'talenttrack' ); ?></p>
            </fieldset>

            <fieldset class="tt-chem-cfg-block">
                <legend><?php esc_html_e( 'Component weights', 'talenttrack' ); ?></legend>
                <p class="tt-chem-cfg-hint"><?php esc_html_e( 'How much each component counts toward a pair score. Saved values are normalised to total 100.', 'talenttrack' ); ?></p>
                <?php foreach ( self::componentLabels() as $key => $label ) :
                    $val = (int) ( $weights[ $key ] ?? 0 ); ?>
                    <label class="tt-chem-cfg-row">
                        <span class="tt-chem-cfg-label"><?php echo esc_html( $label ); ?></span>
                        <input type="number" class="tt-input tt-chem-cfg-num" name="weights[<?php echo esc_attr( $key ); ?>]"
                               min="0" max="100" inputmode="numeric" value="<?php echo esc_attr( (string) $val ); ?>" />
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <fieldset class="tt-chem-cfg-block">
                <legend><?php esc_html_e( 'Position relationship matrix', 'talenttrack' ); ?></legend>
                <p class="tt-chem-cfg-hint"><?php esc_html_e( 'How strongly two lines interact (0–1): 1.0 adjacent, 0.8 connected, 0.5 indirect, 0.2 minimal.', 'talenttrack' ); ?></p>
                <?php foreach ( $matrix as $i => $row ) :
                    $a = (string) $row->position_a;
                    $b = (string) $row->position_b; ?>
                    <label class="tt-chem-cfg-row">
                        <span class="tt-chem-cfg-label">
                            <?php echo esc_html( self::groupLabel( $a ) . ' · ' . self::groupLabel( $b ) ); ?>
                        </span>
                        <input type="hidden" name="matrix[<?php echo (int) $i; ?>][a]" value="<?php echo esc_attr( $a ); ?>" />
                        <input type="hidden" name="matrix[<?php echo (int) $i; ?>][b]" value="<?php echo esc_attr( $b ); ?>" />
                        <input type="number" class="tt-input tt-chem-cfg-num" name="matrix[<?php echo (int) $i; ?>][w]"
                               min="0" max="1" step="0.1" inputmode="decimal" value="<?php echo esc_attr( (string) (float) $row->weight ); ?>" />
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <div class="tt-chem-cfg-footer">
                <button type="submit" class="tt-btn tt-btn-primary"><?php esc_html_e( 'Save settings', 'talenttrack' ); ?></button>
            </div>
        </form>
        <?php
    }

    private static function handlePost(): string {
        $cfg = new ConfigService();
        $cfg->set( 'chemistry_engine_v2', ! empty( $_POST['engine_v2'] ) ? '1' : '0' );

        $weights = isset( $_POST['weights'] ) && is_array( $_POST['weights'] ) ? wp_unslash( $_POST['weights'] ) : [];
        $clean   = [];
        foreach ( ChemistryConfig::COMPONENTS as $c ) {
            $clean[ $c ] = isset( $weights[ $c ] ) ? (int) $weights[ $c ] : 0;
        }
        ( new ChemistryConfig() )->saveWeights( $clean );

        $rows = isset( $_POST['matrix'] ) && is_array( $_POST['matrix'] ) ? wp_unslash( $_POST['matrix'] ) : [];
        $repo = new ChemistryPositionMatrixRepository();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $a = sanitize_key( (string) ( $row['a'] ?? '' ) );
            $b = sanitize_key( (string) ( $row['b'] ?? '' ) );
            if ( $a !== '' && $b !== '' ) {
                $repo->upsert( $a, $b, (float) ( $row['w'] ?? 0 ) );
            }
        }

        return __( 'Chemistry settings saved.', 'talenttrack' );
    }

    /** @return array<string, string> */
    private static function componentLabels(): array {
        return [
            'compatibility' => __( 'Compatibility', 'talenttrack' ),
            'familiarity'   => __( 'Familiarity', 'talenttrack' ),
            'development'   => __( 'Development', 'talenttrack' ),
            'behaviour'     => __( 'Behaviour', 'talenttrack' ),
            'performance'   => __( 'Performance', 'talenttrack' ),
        ];
    }

    private static function groupLabel( string $g ): string {
        switch ( $g ) {
            case 'gk':  return __( 'Goalkeeper', 'talenttrack' );
            case 'def': return __( 'Defence', 'talenttrack' );
            case 'mid': return __( 'Midfield', 'talenttrack' );
            case 'att': return __( 'Attack', 'talenttrack' );
            default:    return strtoupper( $g );
        }
    }
}
