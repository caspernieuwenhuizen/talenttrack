<?php
namespace TT\Modules\Invitations\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Invitations\InvitationKind;
use TT\Modules\Invitations\InvitationsRepository;
use TT\Modules\Invitations\InvitationStatus;
use TT\Modules\Invitations\InvitationToken;

/**
 * AcceptanceView — renders `?tt_view=accept-invite&token=<token>`.
 *
 * No auth required (the token is the credential). Shows three role
 * variants of the same form:
 *   - Player → recovery email + password + jersey number
 *   - Parent → recovery email + password + relationship label + notify checkbox
 *   - Staff  → recovery email + password (functional role + team are read-only confirms)
 *
 * Posts to InvitationAcceptHandler. On success the handler sets the
 * auth cookie and redirects to the dashboard with a welcome flash.
 */
class AcceptanceView {

    public static function render(): void {
        $token = isset( $_GET['token'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['token'] ) ) : '';

        if ( ! InvitationToken::isValidShape( $token ) ) {
            self::renderNotice( __( 'This invitation link is invalid.', 'talenttrack' ) );
            return;
        }

        $repo = new InvitationsRepository();
        $repo->sweepExpired();
        $row = $repo->findByToken( $token );
        if ( ! $row ) {
            self::renderNotice( __( 'This invitation could not be found. It may have been revoked.', 'talenttrack' ) );
            return;
        }

        $status = (string) $row->status;
        if ( $status === InvitationStatus::ACCEPTED ) {
            self::renderNotice( __( 'This invitation has already been accepted. Sign in to your account.', 'talenttrack' ) );
            return;
        }
        if ( $status === InvitationStatus::REVOKED ) {
            self::renderNotice( __( 'This invitation was revoked.', 'talenttrack' ) );
            return;
        }
        if ( $status === InvitationStatus::EXPIRED || strtotime( (string) $row->expires_at ) < time() ) {
            self::renderNotice( __( 'This invitation has expired. Ask the inviter to send a new one.', 'talenttrack' ) );
            return;
        }

        if ( is_user_logged_in() ) {
            $current = wp_get_current_user();
            $matches = $current && $row->prefill_email && strcasecmp( (string) $current->user_email, (string) $row->prefill_email ) === 0;
            if ( $matches ) {
                self::renderSilentLinkConfirm( $token, (string) $row->kind );
                return;
            }
            self::renderSignOutInterstitial( $token, (string) $current->user_email, (string) ( $row->prefill_email ?? '' ) );
            return;
        }

        self::renderForm( $row );
    }

