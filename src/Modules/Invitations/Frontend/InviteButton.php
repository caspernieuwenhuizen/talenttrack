<?php
namespace TT\Modules\Invitations\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Invitations\InvitationKind;
use TT\Modules\Invitations\InvitationService;
use TT\Modules\Invitations\InvitationsRepository;
use TT\Modules\Invitations\InvitationStatus;

/**
 * InviteButton — reusable share UI for player / parent / staff invites.
 *
 * Renders a "Share invite" button on three surfaces (frontend roster
 * row, wp-admin player edit, wp-admin people edit). On click it shows
 * a popover with:
 *   - The acceptance URL pre-filled in a copy-button input.
 *   - A WhatsApp share link (`wa.me/?text=...`).
 *   - An email share link (`mailto:?subject=...&body=...`), opt-in.
 *   - A live preview of the message text.
 *
 * If no pending invitation exists for the target, the popover offers
 * a "Generate invite" submit that creates one (and the redirect tags
 * `?tt_invite_id=<id>` so the popover re-opens already-populated).
 */
class InviteButton {

    /**
     * Render the share button + popover for a given target.
     *
     * @param array{
     *   kind: string,
     *   target_player_id?: int,
     *   target_person_id?: int,
     *   target_team_id?: int,
     *   target_functional_role_key?: string,
     *   prefill_first_name?: string,
     *   prefill_last_name?: string,
     *   prefill_email?: string,
     *   redirect: string
     * } $args
     */
    public static function render( array $args ): void {
        if ( ! current_user_can( 'tt_send_invitation' ) ) return;
        $kind = (string) ( $args['kind'] ?? InvitationKind::PLAYER );
        if ( ! InvitationKind::isValid( $kind ) ) return;

        $repo = new InvitationsRepository();
        $existing = $repo->findPendingFor(
            $kind,
            isset( $args['target_player_id'] ) ? (int) $args['target_player_id'] : null,
            isset( $args['target_person_id'] ) ? (int) $args['target_person_id'] : null
        );

        $popover_id = 'tt-invite-' . wp_generate_uuid4();
        $auto_open  = isset( $_GET['tt_invite_id'] ) && $existing && (int) $_GET['tt_invite_id'] === (int) $existing->id;

        ?>
        <div class="tt-invite-wrap" style="display:inline-block; position:relative;">
            <button type="button"
                    class="tt-btn tt-btn-secondary tt-invite-trigger"
                    data-target="<?php echo esc_attr( $popover_id ); ?>"
                    onclick="(function(b){var p=document.getElementById(b.dataset.target);if(p){p.style.display=p.style.display==='block'?'none':'block';}})(this)">
                <?php
                if ( $existing ) {
                    esc_html_e( 'Share invite', 'talenttrack' );
                } else {
                    esc_html_e( 'Generate invite', 'talenttrack' );
                }
                ?>
            </button>
            <div id="<?php echo esc_attr( $popover_id ); ?>"
                 class="tt-invite-popover"
                 style="display:<?php echo $auto_open ? 'block' : 'none'; ?>; position:absolute; top:calc(100% + 6px); right:0; z-index:50; width:380px; max-width:90vw; padding:14px; background:#fff; border:1px solid #d0d2d6; border-radius:8px; box-shadow:0 4px 14px rgba(0,0,0,.08);">

                <?php if ( $existing ) : ?>
                    <?php self::renderShareUI( $existing ); ?>
                <?php else : ?>
                    <?php self::renderCreateForm( $args ); ?>
                <?php endif; ?>

            </div>
        </div>
        <?php
    }

