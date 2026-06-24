<?php
namespace TT\Modules\Reports\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Reports\ScoutReportsRepository;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendScoutHistoryView — audit / revoke list for scout reports.
 *
 * #0014 Sprint 5. Lists every persisted `tt_player_reports` row, most
 * recent first, with revoke action on active emailed-link rows.
 */
class FrontendScoutHistoryView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
            __( 'Scout reports history', 'talenttrack' ),
            [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'reports', __( 'Reports', 'talenttrack' ) ) ]
        );
        self::renderHeader( __( 'Scout reports history', 'talenttrack' ) );

        // v3.85.5 — Scout access is Pro-tier per FeatureMap.
        if ( class_exists( '\\TT\\Modules\\License\\LicenseGate' )
             && ! \TT\Modules\License\LicenseGate::allows( 'scout_access' )
        ) {
            echo \TT\Modules\License\Admin\UpgradeNudge::inline( __( 'Scout access', 'talenttrack' ), 'pro' );
            return;
        }

        if ( ! current_user_can( 'tt_generate_scout_report' ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'You need scout-management permission to view this page.', 'talenttrack' ) . '</p>';
            return;
        }

        $repo = new ScoutReportsRepository();

        if ( ! empty( $_POST['tt_scout_revoke_id'] ) && check_admin_referer( 'tt_scout_revoke', 'tt_scout_revoke_nonce' ) ) {
            $rid = absint( $_POST['tt_scout_revoke_id'] );
            if ( $rid > 0 && $repo->revoke( $rid ) ) {
                echo '<p class="tt-notice notice-success">' . esc_html__( 'Report revoked.', 'talenttrack' ) . '</p>';
            }
        }

        $rows   = $is_admin ? $repo->listAll( 200 ) : $repo->listForGenerator( $user_id, 200 );
        $chrome = \TT\Shared\Frontend\Components\FrontendAppChrome::class;

        // KPI strip — totals by status (computed once, reused below).
        $statuses = [];
        foreach ( $rows as $row ) {
            $statuses[] = self::statusKey( $row );
        }
        $total   = count( $rows );
        $active  = count( array_filter( $statuses, static fn( $s ) => $s === 'active' ) );
        $revoked = count( array_filter( $statuses, static fn( $s ) => $s === 'revoked' ) );
        $expired = count( array_filter( $statuses, static fn( $s ) => $s === 'expired' ) );
        ?>
        <p class="tt-sr-intro"><?php esc_html_e( 'Every scout report sent or assigned. Revoke an emailed link to invalidate it immediately.', 'talenttrack' ); ?></p>

        <?php if ( ! empty( $rows ) ) : ?>
            <div class="tt-sr-kpis" role="group" aria-label="<?php esc_attr_e( 'Scout report totals', 'talenttrack' ); ?>">
                <?php
                // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped — kpiTile escapes.
                echo $chrome::kpiTile( [ 'label' => __( 'Reports', 'talenttrack' ), 'value' => (string) $total ] );
                echo $chrome::kpiTile( [ 'label' => __( 'Active', 'talenttrack' ), 'value' => (string) $active, 'flag' => 'green' ] );
                echo $chrome::kpiTile( [ 'label' => __( 'Revoked', 'talenttrack' ), 'value' => (string) $revoked, 'flag' => $revoked > 0 ? 'red' : '' ] );
                echo $chrome::kpiTile( [ 'label' => __( 'Expired', 'talenttrack' ), 'value' => (string) $expired ] );
                // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
                ?>
            </div>
        <?php endif; ?>

        <?php if ( empty( $rows ) ) : ?>
            <p class="tt-notice"><?php esc_html_e( 'No scout reports yet.', 'talenttrack' ); ?></p>
        <?php else : ?>
            <div class="tt-sh-tablewrap">
            <table class="tt-table tt-sh-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Player', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Recipient', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Audience', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Sent', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Expires', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Views', 'talenttrack' ); ?></th>
                        <th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'talenttrack' ); ?></span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $rows as $row ) :
                    $player = QueryHelpers::get_player( (int) $row->player_id );
                    $name   = $player ? QueryHelpers::player_display_name( $player ) : '—';
                    $is_link  = $row->audience === 'scout_emailed_link';
                    $recipient = $is_link ? (string) ( $row->recipient_email ?? '' ) : self::scoutDisplay( (int) ( $row->scout_user_id ?? 0 ) );
                    $status_key = self::statusKey( $row );
                    $chip_mod   = $status_key === 'active' ? 'green' : ( $status_key === 'revoked' ? 'red' : 'gold' );
                    $can_revoke = $is_link && $repo->isAccessibleNow( $row );
                    ?>
                    <tr>
                        <td><?php echo esc_html( $name ); ?></td>
                        <td><?php echo esc_html( $recipient ); ?></td>
                        <td><?php echo esc_html( self::audienceLabel( (string) $row->audience ) ); ?></td>
                        <td class="tt-sh-num"><?php echo esc_html( \TT\Shared\Dates\TTDate::dateTime( (string) $row->created_at ) ); ?></td>
                        <td class="tt-sh-num"><?php echo esc_html( ( $row->expires_at ?? '' ) !== '' ? \TT\Shared\Dates\TTDate::dateTime( (string) $row->expires_at ) : '—' ); ?></td>
                        <td><span class="tt-sr-chip tt-sr-chip--<?php echo esc_attr( $chip_mod ); ?>"><?php echo esc_html( self::statusLabel( $row, $repo ) ); ?></span></td>
                        <td class="tt-sh-num"><?php echo (int) $row->access_count; ?></td>
                        <td class="tt-sh-actions">
                            <?php if ( $can_revoke ) : ?>
                                <form method="post">
                                    <?php wp_nonce_field( 'tt_scout_revoke', 'tt_scout_revoke_nonce' ); ?>
                                    <input type="hidden" name="tt_scout_revoke_id" value="<?php echo (int) $row->id; ?>" />
                                    <button type="submit" class="tt-btn tt-btn-danger" onclick="return confirm('<?php echo esc_js( __( 'Revoke this report? The link will stop working immediately.', 'talenttrack' ) ); ?>');"><?php esc_html_e( 'Revoke', 'talenttrack' ); ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Status as a machine key (active|revoked|expired) for chip + KPI
     * bucketing. Mirrors statusLabel()'s ordering.
     */
    private static function statusKey( object $row ): string {
        if ( ! empty( $row->revoked_at ) ) return 'revoked';
        if ( ! empty( $row->expires_at ) && strtotime( (string) $row->expires_at ) < time() ) return 'expired';
        return 'active';
    }

    private static function audienceLabel( string $audience ): string {
        switch ( $audience ) {
            case 'scout_emailed_link':     return __( 'Emailed link', 'talenttrack' );
            case 'scout_assigned_account': return __( 'Assigned account', 'talenttrack' );
            default:                       return $audience;
        }
    }

    private static function statusLabel( object $row, ScoutReportsRepository $repo ): string {
        if ( ! empty( $row->revoked_at ) )                      return __( 'Revoked', 'talenttrack' );
        if ( ! empty( $row->expires_at ) && strtotime( (string) $row->expires_at ) < time() ) return __( 'Expired', 'talenttrack' );
        return __( 'Active', 'talenttrack' );
    }

    private static function scoutDisplay( int $scout_user_id ): string {
        if ( $scout_user_id <= 0 ) return '—';
        $user = get_user_by( 'id', $scout_user_id );
        return $user ? (string) $user->display_name : (string) $scout_user_id;
    }
}
