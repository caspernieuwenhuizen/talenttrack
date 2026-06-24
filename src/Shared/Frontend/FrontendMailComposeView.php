<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Kernel;
use TT\Infrastructure\Audit\AuditService;
use TT\Infrastructure\People\PeopleRepository;
use TT\Shared\Frontend\Components\FormSaveButton;

/**
 * FrontendMailComposeView — in-product email composer (#0063).
 *
 * Reachable via `?tt_view=mail-compose&person_id=N`. Sends via
 * `wp_mail()` (so the academy's configured SMTP / mailer plugin
 * handles deliverability). Every send writes a `mail_sent` audit
 * row keyed on the recipient person id, with subject + first 256
 * chars of the body in the payload.
 *
 * Cap-gated on `tt_send_email`. Granted to admin / club_admin /
 * head_dev / coach. Replaces the bare `mailto:` link the People
 * page used previously, giving the academy a single send-from
 * address + an audit trail without depending on each coach's
 * personal mail client.
 */
final class FrontendMailComposeView extends FrontendViewBase {

    public const NONCE_ACTION = 'tt_mail_compose';
    public const NONCE_FIELD  = '_tt_mail_compose_nonce';

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_send_email' ) ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to send email through TalentTrack.', 'talenttrack' ) . '</p>';
            return;
        }

        $person_id = isset( $_GET['person_id'] ) ? absint( $_GET['person_id'] ) : 0;
        $person    = $person_id > 0 ? ( new PeopleRepository() )->find( $person_id ) : null;

        // v3.92.1 — breadcrumb. When composing for a specific person,
        // chain through People; otherwise just Dashboard → Compose mail.
        if ( $person ) {
            $person_name = trim( ( $person->first_name ?? '' ) . ' ' . ( $person->last_name ?? '' ) );
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
                /* translators: %s: person display name */
                sprintf( __( 'Compose email to %s', 'talenttrack' ), $person_name ),
                [
                    \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'people', __( 'People', 'talenttrack' ) ),
                    \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'people', $person_name, [ 'id' => $person_id ] ),
                ]
            );
        } else {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Compose email', 'talenttrack' ) );
        }

        $dash = \TT\Shared\Frontend\Components\RecordLink::dashboardUrl();
        $back_url = $person_id > 0
            ? add_query_arg( [ 'tt_view' => 'people', 'id' => $person_id ], $dash )
            : add_query_arg( [ 'tt_view' => 'people' ], $dash );

        if ( ! $person || empty( $person->email ) ) {
            self::renderHeader( __( 'Compose email', 'talenttrack' ) );
            echo '<p><em>' . esc_html__( 'No recipient on file.', 'talenttrack' ) . '</em></p>';
            return;
        }

        // POST handler — fall through to render after on success so the
        // user sees the confirmation banner inline rather than a redirect.
        $sent_ok = null;
        $error   = '';
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST[ self::NONCE_FIELD ] ) ) {
            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
                $error = __( 'Security check failed. Please reload the page and try again.', 'talenttrack' );
            } else {
                $subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['subject'] ) ) : '';
                $body    = isset( $_POST['body'] )    ? wp_kses_post( wp_unslash( (string) $_POST['body'] ) )           : '';
                if ( $subject === '' || trim( wp_strip_all_tags( $body ) ) === '' ) {
                    $error = __( 'Subject and message body are both required.', 'talenttrack' );
                } else {
                    $sent_ok = (bool) wp_mail( (string) $person->email, $subject, $body );
                    self::recordAudit( (int) $person->id, $subject, $body, $sent_ok );
                }
            }
        }

        $name  = trim( ( (string) ( $person->first_name ?? '' ) ) . ' ' . ( (string) ( $person->last_name ?? '' ) ) );
        $email = (string) $person->email;

        self::enqueueAssets();
        self::enqueueViewCss();
        self::renderHeader( sprintf(
            /* translators: %s = recipient name */
            __( 'Compose email to %s', 'talenttrack' ),
            $name !== '' ? $name : $email
        ) );

        if ( $sent_ok === true ) {
            echo '<div class="tt-notice tt-notice-success">'
                . esc_html__( 'Email sent. A copy has been logged to the audit trail.', 'talenttrack' )
                . '</div>';
        } elseif ( $sent_ok === false ) {
            echo '<div class="tt-notice tt-notice-error">'
                . esc_html__( "wp_mail returned false. The audit log captured the attempt; check the site's SMTP / mailer configuration.", 'talenttrack' )
                . '</div>';
        }
        if ( $error !== '' ) {
            echo '<div class="tt-notice tt-notice-error">' . esc_html( $error ) . '</div>';
        }

        $persisted_subject = $sent_ok ? '' : (string) ( $_POST['subject'] ?? '' );
        $persisted_body    = $sent_ok ? '' : (string) ( $_POST['body']    ?? '' );
        ?>
        <form method="post" class="tt-mail-compose">
            <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
            <p class="tt-mail-field">
                <label class="tt-field-label" for="tt-mail-to"><?php esc_html_e( 'To', 'talenttrack' ); ?></label>
                <input type="text" id="tt-mail-to" class="tt-input" value="<?php echo esc_attr( $name !== '' ? $name . ' <' . $email . '>' : $email ); ?>" disabled />
            </p>
            <p class="tt-mail-field">
                <label class="tt-field-label tt-field-required" for="tt-mail-subject"><?php esc_html_e( 'Subject', 'talenttrack' ); ?></label>
                <input type="text" id="tt-mail-subject" class="tt-input" name="subject" required value="<?php echo esc_attr( $persisted_subject ); ?>" />
            </p>
            <p class="tt-mail-field">
                <label class="tt-field-label tt-field-required" for="tt-mail-body"><?php esc_html_e( 'Message', 'talenttrack' ); ?></label>
                <textarea id="tt-mail-body" class="tt-input" name="body" rows="10" required><?php echo esc_textarea( $persisted_body ); ?></textarea>
                <small class="tt-mail-hint"><?php esc_html_e( "Sends from the academy's configured email address. Every send is recorded in the audit log.", 'talenttrack' ); ?></small>
            </p>
            <?php echo FormSaveButton::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                'label'      => __( 'Send email', 'talenttrack' ),
                'cancel_url' => $back_url,
            ] ); ?>
        </form>
        <?php
    }

    private static function enqueueViewCss(): void {
        wp_enqueue_style(
            'tt-frontend-mail-compose',
            TT_PLUGIN_URL . 'assets/css/frontend-mail-compose.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
    }

    private static function recordAudit( int $person_id, string $subject, string $body, bool $sent_ok ): void {
        try {
            $audit = Kernel::instance()->container()->get( 'audit' );
            if ( $audit instanceof AuditService ) {
                $audit->record(
                    'mail_sent',
                    'person',
                    $person_id,
                    [
                        'subject'   => $subject,
                        'body_head' => mb_substr( wp_strip_all_tags( $body ), 0, 256 ),
                        'sent_ok'   => $sent_ok ? 1 : 0,
                    ]
                );
            }
        } catch ( \Throwable $e ) {
            // Never let an audit failure interrupt the user's send flow.
        }
    }
}
