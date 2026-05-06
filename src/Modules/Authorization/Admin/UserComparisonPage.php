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
 * Operator picks 2–5 WP users; the page renders a side-by-side matrix
 * of every TT capability with each user's effective state (legacy cap
 * + matrix gate), and highlights rows where the picked users differ.
 *
 * Use case the operator surfaces it for: "Why does HoD A see the
 * Activities tile but HoD B doesn't?" — pick both, toggle "Show only
 * differences", and the table tells you which cap is the discriminator.
 *
 * #0080 Wave B3 — N-user (was 2-user) plus a per-cap drill-down that
 * reveals the matrix tuple (persona / entity:activity / scope_kind /
 * scope_value / source row) that grants each user the cap.
 *
 * Default view is diff-only because that's the headline question.
 * Toggle off to see the full matrix (useful for "what does this Scout
 * actually have, end to end" audits).
 */
class UserComparisonPage {

    /** Cap to keep the table readable on a normal-width admin page. */
    public const MAX_USERS = 5;

    public static function render(): void {
        if ( ! current_user_can( 'administrator' ) ) {
            wp_die( esc_html__( 'You must be an administrator to view this page.', 'talenttrack' ) );
        }

        $user_ids      = self::collectUserIds();
        $diff_only     = ! isset( $_GET['show_all'] );
        $matrix_active = ( new ConfigService() )->getBool( 'tt_authorization_active', false );

        $picked_count = count( array_filter( $user_ids, static function ( int $uid ): bool { return $uid > 0; } ) );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Compare users — Authorization', 'talenttrack' ); ?></h1>
            <p style="color:#5b6e75; max-width:760px;">
                <?php esc_html_e( 'Pick 2–5 users to see their effective TalentTrack permissions side by side. Use this to answer questions like "why does coach A see this tile but coach B doesn\'t?" — the diff highlights the discriminating capability.', 'talenttrack' ); ?>
            </p>

            <?php if ( ! $matrix_active ) : ?>
                <div class="notice notice-warning inline" style="margin:12px 0;">
                    <p><?php esc_html_e( 'The authorization matrix is in shadow mode. The "Matrix" column below is computed but the runtime still uses the legacy "Cap" column. Apply the matrix on Authorization → Migration preview to switch.', 'talenttrack' ); ?></p>
                </div>
            <?php endif; ?>

            <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="background:#fff; border:1px solid #d0d2d6; border-radius:6px; padding:14px; margin:14px 0;">
                <input type="hidden" name="page" value="tt-user-compare" />
                <table class="form-table" style="margin:0;">
                    <?php for ( $i = 0; $i < self::MAX_USERS; $i++ ) :
                        $is_optional = $i >= 2;
                        $hidden      = $is_optional && empty( $user_ids[ $i ] );
                        $row_style   = $hidden ? 'display:none;' : '';
                    ?>
                    <tr id="tt-cmp-row-<?php echo (int) $i; ?>" class="tt-cmp-user-row" style="<?php echo esc_attr( $row_style ); ?>">
                        <th scope="row">
                            <label for="tt-cmp-<?php echo (int) $i; ?>">
                                <?php
                                echo esc_html( sprintf(
                                    /* translators: %d: 1-based user slot index */
                                    __( 'User %d', 'talenttrack' ),
                                    $i + 1
                                ) );
                                if ( $is_optional ) {
                                    echo ' <small style="color:#888; font-weight:normal;">' . esc_html__( '(optional)', 'talenttrack' ) . '</small>';
                                }
                                ?>
                            </label>
                        </th>
                        <td>
                            <?php wp_dropdown_users( [
                                'name'             => 'user_ids[' . $i . ']',
                                'id'               => 'tt-cmp-' . $i,
                                'selected'         => $user_ids[ $i ],
                                'show_option_none' => __( '— Select user —', 'talenttrack' ),
                                'show'             => 'display_name_with_login',
                            ] ); ?>
                        </td>
                    </tr>
                    <?php endfor; ?>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Display options', 'talenttrack' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="show_all" value="1" <?php checked( ! $diff_only ); ?> />
                                <?php esc_html_e( 'Show all capabilities (default: only show rows where the picked users differ)', 'talenttrack' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <p style="margin:10px 0 0;">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Compare', 'talenttrack' ); ?></button>
                    <button type="button" class="button button-secondary" id="tt-cmp-add-more">
                        <?php esc_html_e( '+ Add another user', 'talenttrack' ); ?>
                    </button>
                </p>
            </form>

            <script>
            (function () {
                var btn = document.getElementById('tt-cmp-add-more');
                if (!btn) return;
                var rows = document.querySelectorAll('.tt-cmp-user-row');
                function refreshButton() {
                    var anyHidden = false;
                    rows.forEach(function (r) {
                        if (r.style.display === 'none') anyHidden = true;
                    });
                    btn.style.display = anyHidden ? '' : 'none';
                }
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    for (var i = 0; i < rows.length; i++) {
                        if (rows[i].style.display === 'none') {
                            rows[i].style.display = '';
                            break;
                        }
                    }
                    refreshButton();
                });
                refreshButton();
            }());
            </script>

            <?php
            $users = [];
            $seen  = [];
            foreach ( $user_ids as $uid ) {
                if ( $uid <= 0 || isset( $seen[ $uid ] ) ) continue;
                $u = get_user_by( 'id', $uid );
                if ( $u instanceof \WP_User ) {
                    $users[]      = $u;
                    $seen[ $uid ] = true;
                }
            }

            if ( count( $users ) < 2 ) {
                echo '<p><em>' . esc_html__( 'Pick at least two users above to see the comparison.', 'talenttrack' ) . '</em></p>';
                echo '</div>';
                return;
            }
            if ( count( $users ) !== $picked_count ) {
                echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Duplicate or missing users were skipped.', 'talenttrack' ) . '</p></div>';
            }

            self::renderUserHeaders( $users );
            self::renderComparisonTable( $users, $diff_only );
            ?>
        </div>
        <?php
    }

    /**
     * Read picked users from the request. Accepts the modern
     * `user_ids[]` array (#0080 Wave B3) and the legacy `user_a` /
     * `user_b` pair so existing bookmarks keep working. Returns a
     * fixed-length list padded with zeros.
     *
     * @return int[]
     */
    private static function collectUserIds(): array {
        $out = [];
        if ( isset( $_GET['user_ids'] ) && is_array( $_GET['user_ids'] ) ) {
            foreach ( $_GET['user_ids'] as $v ) {
                $out[] = absint( $v );
            }
        }
        if ( empty( array_filter( $out ) ) ) {
            $legacy_a = isset( $_GET['user_a'] ) ? absint( $_GET['user_a'] ) : 0;
            $legacy_b = isset( $_GET['user_b'] ) ? absint( $_GET['user_b'] ) : 0;
            if ( $legacy_a || $legacy_b ) {
                $out = [ $legacy_a, $legacy_b ];
            }
        }
        while ( count( $out ) < self::MAX_USERS ) $out[] = 0;
        return array_slice( $out, 0, self::MAX_USERS );
    }

    /**
     * @param \WP_User[] $users
     */
    private static function renderUserHeaders( array $users ): void {
        ?>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:14px; margin:14px 0;">
            <?php foreach ( $users as $idx => $u ) :
                $personas = PersonaResolver::personasFor( (int) $u->ID );
                $roles    = $u->roles;
            ?>
            <div style="background:#fff; border:1px solid #d0d2d6; border-radius:6px; padding:14px;">
                <h3 style="margin:0 0 6px;">
                    <?php echo esc_html( sprintf(
                        /* translators: %d: 1-based user slot index */
                        __( 'User %d', 'talenttrack' ),
                        $idx + 1
                    ) ); ?>
                </h3>
                <p style="margin:0;">
                    <strong><?php echo esc_html( $u->display_name ); ?></strong><br>
                    <small style="color:#888;">@<?php echo esc_html( $u->user_login ); ?> · <?php echo esc_html( $u->user_email ); ?></small>
                </p>
                <p style="margin:6px 0 0; font-size:12px;">
                    <strong><?php esc_html_e( 'WP roles:', 'talenttrack' ); ?></strong> <?php echo esc_html( implode( ', ', $roles ) ?: '—' ); ?><br>
                    <strong><?php esc_html_e( 'TT personas:', 'talenttrack' ); ?></strong> <?php echo esc_html( implode( ', ', $personas ) ?: '—' ); ?>
                </p>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * @param \WP_User[] $users
     */
    private static function renderComparisonTable( array $users, bool $diff_only ): void {
        $caps = LegacyCapMapper::knownCaps();
        sort( $caps );

        $rows = [];
        foreach ( $caps as $cap ) {
            $tuple    = LegacyCapMapper::tupleFor( $cap );
            $entity   = $tuple ? $tuple[0] : '';
            $activity = $tuple ? $tuple[1] : '';

            $per_user   = [];
            $effectives = [];
            foreach ( $users as $u ) {
                $legacy      = isset( $u->allcaps[ $cap ] ) && (bool) $u->allcaps[ $cap ];
                $matrix      = $entity !== '' && MatrixGate::canAnyScope( (int) $u->ID, $entity, $activity );
                $description = $entity !== '' ? MatrixGate::describeAccess( (int) $u->ID, $entity, $activity ) : null;
                $effective   = $legacy || $matrix;

                $per_user[ (int) $u->ID ] = [
                    'legacy'      => $legacy,
                    'matrix'      => $matrix,
                    'description' => $description,
                    'effective'   => $effective,
                ];
                $effectives[] = $effective;
            }

            $differs = count( array_unique( $effectives ) ) > 1;
            if ( $diff_only && ! $differs ) continue;

            $rows[] = [
                'cap'      => $cap,
                'entity'   => $entity,
                'activity' => $activity,
                'per_user' => $per_user,
                'differs'  => $differs,
            ];
        }

        $diff_count = 0;
        foreach ( $rows as $r ) if ( $r['differs'] ) $diff_count++;
        $known_count = count( $caps );
        $user_count  = count( $users );
        $col_pct     = max( 8, (int) floor( 60 / max( 1, $user_count ) ) );
        ?>
        <h2 style="margin-top:18px;"><?php esc_html_e( 'Capability comparison', 'talenttrack' ); ?>
            <span style="font-weight:normal; color:#5b6e75; font-size:14px;">
                <?php
                if ( $diff_only ) {
                    echo esc_html( sprintf(
                        /* translators: 1: differing-cap count, 2: total caps, 3: number of compared users */
                        __( '— %1$d of %2$d capabilities differ across %3$d users', 'talenttrack' ),
                        $diff_count, $known_count, $user_count
                    ) );
                } else {
                    echo esc_html( sprintf(
                        /* translators: 1: differing-cap count, 2: rows shown, 3: number of compared users */
                        __( '— %1$d differ of %2$d shown across %3$d users', 'talenttrack' ),
                        $diff_count, count( $rows ), $user_count
                    ) );
                }
                ?>
            </span>
        </h2>

        <?php if ( empty( $rows ) ) : ?>
            <p><em><?php esc_html_e( 'No differences. The picked users have identical effective capabilities across the matrix.', 'talenttrack' ); ?></em></p>
        <?php else : ?>
            <div style="overflow-x:auto;">
            <table class="widefat striped" style="font-size:12px;">
                <thead>
                    <tr>
                        <th style="width:18%;"><?php esc_html_e( 'Capability', 'talenttrack' ); ?></th>
                        <th style="width:14%;"><?php esc_html_e( 'Matrix entity:activity', 'talenttrack' ); ?></th>
                        <?php foreach ( $users as $u ) : ?>
                            <th style="width:<?php echo (int) $col_pct; ?>%;"><?php echo esc_html( $u->display_name ); ?></th>
                        <?php endforeach; ?>
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
                        <?php foreach ( $users as $u ) :
                            $pu = $r['per_user'][ (int) $u->ID ];
                        ?>
                            <td>
                                <?php echo self::renderCapState( $pu['legacy'], $pu['matrix'] ); ?>
                                <?php if ( $r['entity'] !== '' && is_array( $pu['description'] ) ) : ?>
                                    <details style="margin-top:4px;">
                                        <summary style="cursor:pointer; color:#5b6e75; font-size:11px;">
                                            <?php esc_html_e( 'which scope row grants this?', 'talenttrack' ); ?>
                                        </summary>
                                        <div style="margin-top:4px; font-family:monospace; font-size:11px; color:#5b6e75; word-break:break-word;">
                                            <?php echo self::describeMatrixRow( $pu['description'], $r['entity'], $r['activity'] ); ?>
                                        </div>
                                    </details>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
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
            </div>
            <p style="margin-top:10px; color:#5b6e75; font-size:12px;">
                <strong><?php esc_html_e( 'Legend:', 'talenttrack' ); ?></strong>
                <span style="color:#196a32;">✓ cap</span> = <?php esc_html_e( 'granted via WP role / legacy capability', 'talenttrack' ); ?>,
                <span style="color:#196a32;">✓ matrix</span> = <?php esc_html_e( 'granted via the authorization matrix', 'talenttrack' ); ?>,
                <span style="color:#b32d2e;">✗</span> = <?php esc_html_e( 'denied', 'talenttrack' ); ?>,
                <?php esc_html_e( 'yellow row = users differ.', 'talenttrack' ); ?>
            </p>
        <?php endif; ?>
        <?php
    }

    /**
     * Format a `MatrixGate::describeAccess()` result for display in the
     * per-cap drill-down disclosure.
     *
     * @param array{allowed:bool, persona:?string, scope_kind:?string, scope_value:?int, source_row_id:?int} $d
     */
    private static function describeMatrixRow( array $d, string $entity, string $activity ): string {
        if ( empty( $d['allowed'] ) ) {
            return '<em>' . esc_html__( 'no matrix row matches', 'talenttrack' ) . '</em>';
        }
        $parts = [
            'persona=' . (string) ( $d['persona'] ?? '?' ),
            $entity . ':' . $activity,
            'scope_kind=' . (string) ( $d['scope_kind'] ?? '?' ),
        ];
        if ( $d['scope_value'] !== null ) {
            $parts[] = 'scope_value=' . (int) $d['scope_value'];
        }
        if ( $d['source_row_id'] !== null ) {
            $parts[] = '#' . (int) $d['source_row_id'];
        }
        return esc_html( implode( ' / ', $parts ) );
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
