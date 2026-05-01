<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Wizards\WizardAnalytics;
use TT\Shared\Wizards\WizardRegistry;

/**
 * FrontendWizardsAdminView (#0055 Phase 4) — admin page combining the
 * `tt_wizards_enabled` toggle with completion analytics per registered
 * wizard.
 *
 *   ?tt_view=wizards-admin
 *
 * Config UI is a checkbox grid — admins tick the wizards they want
 * surfaced. The form serialises into the existing storage shape on
 * submit (`'all'` / `'off'` / comma-separated slug list) so
 * `WizardRegistry::isEnabled()` keeps working unchanged.
 */
class FrontendWizardsAdminView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_edit_settings' ) ) {
            self::renderHeader( __( 'Wizards', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to configure wizards.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        $saved = self::handlePost( $user_id );
        self::renderHeader( __( 'Wizards', 'talenttrack' ) );

        if ( $saved ) {
            echo '<div class="tt-notice tt-notice-success">' . esc_html__( 'Saved.', 'talenttrack' ) . '</div>';
        }

        self::renderConfigForm();
        self::renderAnalytics();
    }

    private static function handlePost( int $user_id ): bool {
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) return false;
        if ( ! isset( $_POST['tt_wizards_admin_nonce'] ) ) return false;
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tt_wizards_admin_nonce'] ) ), 'tt_wizards_admin' ) ) return false;

        $registered     = WizardRegistry::all();
        $registered_set = array_keys( $registered );
        $submitted      = isset( $_POST['tt_wizards_enabled_slugs'] ) && is_array( $_POST['tt_wizards_enabled_slugs'] )
            ? array_values( array_intersect( $registered_set, array_map( static fn( $s ) => sanitize_key( wp_unslash( (string) $s ) ), $_POST['tt_wizards_enabled_slugs'] ) ) )
            : [];

        if ( empty( $submitted ) ) {
            $value = 'off';
        } elseif ( count( $submitted ) === count( $registered_set ) ) {
            $value = 'all';
        } else {
            $value = implode( ',', $submitted );
        }

        QueryHelpers::set_config( 'tt_wizards_enabled', $value );
        return true;
    }

    private static function renderConfigForm(): void {
        $current    = strtolower( trim( (string) QueryHelpers::get_config( 'tt_wizards_enabled', 'all' ) ) );
        $registered = WizardRegistry::all();
        if ( ! $registered ) {
            echo '<p>' . esc_html__( 'No wizards registered yet.', 'talenttrack' ) . '</p>';
            return;
        }

        // Resolve which slugs are currently enabled.
        $enabled_slugs = self::resolveEnabledSlugs( $current, array_keys( $registered ) );
        ?>
        <section class="tt-trial-section">
            <h2 style="margin-top:0;"><?php esc_html_e( 'Configuration', 'talenttrack' ); ?></h2>
            <p style="color:#5b6e75;margin:0 0 16px;">
                <?php esc_html_e( 'Tick the wizards you want surfaced as the entry-point on their respective list views. Unticked wizards fall back to the flat create form.', 'talenttrack' ); ?>
            </p>

            <form method="post" class="tt-wizards-admin-form">
                <?php wp_nonce_field( 'tt_wizards_admin', 'tt_wizards_admin_nonce' ); ?>

                <label class="tt-wizards-admin-master" style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid #d6dadd;border-radius:6px;background:#f8fafc;margin-bottom:14px;cursor:pointer;font-weight:600;">
                    <input type="checkbox" id="tt-wizards-master" data-tt-wizards-master="1" <?php checked( count( $enabled_slugs ), count( $registered ) ); ?> style="width:18px;height:18px;">
                    <span><?php esc_html_e( 'Enable all wizards', 'talenttrack' ); ?></span>
                </label>

                <ul class="tt-wizards-admin-list" style="list-style:none;padding:0;margin:0 0 16px;display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px;">
                    <?php foreach ( $registered as $slug => $wizard ) : ?>
                        <li style="border:1px solid #d6dadd;border-radius:6px;padding:12px 14px;background:#fff;">
                            <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;min-height:48px;">
                                <input type="checkbox" name="tt_wizards_enabled_slugs[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $enabled_slugs, true ) ); ?> data-tt-wizards-toggle="1" style="width:18px;height:18px;margin-top:2px;">
                                <span style="display:flex;flex-direction:column;gap:2px;">
                                    <strong><?php echo esc_html( $wizard->label() ); ?></strong>
                                    <code style="font-size:11px;color:#5b6e75;background:transparent;padding:0;"><?php echo esc_html( $slug ); ?></code>
                                </span>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <button type="submit" class="tt-button tt-button-primary" style="min-height:48px;padding:0 22px;">
                    <?php esc_html_e( 'Save changes', 'talenttrack' ); ?>
                </button>
            </form>
        </section>
        <script>
        (function(){
            var master = document.getElementById('tt-wizards-master');
            if ( ! master ) return;
            var boxes = document.querySelectorAll('input[data-tt-wizards-toggle="1"]');
            master.addEventListener('change', function(){
                boxes.forEach(function(b){ b.checked = master.checked; });
            });
            boxes.forEach(function(b){
                b.addEventListener('change', function(){
                    var allOn = Array.prototype.every.call( boxes, function(x){ return x.checked; });
                    master.checked = allOn;
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * Mirror of `WizardRegistry::isEnabled()` parsing — translate the
     * stored config value to the explicit list of slugs that should be
     * shown as ticked.
     *
     * @param array<int,string> $registered_slugs
     * @return array<int,string>
     */
    private static function resolveEnabledSlugs( string $current, array $registered_slugs ): array {
        if ( $current === '' || $current === 'off' || $current === '0' || $current === 'false' ) return [];
        if ( $current === 'all' || $current === '1' || $current === 'true' ) return $registered_slugs;
        $list = array_filter( array_map( 'trim', explode( ',', $current ) ) );
        return array_values( array_intersect( $registered_slugs, $list ) );
    }

    private static function renderAnalytics(): void {
        $registered = WizardRegistry::all();
        if ( ! $registered ) return;

        echo '<section class="tt-trial-section"><h2>' . esc_html__( 'Completion analytics', 'talenttrack' ) . '</h2>';
        echo '<table class="tt-table"><thead><tr>';
        echo '<th>' . esc_html__( 'Wizard', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Started', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Completed', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Completion rate', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Most-skipped step', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( $registered as $slug => $w ) {
            $stats = WizardAnalytics::statsFor( $slug );
            $most_skipped = '—';
            if ( $stats['skipped'] ) {
                arsort( $stats['skipped'] );
                $top_step = (string) array_key_first( $stats['skipped'] );
                $most_skipped = $top_step . ' (' . (int) $stats['skipped'][ $top_step ] . ')';
            }
            $rate = (int) round( $stats['completion_rate'] * 100 ) . '%';
            echo '<tr>';
            echo '<td>' . esc_html( $w->label() ) . ' <small style="color:#5b6e75;">(' . esc_html( $slug ) . ')</small></td>';
            echo '<td>' . (int) $stats['started'] . '</td>';
            echo '<td>' . (int) $stats['completed'] . '</td>';
            echo '<td>' . esc_html( $rate ) . '</td>';
            echo '<td>' . esc_html( $most_skipped ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></section>';
    }
}
