<?php
namespace TT\Modules\Authorization\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;
use TT\Modules\Authorization\LegacyCapMapper;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\PersonaResolver;

/**
 * UserComparisonPage — Authorization → Compare users (#0033 follow-up).
 *
 * Operator picks two WP users; the page renders a side-by-side matrix
 * of every TT capability with each user's effective state (legacy cap
 * + matrix gate), and highlights rows where the two users differ.
 *
 * Use case the operator surfaces it for: "Why does HoD A see the
 * Activities tile but HoD B doesn't?" — pick both, toggle "Show only
 * differences", and the table tells you which cap is the discriminator.
 *
 * Default view is diff-only because that's the headline question.
 * Toggle off to see the full matrix (useful for "what does this Scout
 * actually have, end to end" audits).
 */
class UserComparisonPage {

    public static function render(): void {
        if ( ! current_user_can( 'administrator' ) ) {
            wp_die( esc_html__( 'You must be an administrator to view this page.', 'talenttrack' ) );
        }

        $user_a_id    = isset( $_GET['user_a'] ) ? absint( $_GET['user_a'] ) : 0;
        $user_b_id    = isset( $_GET['user_b'] ) ? absint( $_GET['user_b'] ) : 0;
        $diff_only    = ! isset( $_GET['show_all'] );
        $matrix_active = ( new ConfigService() )->getBool( 'tt_authorization_active', false );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Compare users — Authorization', 'talenttrack' ); ?></h1>
            <p style="color:#5b6e75; max-width:760px;">
                <?php esc_html_e( 'Pick two users to see their effective TalentTrack permissions side by side. Use this to answer questions like "why does coach A see this tile but coach B doesn\'t?" — the diff highlights the discriminating capability.', 'talenttrack' ); ?>
            </p>

            <?php if ( ! $matrix_active ) : ?>
                <div class="notice notice-warning inline" style="margin:12px 0;">
                    <p><?php esc_html_e( 'The authorization matrix is in shadow mode. The "Matrix" column below is computed but the runtime still uses the legacy "Cap" column. Apply the matrix on Authorization → Migration preview to switch.', 'talenttrack' ); ?></p>
                </div>
            <?php endif; ?>

            <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="background:#fff; border:1px solid #d0d2d6; border-radius:6px; padding:14px; margin:14px 0;">
                <input type="hidden" name="page" value="tt-user-compare" />
                <table class="form-table" style="margin:0;">
                    <tr>
                        <th scope="row"><label for="tt-cmp-a"><?php esc_html_e( 'User A', 'talenttrack' ); ?></label></th>
                        <td>
                            <?php wp_dropdown_users( [
                                'name'             => 'user_a',
                                'id'               => 'tt-cmp-a',
                                'selected'         => $user_a_id,
                                'show_option_none' => __( '— Select user A —', 'talenttrack' ),
                                'show'             => 'display_name_with_login',
                            ] ); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tt-cmp-b"><?php esc_html_e( 'User B', 'talenttrack' ); ?></label></th>
                        <td>
                            <?php wp_dropdown_users( [
                                'name'             => 'user_b',
                                'id'               => 'tt-cmp-b',
                                'selected'         => $user_b_id,
                                'show_option_none' => __( '— Select user B —', 'talenttrack' ),
                                'show'             => 'display_name_with_login',
                            ] ); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Display options', 'talenttrack' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="show_all" value="1" <?php checked( ! $diff_only ); ?> />
                                <?php esc_html_e( 'Show all capabilities (default: only show rows where the two users differ)', 'talenttrack' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <p style="margin:10px 0 0;">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Compare', 'talenttrack' ); ?></button>
                </p>
            </form>

            <?php
            if ( $user_a_id === 0 || $user_b_id === 0 ) {
                echo '<p><em>' . esc_html__( 'Pick two users above to see the comparison.', 'talenttrack' ) . '</em></p>';
                echo '</div>';
                return;
            }
            if ( $user_a_id === $user_b_id ) {
                echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Pick two different users.', 'talenttrack' ) . '</p></div>';
                echo '</div>';
                return;
            }

            $user_a = get_user_by( 'id', $user_a_id );
            $user_b = get_user_by( 'id', $user_b_id );
            if ( ! $user_a instanceof \WP_User || ! $user_b instanceof \WP_User ) {
                echo '<div class="notice notice-error inline"><p>' . esc_html__( 'One of the selected users no longer exists.', 'talenttrack' ) . '</p></div>';
                echo '</div>';
                return;
            }

            self::renderUserHeaders( $user_a, $user_b );
            self::renderComparisonTable( $user_a, $user_b, $diff_only );
            ?>
        </div>
        <?php
    }

    private static function renderUserHeaders( \WP_User $a, \WP_User $b ): void {
        $personas_a = PersonaResolver::personasFor( (int) $a->ID );
        $personas_b = PersonaResolver::personasFor( (int) $b->ID );
        $roles_a    = $a->roles;
        $roles_b    = $b->roles;
        ?>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin:14px 0;">
            <div style="background:#fff; border:1px solid #d0d2d6; border-radius:6px; padding:14px;">
                <h3 style="margin:0 0 6px;"><?php esc_html_e( 'User A', 'talenttrack' ); ?></h3>
                <p style="margin:0;"><strong><?php echo esc_html( $a->display_name ); ?></strong>
                    <small style="color:#888;">@<?php echo esc_html( $a->user_login ); ?> · <?php echo esc_html( $a->user_email ); ?></small></p>
                <p style="margin:6px 0 0; font-size:12px;">
                    <strong><?php esc_html_e( 'WP roles:', 'talenttrack' ); ?></strong> <?php echo esc_html( implode( ', ', $roles_a ) ?: '—' ); ?><br>
                    <strong><?php esc_html_e( 'TT personas:', 'talenttrack' ); ?></strong> <?php echo esc_html( implode( ', ', $personas_a ) ?: '—' ); ?>
                </p>
            </div>
            <div style="background:#fff; border:1px solid #d0d2d6; border-radius:6px; padding:14px;">
                <h3 style="margin:0 0 6px;"><?php esc_html_e( 'User B', 'talenttrack' ); ?></h3>
                <p style="margin:0;"><strong><?php echo esc_html( $b->display_name ); ?></strong>
                    <small style="color:#888;">@<?php echo esc_html( $b->user_login ); ?> · <?php echo esc_html( $b->user_email ); ?></small></p>
                <p style="margin:6px 0 0; font-size:12px;">
                    <strong><?php esc_html_e( 'WP roles:', 'talenttrack' ); ?></strong> <?php echo esc_html( implode( ', ', $roles_b ) ?: '—' ); ?><br>
                    <strong><?php esc_html_e( 'TT personas:', 'talenttrack' ); ?></strong> <?php echo esc_html( implode( ', ', $personas_b ) ?: '—' ); ?>
                </p>
            </div>
        </div>
        <?php
    }

    private static function renderComparisonTable( \WP_User $a, \WP_User $b, bool $diff_only ): void {
        $caps = LegacyCapMapper::knownCaps();
        sort( $caps );

        $rows = [];
        foreach ( $caps as $cap ) {
            $tuple = LegacyCapMapper::tupleFor( $cap );
            $entity   = $tuple ? $tuple[0] : '';
            $activity = $tuple ? $tuple[1] : '';

            $a_legacy = isset( $a->allcaps[ $cap ] ) && (bool) $a->allcaps[ $cap ];
            $b_legacy = isset( $b->allcaps[ $cap ] ) && (bool) $b->allcaps[ $cap ];
            $a_matrix = $entity !== '' && MatrixGate::canAnyScope( (int) $a->ID, $entity, $activity );
            $b_matrix = $entity !== '' && MatrixGate::canAnyScope( (int) $b->ID, $entity, $activity );

            $a_effective = $a_legacy || $a_matrix;
            $b_effective = $b_legacy || $b_matrix;
            $differs     = $a_effective !== $b_effective;

            if ( $diff_only && ! $differs ) continue;

            $rows[] = [
                'cap'         => $cap,
                'entity'      => $entity,
                'activity'    => $activity,
                'a_legacy'    => $a_legacy,
                'b_legacy'    => $b_legacy,
                'a_matrix'    => $a_matrix,
                'b_matrix'    => $b_matrix,
                'a_effective' => $a_effective,
                'b_effective' => $b_effective,
                'differs'     => $differs,
            ];
        }

        $diff_count = 0;
        foreach ( $rows as $r ) if ( $r['differs'] ) $diff_count++;
        ?>
        <h2 style="margin-top:18px;"><?php esc_html_e( 'Capability comparison', 'talenttrack' ); ?>
            <span style="font-weight:normal; color:#5b6e75; font-size:14px;">
                <?php
                $known_count = count( $caps );
                if ( $diff_only ) {
                    echo esc_html( sprintf(
                        /* translators: 1: differing-cap count, 2: total caps */
                        __( '— %1$d of %2$d capabilities differ', 'talenttrack' ),
                        $diff_count, $known_count
                    ) );
                } else {
                    echo esc_html( sprintf(
                        /* translators: 1: differing-cap count, 2: total caps */
                        __( '— %1$d differ of %2$d total', 'talenttrack' ),
                        $diff_count, count( $rows )
                    ) );
                }
                ?>
            </span>
        </h2>

        <?php if ( empty( $rows ) ) : ?>
            <p><em><?php esc_html_e( 'No differences. Both users have identical effective capabilities across the matrix.', 'talenttrack' ); ?></em></p>
        <?php else : ?>
            <table class="widefat striped" style="font-size:12px;">
                <thead>
                    <tr>
                        <th style="width:25%;"><?php esc_html_e( 'Capability', 'talenttrack' ); ?></th>
                        <th style="width:20%;"><?php esc_html_e( 'Matrix entity:activity', 'talenttrack' ); ?></th>
                        <th style="width:25%;"><?php echo esc_html( $a->display_name ); ?></th>
                        <th style="width:25%;"><?php echo esc_html( $b->display_name ); ?></th>
                        <th style="width:5%;"><?php esc_html_e( 'Diff', 'talenttrack' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $rows as $r ) :
                    $bg = $r['differs'] ? '#fef9e7' : '';
                    ?>
                    <tr<?php if ( $bg ) echo ' style="background:' . esc_attr( $bg ) . ';"'; ?>>
                        <td style="font-family:monospace;"><?php echo esc_html( $r['cap'] ); ?></td>
                        <td style="font-family:monospace; color:#5b6e75;">
                            <?php echo $r['entity'] !== ''
                                ? esc_html( $r['entity'] . ':' . $r['activity'] )
                                : '<em style="color:#b32d2e;">' . esc_html__( 'unmapped', 'talenttrack' ) . '</em>'; ?>
                        </td>
                        <td><?php echo self::renderCapState( $r['a_legacy'], $r['a_matrix'] ); ?></td>
                        <td><?php echo self::renderCapState( $r['b_legacy'], $r['b_matrix'] ); ?></td>
                        <td style="text-align:center;">
                            <?php if ( $r['differs'] ) : ?>
                                <strong style="color:#b32d2e;">●</strong>
                            <?php else : ?>
                                <span style="color:#aaa;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin-top:10px; color:#5b6e75; font-size:12px;">
                <strong><?php esc_html_e( 'Legend:', 'talenttrack' ); ?></strong>
                <span style="color:#196a32;">✓ cap</span> = granted via WP role / legacy capability,
                <span style="color:#196a32;">✓ matrix</span> = granted via the authorization matrix,
                <span style="color:#b32d2e;">✗</span> = denied,
                yellow row = users differ.
            </p>
        <?php endif; ?>
        <?php
    }

    private static function renderCapState( bool $legacy, bool $matrix ): string {
        $parts = [];
        if ( $legacy ) {
            $parts[] = '<span style="color:#196a32;">✓ cap</span>';
        } else {
            $parts[] = '<span style="color:#b32d2e;">✗ cap</span>';
        }
        if ( $matrix ) {
            $parts[] = '<span style="color:#196a32;">✓ matrix</span>';
        } else {
            $parts[] = '<span style="color:#b32d2e;">✗ matrix</span>';
        }
        return implode( ' · ', $parts );
    }
}