    private static function renderShareUI( object $invitation ): void {
        $service = new InvitationService();
        $url     = $service->acceptanceUrl( (string) $invitation->token );
        $message = $service->renderShareMessage( $invitation );
        $whatsapp = 'https://wa.me/?text=' . rawurlencode( $message );
        $mailto   = 'mailto:' . ( ! empty( $invitation->prefill_email ) ? rawurlencode( (string) $invitation->prefill_email ) : '' )
                    . '?subject=' . rawurlencode( __( 'Your TalentTrack invitation', 'talenttrack' ) )
                    . '&body=' . rawurlencode( $message );
        $expires = (string) $invitation->expires_at;

        ?>
        <div style="font-size:13px;">
            <p style="margin:0 0 8px; font-weight:600;">
                <?php esc_html_e( 'Share invitation', 'talenttrack' ); ?>
            </p>
            <p style="margin:0 0 6px; color:#666; font-size:12px;">
                <?php
                printf(
                    /* translators: %s = expiry date */
                    esc_html__( 'Valid until %s.', 'talenttrack' ),
                    esc_html( $expires )
                );
                ?>
            </p>

            <label style="display:block; font-weight:500; margin:8px 0 4px;">
                <?php esc_html_e( 'Acceptance URL', 'talenttrack' ); ?>
            </label>
            <input type="text" readonly value="<?php echo esc_attr( $url ); ?>"
                   onclick="this.select(); document.execCommand('copy');"
                   style="width:100%; padding:6px; font-family:monospace; font-size:11px;" />

            <label style="display:block; font-weight:500; margin:10px 0 4px;">
                <?php esc_html_e( 'Message preview', 'talenttrack' ); ?>
            </label>
            <textarea readonly rows="5"
                      style="width:100%; padding:6px; font-size:12px; resize:vertical;"><?php echo esc_textarea( $message ); ?></textarea>

            <p style="margin:12px 0 0; display:flex; gap:6px; flex-wrap:wrap;">
                <a class="tt-btn tt-btn-primary" target="_blank" rel="noopener noreferrer"
                   href="<?php echo esc_url( $whatsapp ); ?>"
                   style="background:#25d366; border-color:#25d366; color:#fff; flex:1; min-width:120px; text-align:center;">
                    <?php esc_html_e( 'Share via WhatsApp', 'talenttrack' ); ?>
                </a>
                <a class="tt-btn tt-btn-secondary" href="<?php echo esc_url( $mailto ); ?>"
                   style="flex:1; min-width:80px; text-align:center;">
                    <?php esc_html_e( 'Email', 'talenttrack' ); ?>
                </a>
            </p>

            <?php if ( current_user_can( 'tt_revoke_invitation' ) ) : ?>
                <details style="margin-top:10px;">
                    <summary style="cursor:pointer; color:#b32d2e; font-size:12px;">
                        <?php esc_html_e( 'Revoke', 'talenttrack' ); ?>
                    </summary>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:6px;"
                          onsubmit="return confirm('<?php echo esc_js( __( 'Revoke this invitation? The link will stop working immediately.', 'talenttrack' ) ); ?>');">
                        <?php wp_nonce_field( 'tt_invitation_revoke' ); ?>
                        <input type="hidden" name="action" value="tt_invitation_revoke" />
                        <input type="hidden" name="id" value="<?php echo (int) $invitation->id; ?>" />
                        <input type="hidden" name="_redirect" value="<?php echo esc_attr( self::currentUrl() ); ?>" />
                        <button type="submit" class="tt-btn tt-btn-secondary" style="font-size:12px;">
                            <?php esc_html_e( 'Confirm revoke', 'talenttrack' ); ?>
                        </button>
                    </form>
                </details>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @param array{
     *   kind: string,
     *   target_player_id?: int,
     *   target_person_id?: int,
     *   target_team_id?: int,
     *   target_functional_role_key?: string,
     *   prefill_first_name?: string,
     *   prefill_last_name?: string,
     *   prefill_email?: string,
     *   redirect: string
     * } $args
     */
    private static function renderCreateForm( array $args ): void {
        $kind = (string) $args['kind'];
        $cap_exceeded = isset( $_GET['tt_invite_cap'] );
        ?>
        <p style="margin:0 0 8px; font-weight:600; font-size:13px;">
            <?php
            printf(
                /* translators: %s = kind label */
                esc_html__( 'Generate %s invitation', 'talenttrack' ),
                esc_html( strtolower( InvitationKind::label( $kind ) ) )
            );
            ?>
        </p>
        <p style="margin:0 0 12px; color:#666; font-size:12px;">
            <?php esc_html_e( 'Creates a token + share link. The recipient picks their password on first follow-through.', 'talenttrack' ); ?>
        </p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'tt_invitation_create' ); ?>
            <input type="hidden" name="action" value="tt_invitation_create" />
            <input type="hidden" name="kind"   value="<?php echo esc_attr( $kind ); ?>" />
            <input type="hidden" name="_redirect" value="<?php echo esc_attr( (string) $args['redirect'] ); ?>" />
            <?php foreach ( [ 'target_player_id', 'target_person_id', 'target_team_id', 'target_functional_role_key', 'prefill_first_name', 'prefill_last_name', 'prefill_email' ] as $field ) : ?>
                <?php if ( isset( $args[ $field ] ) && $args[ $field ] !== '' && $args[ $field ] !== 0 ) : ?>
                    <input type="hidden" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( (string) $args[ $field ] ); ?>" />
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if ( $cap_exceeded ) : ?>
                <div style="margin:0 0 10px; padding:8px; background:#fff4f4; border:1px solid #f1baba; border-radius:6px; font-size:12px;">
                    <p style="margin:0 0 6px;"><?php esc_html_e( 'Daily invitation cap reached. Continue anyway with a reason for the audit log:', 'talenttrack' ); ?></p>
                    <input type="text" name="override_reason" required maxlength="255" style="width:100%; padding:6px;" placeholder="<?php esc_attr_e( 'e.g. season kick-off onboarding for 60 players', 'talenttrack' ); ?>" />
                    <input type="hidden" name="override_cap" value="1" />
                </div>
            <?php endif; ?>

            <button type="submit" class="tt-btn tt-btn-primary" style="width:100%;">
                <?php esc_html_e( 'Create invitation', 'talenttrack' ); ?>
            </button>
        </form>
        <?php
    }

    private static function currentUrl(): string {
        return isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : home_url( '/' );
    }
}
