<?php
namespace TT\Modules\Authorization\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Authorization\LegacyCapMapper;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * AuthChainDebugPage — admin → Permission Chain Debug (#777).
 *
 * Per-user, per-cap diagnostic that prints every layer the auth chain
 * consults, so the operator can see exactly which layer fails when a
 * legitimate user hits "Not authorized" on a cap-gated surface.
 *
 * Why this exists: the WP `tt_scout` role doesn't bake `tt_view_prospects`
 * / `tt_edit_prospects` into its baseline caps — both come exclusively
 * through the matrix bridge and the `user_has_cap` filter. When that
 * pipeline is in an inconsistent state (toggle off, matrix row missing,
 * persona resolution returning empty, evaluator class missing) the
 * scout hits the deny path on every prospects surface. The existing
 * Permission Debug (`?page=tt-roles-debug`) shows scope assignments but
 * not the per-cap layered chain (user_can → LegacyCapMapper::evaluate →
 * AuthorizationService::userCanOrMatrix). This page fills that gap.
 *
 * Output sections:
 *   1. User picker + summary (roles, personas).
 *   2. System state (toggle value, class existence, hook count).
 *   3. Per-cap resolution table — one row per representative `tt_*` cap,
 *      with each layer's verdict colour-coded and the failing layer
 *      called out explicitly.
 *   4. Matrix grants for the user's resolved personas.
 *
 * Read-only. Gated on `administrator` so a misbehaving cap layer can't
 * lock the operator out of the page that diagnoses it.
 */
class AuthChainDebugPage {

    /**
     * Representative caps the diagnostic walks through. Ordered by
     * persona usefulness: scout-relevant first, then coach, then admin.
     */
    private const PROBE_CAPS = [
        'tt_view_prospects',
        'tt_edit_prospects',
        'tt_manage_prospects',
        'tt_view_players',
        'tt_edit_players',
        'tt_view_teams',
        'tt_view_evaluations',
        'tt_edit_evaluations',
        'tt_evaluate_players',
        'tt_view_player_notes',
        'tt_access_frontend_admin',
        'tt_view_settings',
    ];

    public static function render(): void {
        if ( ! current_user_can( 'administrator' ) ) {
            wp_die( esc_html__( 'You must be an administrator to view this page.', 'talenttrack' ) );
        }

        $selected_user_id = isset( $_GET['user_id'] )
            ? absint( wp_unslash( (string) $_GET['user_id'] ) )
            : 0;

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Permission Chain Debug', 'talenttrack' ); ?></h1>

            <p class="description" style="max-width: 760px;">
                <?php esc_html_e( 'Per-user, per-cap walk through every layer of the authorization chain. Use this to diagnose "Not authorized" symptoms on installs where the legacy WP cap, the matrix bridge, and the user_has_cap filter return inconsistent results.', 'talenttrack' ); ?>
            </p>

            <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin: 16px 0;">
                <input type="hidden" name="page" value="tt-auth-chain-debug" />
                <label for="tt_auth_chain_user">
                    <strong><?php esc_html_e( 'User:', 'talenttrack' ); ?></strong>
                </label>
                <?php
                wp_dropdown_users( [
                    'name'              => 'user_id',
                    'id'                => 'tt_auth_chain_user',
                    'selected'          => $selected_user_id,
                    'show_option_none'  => __( '— Select —', 'talenttrack' ),
                    'option_none_value' => 0,
                ] );
                submit_button( __( 'Check', 'talenttrack' ), 'secondary', 'submit', false );
                ?>
            </form>

            <?php
            self::renderSystemStateSection();

            if ( $selected_user_id > 0 ) {
                $user = get_user_by( 'id', $selected_user_id );
                if ( ! $user instanceof \WP_User ) {
                    echo '<div class="notice notice-error"><p>' .
                        esc_html__( 'User not found.', 'talenttrack' ) .
                        '</p></div>';
                    echo '</div>';
                    return;
                }
                self::renderUserSummarySection( $user );
                self::renderCapResolutionSection( $user );
                self::renderMatrixGrantsSection( $user );
            }
            ?>
        </div>
        <?php
    }

    private static function renderSystemStateSection(): void {
        $config = new ConfigService();
        $matrix_active = $config->getBool( 'tt_authorization_active', false );

        $classes = [
            AuthorizationService::class => class_exists( AuthorizationService::class ),
            LegacyCapMapper::class      => class_exists( LegacyCapMapper::class ),
            MatrixGate::class           => class_exists( MatrixGate::class ),
            PersonaResolver::class      => class_exists( PersonaResolver::class ),
            MatrixRepository::class     => class_exists( MatrixRepository::class ),
        ];

        global $wp_filter;
        $user_has_cap_hooks = isset( $wp_filter['user_has_cap'] )
            ? count( $wp_filter['user_has_cap']->callbacks ?? [] )
            : 0;
        ?>
        <h2 style="margin-top: 24px;"><?php esc_html_e( 'System state', 'talenttrack' ); ?></h2>
        <table class="widefat striped" style="max-width: 760px;">
            <tbody>
                <tr>
                    <th style="width: 320px;"><?php esc_html_e( 'tt_authorization_active', 'talenttrack' ); ?></th>
                    <td>
                        <?php if ( $matrix_active ) : ?>
                            <span style="color: #196a32; font-weight: 600;">1</span> &middot;
                            <?php esc_html_e( 'Matrix bridge enforces cap checks via user_has_cap filter.', 'talenttrack' ); ?>
                        <?php else : ?>
                            <span style="color: #b32d2e; font-weight: 600;">0</span> &middot;
                            <?php esc_html_e( 'Matrix bridge is dormant. user_can() consults only baseline WP caps. AuthorizationService::userCanOrMatrix() falls back to LegacyCapMapper::evaluate(), but the user_has_cap surfacing layer is OFF.', 'talenttrack' ); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'user_has_cap filter callbacks', 'talenttrack' ); ?></th>
                    <td><?php echo (int) $user_has_cap_hooks; ?></td>
                </tr>
                <?php foreach ( $classes as $class => $exists ) : ?>
                    <tr>
                        <th><code><?php echo esc_html( $class ); ?></code></th>
                        <td>
                            <?php if ( $exists ) : ?>
                                <span style="color: #196a32; font-weight: 600;">✓ loaded</span>
                            <?php else : ?>
                                <span style="color: #b32d2e; font-weight: 600;">✗ MISSING</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function renderUserSummarySection( \WP_User $user ): void {
        $personas = class_exists( PersonaResolver::class )
            ? PersonaResolver::personasFor( (int) $user->ID )
            : [];
        ?>
        <h2 style="margin-top: 24px;">
            <?php
            echo esc_html( sprintf(
                /* translators: 1: user display name, 2: user id */
                __( 'User: %1$s (#%2$d)', 'talenttrack' ),
                $user->display_name,
                (int) $user->ID
            ) );
            ?>
        </h2>
        <table class="widefat striped" style="max-width: 760px;">
            <tbody>
                <tr>
                    <th style="width: 320px;"><?php esc_html_e( 'Login', 'talenttrack' ); ?></th>
                    <td><code><?php echo esc_html( $user->user_login ); ?></code></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'WP roles', 'talenttrack' ); ?></th>
                    <td>
                        <?php if ( empty( $user->roles ) ) : ?>
                            <em><?php esc_html_e( '(none)', 'talenttrack' ); ?></em>
                        <?php else : ?>
                            <code><?php echo esc_html( implode( ', ', (array) $user->roles ) ); ?></code>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Resolved personas (PersonaResolver)', 'talenttrack' ); ?></th>
                    <td>
                        <?php if ( empty( $personas ) ) : ?>
                            <span style="color: #b32d2e; font-weight: 600;">
                                <?php esc_html_e( '(empty)', 'talenttrack' ); ?>
                            </span>
                            &middot;
                            <?php esc_html_e( 'No persona resolved — MatrixGate::canAnyScope() will short-circuit to false on every check.', 'talenttrack' ); ?>
                        <?php else : ?>
                            <code><?php echo esc_html( implode( ', ', $personas ) ); ?></code>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'is administrator?', 'talenttrack' ); ?></th>
                    <td>
                        <?php echo in_array( 'administrator', (array) $user->roles, true )
                            ? '<span style="color:#196a32;font-weight:600;">yes</span>'
                            : 'no'; ?>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    private static function renderCapResolutionSection( \WP_User $user ): void {
        ?>
        <h2 style="margin-top: 24px;"><?php esc_html_e( 'Per-cap resolution', 'talenttrack' ); ?></h2>
        <p class="description" style="max-width: 760px;">
            <?php esc_html_e( 'For each cap, three checks: (a) user_can() — the WP layer, which consults baseline role caps + the user_has_cap filter (matrix bridge when active); (b) LegacyCapMapper::evaluate() — the matrix-bridge direct call, bypassing the user_can() pipeline; (c) AuthorizationService::userCanOrMatrix() — the helper that combines both. The "Failing layer" column points at which layer is the first to fall.', 'talenttrack' ); ?>
        </p>
        <table class="widefat striped" style="max-width: 1100px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Cap', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Matrix tuple', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'user_can()', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'LegacyCapMapper::evaluate()', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'userCanOrMatrix()', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Verdict', 'talenttrack' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( self::PROBE_CAPS as $cap ) : ?>
                    <?php
                    $tuple = class_exists( LegacyCapMapper::class )
                        ? LegacyCapMapper::tupleFor( $cap )
                        : null;
                    $tuple_label = $tuple ? sprintf( '(%s, %s)', $tuple[0], $tuple[1] ) : '—';

                    $can          = user_can( $user, $cap );
                    $matrix_eval  = class_exists( LegacyCapMapper::class )
                        ? LegacyCapMapper::evaluate( $cap, $user, [] )
                        : null;
                    $combined     = class_exists( AuthorizationService::class )
                        ? AuthorizationService::userCanOrMatrix( (int) $user->ID, $cap )
                        : false;

                    $verdict      = self::buildVerdict( $can, $matrix_eval, $combined );
                    ?>
                    <tr>
                        <td><code><?php echo esc_html( $cap ); ?></code></td>
                        <td><code><?php echo esc_html( $tuple_label ); ?></code></td>
                        <td><?php echo self::renderBool( $can ); ?></td>
                        <td><?php echo self::renderTriBool( $matrix_eval ); ?></td>
                        <td><?php echo self::renderBool( $combined ); ?></td>
                        <td><?php echo wp_kses_post( $verdict ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function renderMatrixGrantsSection( \WP_User $user ): void {
        if ( ! class_exists( MatrixRepository::class ) ) {
            return;
        }

        $personas = class_exists( PersonaResolver::class )
            ? PersonaResolver::personasFor( (int) $user->ID )
            : [];

        if ( empty( $personas ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tt_authorization_matrix';
        $placeholders = implode( ',', array_fill( 0, count( $personas ), '%s' ) );
        // Numeric IDs are not safe to splice; persona keys are sanitised
        // via wpdb->prepare placeholders.
        $rows = $wpdb->get_results( $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — placeholders built from a known count.
            "SELECT persona, entity, activity, scope_kind, module_class, is_default FROM {$table}
             WHERE persona IN ($placeholders)
             ORDER BY persona ASC, module_class ASC, entity ASC, activity ASC",
            $personas
        ) );
        ?>
        <h2 style="margin-top: 24px;">
            <?php esc_html_e( 'Matrix grants for resolved personas', 'talenttrack' ); ?>
        </h2>
        <p class="description" style="max-width: 760px;">
            <?php esc_html_e( 'Every row in tt_authorization_matrix whose persona matches one of the user\'s resolved personas. If a row you expect is missing, the matrix seed didn\'t plant it on this install (or migration 0104+ didn\'t upgrade it). If rows are present but per-cap evaluation still returns false, the chain is breaking elsewhere — most likely PersonaResolver returning empty or the user_has_cap filter not surfacing the bridge.', 'talenttrack' ); ?>
        </p>
        <?php if ( empty( $rows ) ) : ?>
            <div class="notice notice-warning" style="max-width: 760px;">
                <p>
                    <strong><?php esc_html_e( 'No matrix grants found for any of this user\'s resolved personas.', 'talenttrack' ); ?></strong><br>
                    <?php esc_html_e( 'Likely the tt_authorization_matrix table is empty for these personas (seed missing) — or PersonaResolver is returning personas that the seed doesn\'t recognise.', 'talenttrack' ); ?>
                </p>
            </div>
        <?php else : ?>
            <table class="widefat striped" style="max-width: 1100px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Persona', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Entity', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Activity', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Scope', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Module', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Default', 'talenttrack' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $row ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( (string) $row->persona ); ?></code></td>
                            <td><code><?php echo esc_html( (string) $row->entity ); ?></code></td>
                            <td><code><?php echo esc_html( (string) $row->activity ); ?></code></td>
                            <td><code><?php echo esc_html( (string) $row->scope_kind ); ?></code></td>
                            <td style="font-size: 11px; color: #5b6e75;">
                                <?php echo esc_html( (string) ( $row->module_class ?? '' ) ); ?>
                            </td>
                            <td><?php echo (int) $row->is_default === 1 ? '✓' : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    private static function renderBool( bool $value ): string {
        return $value
            ? '<span style="color:#196a32;font-weight:600;">✓ true</span>'
            : '<span style="color:#b32d2e;font-weight:600;">✗ false</span>';
    }

    /**
     * Three-state render: true / false / null. LegacyCapMapper::evaluate
     * returns null when the cap isn't in the mapping table (so the
     * filter doesn't intercept and native WP cap rules decide).
     */
    private static function renderTriBool( ?bool $value ): string {
        if ( $value === null ) {
            return '<span style="color:#5b6e75;">— null (cap not in mapping)</span>';
        }
        return self::renderBool( $value );
    }

    private static function buildVerdict( bool $can, ?bool $matrix_eval, bool $combined ): string {
        if ( $combined ) {
            return '<span style="color:#196a32;font-weight:600;">✓ allowed</span>';
        }

        // userCanOrMatrix returned false — figure out why.
        if ( $can ) {
            // user_can said yes but combined said no? Shouldn't happen
            // since userCanOrMatrix calls user_can first and returns
            // true if it does.
            return '<span style="color:#b32d2e;">inconsistent — user_can=true but combined=false</span>';
        }

        if ( $matrix_eval === true ) {
            // Matrix said yes but combined said no? Same inconsistency.
            return '<span style="color:#b32d2e;">inconsistent — matrix=true but combined=false</span>';
        }

        if ( $matrix_eval === null ) {
            return '<span style="color:#b32d2e;font-weight:600;">✗ denied</span> &middot; ' .
                esc_html__( 'cap not mapped; native WP cap rules deny', 'talenttrack' );
        }

        // matrix_eval === false
        return '<span style="color:#b32d2e;font-weight:600;">✗ denied</span> &middot; ' .
            esc_html__( 'both layers returned false — check personas + matrix seed', 'talenttrack' );
    }
}