    private static function renderForm( object $row ): void {
        $kind   = (string) $row->kind;
        $first  = (string) ( $row->prefill_first_name ?? '' );
        $last   = (string) ( $row->prefill_last_name  ?? '' );
        $email  = (string) ( $row->prefill_email      ?? '' );
        $token  = (string) $row->token;
        $club_name = self::clubName();
        ?>
        <div class="tt-accept-invite" style="max-width:520px; margin:40px auto; padding:24px; background:#fff; border:1px solid #e5e7ea; border-radius:10px;">
            <h1 style="margin:0 0 8px; font-size:22px;">
                <?php
                printf(
                    /* translators: 1: club name, 2: invitation kind label */
                    esc_html__( 'Welcome to %1$s on TalentTrack — accepting your %2$s invitation', 'talenttrack' ),
                    esc_html( $club_name ),
                    esc_html( strtolower( InvitationKind::label( $kind ) ) )
                );
                ?>
            </h1>
            <p style="color:#666; margin:0 0 18px;">
                <?php esc_html_e( 'Set up your account in 30 seconds. We only collect what we need to log you in and recover your account if you forget the password.', 'talenttrack' ); ?>
            </p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_invitation_accept' ); ?>
                <input type="hidden" name="action" value="tt_invitation_accept" />
                <input type="hidden" name="token"  value="<?php echo esc_attr( $token ); ?>" />
                <input type="hidden" name="_redirect" value="<?php echo esc_attr( self::baseUrl() ); ?>" />

                <fieldset style="border:none; padding:0; margin:0 0 18px;">
                    <legend style="font-weight:600; padding:0; margin:0 0 10px;">
                        <?php esc_html_e( 'Your account', 'talenttrack' ); ?>
                    </legend>

                    <p style="margin:0 0 12px;">
                        <label style="display:block; font-weight:500;">
                            <?php esc_html_e( 'Email (for password recovery)', 'talenttrack' ); ?>
                        </label>
                        <input type="email" name="recovery_email" required value="<?php echo esc_attr( $email ); ?>" style="width:100%; padding:8px;" />
                    </p>

                    <p style="margin:0 0 12px;">
                        <label style="display:block; font-weight:500;">
                            <?php esc_html_e( 'Password (8+ characters)', 'talenttrack' ); ?>
                        </label>
                        <input type="password" name="password" required minlength="8" autocomplete="new-password" style="width:100%; padding:8px;" />
                    </p>
                </fieldset>

                <fieldset style="border:none; padding:0; margin:0 0 18px;">
                    <legend style="font-weight:600; padding:0; margin:0 0 10px;">
                        <?php
                        switch ( $kind ) {
                            case InvitationKind::PLAYER: esc_html_e( 'Player details', 'talenttrack' ); break;
                            case InvitationKind::PARENT: esc_html_e( 'Your relationship', 'talenttrack' ); break;
                            case InvitationKind::STAFF:  esc_html_e( 'Your role', 'talenttrack' ); break;
                        }
                        ?>
                    </legend>

                    <?php if ( $kind === InvitationKind::PLAYER ) : ?>
                        <p style="margin:0 0 12px;">
                            <label style="display:block; font-weight:500;">
                                <?php esc_html_e( 'Jersey number (optional)', 'talenttrack' ); ?>
                            </label>
                            <input type="number" name="jersey_number" min="0" max="999" inputmode="numeric" style="width:120px; padding:8px;" />
                        </p>
                        <p style="color:#777; font-size:12px; margin:0;">
                            <?php
                            printf(
                                /* translators: %s = player name */
                                esc_html__( 'Your profile is already set up as %s. You can edit your photo + DOB after sign-in.', 'talenttrack' ),
                                esc_html( trim( $first . ' ' . $last ) )
                            );
                            ?>
                        </p>
                    <?php elseif ( $kind === InvitationKind::PARENT ) : ?>
                        <p style="margin:0 0 12px;">
                            <label style="display:block; font-weight:500;">
                                <?php esc_html_e( 'Your relationship to the player', 'talenttrack' ); ?>
                            </label>
                            <select name="relationship_label" style="padding:8px;">
                                <option value="parent"><?php esc_html_e( 'Parent', 'talenttrack' ); ?></option>
                                <option value="mother"><?php esc_html_e( 'Mother', 'talenttrack' ); ?></option>
                                <option value="father"><?php esc_html_e( 'Father', 'talenttrack' ); ?></option>
                                <option value="guardian"><?php esc_html_e( 'Guardian', 'talenttrack' ); ?></option>
                            </select>
                        </p>
                        <p style="margin:0;">
                            <label style="font-weight:500;">
                                <input type="checkbox" name="notify_on_progress" value="1" />
                                <?php esc_html_e( 'Email me when there are new evaluations or goals.', 'talenttrack' ); ?>
                            </label>
                        </p>
                    <?php else : ?>
                        <p style="color:#777; font-size:12px; margin:0;">
                            <?php
                            $role_label = self::staffRoleLabel( (string) ( $row->target_functional_role_key ?? '' ) );
                            $team_label = self::teamName( (int) ( $row->target_team_id ?? 0 ) );
                            if ( $role_label !== '' || $team_label !== '' ) {
                                printf(
                                    /* translators: 1: role label, 2: team name */
                                    esc_html__( 'You are joining as %1$s%2$s. The role + team are confirmed by your inviter.', 'talenttrack' ),
                                    esc_html( $role_label ),
                                    $team_label !== '' ? ' (' . esc_html( $team_label ) . ')' : ''
                                );
                            } else {
                                esc_html_e( 'Your role + team are confirmed by your inviter.', 'talenttrack' );
                            }
                            ?>
                        </p>
                    <?php endif; ?>
                </fieldset>

                <p style="margin:0;">
                    <button type="submit" class="tt-btn tt-btn-primary" style="width:100%; padding:10px;">
                        <?php esc_html_e( 'Create account & sign in', 'talenttrack' ); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    private static function renderSilentLinkConfirm( string $token, string $kind ): void {
        ?>
        <div class="tt-accept-invite" style="max-width:520px; margin:40px auto; padding:24px; background:#fff; border:1px solid #e5e7ea; border-radius:10px;">
            <h1 style="margin:0 0 12px; font-size:22px;">
                <?php esc_html_e( 'Accept invitation', 'talenttrack' ); ?>
            </h1>
            <p style="color:#666; margin:0 0 16px;">
                <?php
                printf(
                    /* translators: %s = invitation kind label */
                    esc_html__( 'You are signed in. Tap accept to link the %s invitation to your existing account.', 'talenttrack' ),
                    esc_html( strtolower( InvitationKind::label( $kind ) ) )
                );
                ?>
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_invitation_accept' ); ?>
                <input type="hidden" name="action" value="tt_invitation_accept" />
                <input type="hidden" name="token"  value="<?php echo esc_attr( $token ); ?>" />
                <input type="hidden" name="_redirect" value="<?php echo esc_attr( self::baseUrl() ); ?>" />
                <button type="submit" class="tt-btn tt-btn-primary" style="width:100%; padding:10px;">
                    <?php esc_html_e( 'Accept and continue', 'talenttrack' ); ?>
                </button>
            </form>
        </div>
        <?php
    }

    private static function renderSignOutInterstitial( string $token, string $current_email, string $invite_email ): void {
        $logout_url = wp_logout_url( add_query_arg( [ 'tt_view' => 'accept-invite', 'token' => $token ], self::baseUrl() ) );
        ?>
        <div class="tt-accept-invite" style="max-width:520px; margin:40px auto; padding:24px; background:#fff; border:1px solid #e5e7ea; border-radius:10px;">
            <h1 style="margin:0 0 12px; font-size:22px;">
                <?php esc_html_e( 'Sign out to accept this invitation?', 'talenttrack' ); ?>
            </h1>
            <p style="color:#666; margin:0 0 16px;">
                <?php
                printf(
                    /* translators: 1: current logged-in email, 2: invited email */
                    esc_html__( 'You are signed in as %1$s, but this invitation is addressed to %2$s. Sign out and accept as the invited user, or open the link from a different browser.', 'talenttrack' ),
                    esc_html( $current_email ),
                    esc_html( $invite_email !== '' ? $invite_email : __( 'a different account', 'talenttrack' ) )
                );
                ?>
            </p>
            <p style="margin:0;">
                <a class="tt-btn tt-btn-primary" href="<?php echo esc_url( $logout_url ); ?>" style="display:inline-block; padding:10px 20px;">
                    <?php esc_html_e( 'Sign out and accept', 'talenttrack' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    private static function renderNotice( string $msg ): void {
        echo '<div class="tt-accept-invite" style="max-width:520px; margin:40px auto; padding:24px; background:#fff; border:1px solid #e5e7ea; border-radius:10px;">';
        echo '<p>' . esc_html( $msg ) . '</p>';
        echo '</div>';
    }

    private static function clubName(): string {
        $config = new \TT\Infrastructure\Config\ConfigService();
        $name = $config->get( 'academy_name', 'TalentTrack' );
        return $name !== '' ? $name : 'TalentTrack';
    }

    private static function teamName( int $teamId ): string {
        if ( $teamId <= 0 ) return '';
        global $wpdb;
        $name = $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}tt_teams WHERE id = %d AND club_id = %d",
            $teamId, CurrentClub::id()
        ) );
        return $name ? (string) $name : '';
    }

    private static function staffRoleLabel( string $key ): string {
        if ( $key === '' ) return '';
        global $wpdb;
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$wpdb->prefix}tt_functional_roles" ) );
        if ( $exists !== "{$wpdb->prefix}tt_functional_roles" ) return $key;
        $label = $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}tt_functional_roles WHERE role_key = %s AND club_id = %d",
            $key, CurrentClub::id()
        ) );
        return $label ? (string) $label : $key;
    }

    private static function baseUrl(): string {
        $req = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : home_url( '/' );
        return remove_query_arg( [ 'tt_view', 'token' ], $req );
    }
}
