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
        self::renderHeader( __( 'Scout reports history', 'talenttrack' ) );

        if ( ! current_user_can( 'tt_generate_scout_report' ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'You need scout-management permission to view this page.', 'talenttrack' ) . '</p>';
            return;
        }

        $repo = new ScoutReportsRepository();

        if ( ! empty( $_POST['tt_scout_revoke_id'] ) && check_admin_referer( 'tt_scout_revoke', 'tt_scout_revoke_nonce' ) ) {
            $rid = absint( $_POST['tt_scout_revoke_id'] );
            if ( $rid > 0 && $repo->revoke( $rid ) ) {
                echo '<p class="tt-notice notice-success" style="background:#e9f5e9;border-left:4px solid #2c8a2c;padding:8px 12px;margin:8px 0 16px;">'
                    . esc_html__( 'Report revoked.', 'talenttrack' ) . '</p>';
            }
        }

        $rows = $is_admin ? $repo->listAll( 200 ) : $repo->listForGenerator( $user_id, 200 );
        ?>
        <p class="tt-help-text"><?php esc_html_e( 'Every scout report sent or assigned. Revoke an emailed link to invalidate it immediately.', 'talenttrack' ); ?></p>

        <?php if ( empty( $rows ) ) : ?>
            <p class="tt-notice"><?php esc_html_e( 'No scout reports yet.', 'talenttrack' ); ?></p>
        <?php else : ?>
            <table class="tt-table" style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr>
                        <th style="text-align:left;padding:8px;border-bottom:2px solid #1a1d21;"><?php esc_html_e( 'Player', 'talenttrack' ); ?></th>
                        <th style="text-align:left;padding:8px;border-bottom:2px solid #1a1d21;"><?php esc_html_e( 'Recipient', 'talenttrack' ); ?></th>
                        <th style="text-align:left;padding:8px;border-bottom:2px solid #1a1d21;"><?php esc_html_e( 'Audience', 'talenttrack' ); ?></th>
                        <th style="text-align:left;padding:8px;border-bottom:2px solid #1a1d21;"><?php esc_html_e( 'Sent', 'talenttrack' ); ?></th>
                        <th style="text-align:left;padding:8px;border-bottom:2px solid #1a1d21;"><?php esc_html_e( 'Expires', 'talenttrack' ); ?></th>
                        <th style="text-align:left;padding:8px;border-bottom:2px solid #1a1d21;"><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                        <th style="text-align:left;padding:8px;border-bottom:2px solid #1a1d21;"><?php esc_html_e( 'Views', 'talenttrack' ); ?></th>
                        <th style="text-align:left;padding:8px;border-bottom:2px solid #1a1d21;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $rows as $row ) :
                    $player = QueryHelpers::get_player( (int) $row->player_id );
                    $name   = $player ? QueryHelpers::player_display_name( $player ) : '—';
                    $is_link  = $row->audience === 'scout_emailed_link';
                    $recipient = $is_link ? (string) ( $row->recipient_email ?? '' ) : self::scoutDisplay( (int) ( $row->scout_user_id ?? 0 ) );
                    $status   = self::statusLabel( $row, $repo );
                    $can_revoke = $is_link && $repo->isAccessibleNow( $row );
                    ?>
                    <tr>
                        <td style="padding:8px;border-bottom:1px solid #eee;"><?php echo esc_html( $name ); ?></td>
                        <td style="padding:8px;border-bottom:1px solid #eee;"><?php echo esc_html( $recipient ); ?></td>
                        <td style="padding:8px;border-bottom:1px solid #eee;"><?php echo esc_html( self::audienceLabel( (string) $row->audience ) ); ?></td>
                        <td style="padding:8px;border-bottom:1px solid #eee;font-variant-numeric:tabular-nums;"><?php echo esc_html( (string) $row->created_at ); ?></td>
                        <td style="padding:8px;border-bottom:1px solid #eee;font-variant-numeric:tabular-nums;"><?php echo esc_html( (string) ( $row->expires_at ?? '—' ) ); ?></td>
                        <td style="padding:8px;border-bottom:1px solid #eee;"><?php echo esc_html( $status ); ?></td>
                        <td style="padding:8px;border-bottom:1px solid #eee;font-variant-numeric:tabular-nums;"><?php echo (int) $row->access_count; ?></td>
                        <td style="padding:8px;border-bottom:1px solid #eee;text-align:right;">
                            <?php if ( $can_revoke ) : ?>
                                <form method="post" style="margin:0;">
                                    <?php wp_nonce_field( 'tt_scout_revoke', 'tt_scout_revoke_nonce' ); ?>
                                    <input type="hidden" name="tt_scout_revoke_id" value="<?php echo (int) $row->id; ?>" />
                                    <button type="submit" class="tt-btn tt-btn-secondary" style="font-size:12px;color:#b32d2e;" onclick="return confirm('<?php echo esc_js( __( 'Revoke this report? The link will stop working immediately.', 'talenttrack' ) ); ?>');"><?php esc_html_e( 'Revoke', 'talenttrack' ); ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
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
