<?php
namespace TT\Modules\Invitations\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Invitations\InvitationKind;
use TT\Modules\Invitations\InvitationService;
use TT\Modules\Invitations\InvitationStatus;
use TT\Modules\Invitations\InvitationsRepository;
use TT\Shared\Frontend\FrontendBackButton;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * InvitationsConfigView — Configuration → Invitations admin surface.
 *
 * One view, two tabs:
 *   - Invitations — paginated list of every invitation row, filter by
 *     status / kind, per-row revoke + copy-link actions.
 *   - Messages — the six default templates, one editor per (kind ×
 *     locale), `{url}` validation enforced on save.
 *
 * Cap-gated by `tt_manage_invite_messages` (administrator + Club Admin).
 */
class InvitationsConfigView extends FrontendViewBase {

    public static function render(): void {
        if ( ! current_user_can( 'tt_manage_invite_messages' ) ) {
            FrontendBackButton::render();
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to manage invitations.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        self::renderHeader( __( 'Invitations', 'talenttrack' ) );

        $tab = isset( $_GET['inv_tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['inv_tab'] ) ) : 'list';
        $base = self::baseUrl();
        $list_url     = add_query_arg( 'inv_tab', 'list', $base );
        $messages_url = add_query_arg( 'inv_tab', 'messages', $base );
        ?>
        <p style="margin:0 0 14px;">
            <a class="tt-btn <?php echo $tab === 'list' ? 'tt-btn-primary' : 'tt-btn-secondary'; ?>" href="<?php echo esc_url( $list_url ); ?>">
                <?php esc_html_e( 'Invitations', 'talenttrack' ); ?>
            </a>
            <a class="tt-btn <?php echo $tab === 'messages' ? 'tt-btn-primary' : 'tt-btn-secondary'; ?>" href="<?php echo esc_url( $messages_url ); ?>">
                <?php esc_html_e( 'Messages', 'talenttrack' ); ?>
            </a>
        </p>
        <?php

        if ( $tab === 'messages' ) {
            self::renderMessagesTab( $base );
        } else {
            self::renderListTab( $base );
        }
    }

    private static function renderListTab( string $base ): void {
        $repo = new InvitationsRepository();
        $repo->sweepExpired();

        $status = isset( $_GET['status'] ) ? sanitize_key( (string) wp_unslash( $_GET['status'] ) ) : '';
        $rows   = $repo->listAll( 200, $status !== '' ? $status : null );

        $filter_url = function ( string $s ) use ( $base ) {
            return $s === ''
                ? add_query_arg( 'inv_tab', 'list', $base )
                : add_query_arg( [ 'inv_tab' => 'list', 'status' => $s ], $base );
        };
        ?>
        <p style="margin:0 0 12px;">
            <strong><?php esc_html_e( 'Filter:', 'talenttrack' ); ?></strong>
            <a href="<?php echo esc_url( $filter_url( '' ) ); ?>"><?php esc_html_e( 'All', 'talenttrack' ); ?></a> ·
            <a href="<?php echo esc_url( $filter_url( InvitationStatus::PENDING ) ); ?>"><?php echo esc_html( InvitationStatus::label( InvitationStatus::PENDING ) ); ?></a> ·
            <a href="<?php echo esc_url( $filter_url( InvitationStatus::ACCEPTED ) ); ?>"><?php echo esc_html( InvitationStatus::label( InvitationStatus::ACCEPTED ) ); ?></a> ·
            <a href="<?php echo esc_url( $filter_url( InvitationStatus::EXPIRED ) ); ?>"><?php echo esc_html( InvitationStatus::label( InvitationStatus::EXPIRED ) ); ?></a> ·
            <a href="<?php echo esc_url( $filter_url( InvitationStatus::REVOKED ) ); ?>"><?php echo esc_html( InvitationStatus::label( InvitationStatus::REVOKED ) ); ?></a>
        </p>

        <?php if ( empty( $rows ) ) : ?>
            <p><em><?php esc_html_e( 'No invitations match the filter.', 'talenttrack' ); ?></em></p>
            <?php return; ?>
        <?php endif; ?>

        <table class="tt-table" style="width:100%;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Kind', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Target', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Inviter', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Created', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Expires', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php
            $service = new InvitationService();
            foreach ( $rows as $row ) :
                $accept_url = $service->acceptanceUrl( (string) $row->token );
                ?>
                <tr>
                    <td><?php echo esc_html( InvitationKind::label( (string) $row->kind ) ); ?></td>
                    <td><?php echo esc_html( self::targetLabel( $row ) ); ?></td>
                    <td><?php echo esc_html( self::userName( (int) $row->created_by ) ); ?></td>
                    <td><?php echo esc_html( (string) $row->created_at ); ?></td>
                    <td><?php echo esc_html( (string) $row->expires_at ); ?></td>
                    <td><?php echo esc_html( InvitationStatus::label( (string) $row->status ) ); ?></td>
                    <td>
                        <?php if ( (string) $row->status === InvitationStatus::PENDING ) : ?>
                            <input type="text" readonly value="<?php echo esc_attr( $accept_url ); ?>"
                                   onclick="this.select(); document.execCommand('copy');"
                                   style="width:200px; font-family:monospace; font-size:11px;" />
                            <?php if ( current_user_can( 'tt_revoke_invitation' ) ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin:0;"
                                      onsubmit="return confirm('<?php echo esc_js( __( 'Revoke this invitation?', 'talenttrack' ) ); ?>');">
                                    <?php wp_nonce_field( 'tt_invitation_revoke' ); ?>
                                    <input type="hidden" name="action" value="tt_invitation_revoke" />
                                    <input type="hidden" name="id" value="<?php echo (int) $row->id; ?>" />
                                    <input type="hidden" name="_redirect" value="<?php echo esc_attr( self::currentUrl() ); ?>" />
                                    <button type="submit" class="tt-btn tt-btn-secondary" style="font-size:11px;">
                                        <?php esc_html_e( 'Revoke', 'talenttrack' ); ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        <?php elseif ( (string) $row->status === InvitationStatus::ACCEPTED ) : ?>
                            <small><?php
                                printf(
                                    /* translators: 1: accepted date, 2: user name */
                                    esc_html__( '%1$s by %2$s', 'talenttrack' ),
                                    esc_html( (string) $row->accepted_at ),
                                    esc_html( self::userName( (int) ( $row->accepted_user_id ?? 0 ) ) )
                                );
                            ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function renderMessagesTab( string $base ): void {
        $config = new ConfigService();
        $current_url = add_query_arg( 'inv_tab', 'messages', $base );
        ?>
        <p style="color:#666; max-width:760px;">
            <?php esc_html_e( 'Six message templates — three role variants × two locales. Placeholders: {club} {role} {team} {player} {sender} {url} {ttl_days}. The {url} placeholder is required so the recipient can open the invitation.', 'talenttrack' ); ?>
        </p>

        <?php
        $kinds   = InvitationKind::all();
        $locales = [ 'en_US', 'nl_NL' ];

        foreach ( $kinds as $kind ) :
            ?>
            <h3 style="margin-top:24px;"><?php echo esc_html( InvitationKind::label( $kind ) ); ?></h3>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(360px, 1fr)); gap:16px;">
                <?php foreach ( $locales as $locale ) :
                    $key = "invite_message_{$kind}_{$locale}";
                    $body = $config->get( $key, '' );
                    ?>
                    <div style="padding:14px; background:#fff; border:1px solid #e5e7ea; border-radius:8px;">
                        <p style="margin:0 0 6px; font-weight:600; font-size:13px;"><?php echo esc_html( $locale ); ?></p>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <?php wp_nonce_field( 'tt_invitation_message_save' ); ?>
                            <input type="hidden" name="action" value="tt_invitation_message_save" />
                            <input type="hidden" name="kind"   value="<?php echo esc_attr( $kind ); ?>" />
                            <input type="hidden" name="locale" value="<?php echo esc_attr( $locale ); ?>" />
                            <input type="hidden" name="_redirect" value="<?php echo esc_attr( $current_url ); ?>" />
                            <textarea name="body" rows="6" style="width:100%; padding:8px; font-size:12px;"><?php echo esc_textarea( $body ); ?></textarea>
                            <p style="margin:6px 0 0;">
                                <button type="submit" class="tt-btn tt-btn-primary" style="font-size:12px;">
                                    <?php esc_html_e( 'Save', 'talenttrack' ); ?>
                                </button>
                            </p>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php
        endforeach;
    }

    private static function targetLabel( object $row ): string {
        global $wpdb;
        if ( ! empty( $row->target_player_id ) ) {
            $name = $wpdb->get_row( $wpdb->prepare(
                "SELECT first_name, last_name FROM {$wpdb->prefix}tt_players WHERE id = %d AND club_id = %d",
                (int) $row->target_player_id, CurrentClub::id()
            ) );
            if ( $name ) return trim( (string) $name->first_name . ' ' . (string) $name->last_name );
        }
        if ( ! empty( $row->target_person_id ) ) {
            $name = $wpdb->get_row( $wpdb->prepare(
                "SELECT first_name, last_name FROM {$wpdb->prefix}tt_people WHERE id = %d AND club_id = %d",
                (int) $row->target_person_id, CurrentClub::id()
            ) );
            if ( $name ) return trim( (string) $name->first_name . ' ' . (string) $name->last_name );
        }
        return '';
    }

    private static function userName( int $userId ): string {
        if ( $userId <= 0 ) return '';
        $u = get_userdata( $userId );
        return $u ? (string) $u->display_name : '#' . $userId;
    }

    private static function baseUrl(): string {
        $req = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : home_url( '/' );
        return remove_query_arg( [ 'inv_tab', 'status' ], $req );
    }

    private static function currentUrl(): string {
        return isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : home_url( '/' );
    }
}
