<?php
namespace TTB\Pages;

use TTB\Layout;
use TTB\Settings;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ContactPage — single contact form, posts to admin-post.php and
 * emails the configured recipient.
 *
 * Anti-spam: a hidden honeypot field plus a nonce. We deliberately
 * don't add captcha — too noisy for a B2B audience.
 *
 * Status messages are passed through ?ttb_contact=sent|error|invalid
 * by handleSubmit() so a refresh doesn't resubmit the form.
 */
final class ContactPage {

    /** @param array<string, mixed>|string $atts */
    public static function render( $atts = [] ): string {
        $action = esc_url( admin_url( 'admin-post.php' ) );
        $nonce  = wp_create_nonce( 'ttb_contact' );
        $status = isset( $_GET['ttb_contact'] ) ? sanitize_key( (string) $_GET['ttb_contact'] ) : '';

        $banner = '';
        if ( $status === 'sent' ) {
            $banner = '<div class="ttb-banner ttb-banner--ok">' . esc_html__( 'Thanks — message received. I\'ll reply within two business days.', 'talenttrack-branding' ) . '</div>';
        } elseif ( $status === 'invalid' ) {
            $banner = '<div class="ttb-banner ttb-banner--err">' . esc_html__( 'Some fields look off. Please check and try again.', 'talenttrack-branding' ) . '</div>';
        } elseif ( $status === 'error' ) {
            $banner = '<div class="ttb-banner ttb-banner--err">' . esc_html__( 'Something went wrong sending the message. Try again, or email us directly.', 'talenttrack-branding' ) . '</div>';
        }

        $email = (string) Settings::get( 'contact_email', '' );

        ob_start();
        ?>
        <section class="ttb-page-head">
            <div class="ttb-container">
                <span class="ttb-eyebrow"><?php esc_html_e( 'Contact', 'talenttrack-branding' ); ?></span>
                <h1><?php esc_html_e( 'Send a message', 'talenttrack-branding' ); ?></h1>
                <p><?php esc_html_e( 'Pilot applications, demo requests, awkward questions about pricing — anything. You\'ll get a real reply.', 'talenttrack-branding' ); ?></p>
            </div>
        </section>

        <section class="ttb-section">
            <div class="ttb-container ttb-contact">
                <?php echo $banner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

                <form class="ttb-form" method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
                    <input type="hidden" name="action" value="ttb_contact" />
                    <input type="hidden" name="_ttb_nonce" value="<?php echo esc_attr( $nonce ); ?>" />

                    <p class="ttb-form__hp" aria-hidden="true">
                        <label>Don't fill this in <input type="text" name="ttb_hp" value="" tabindex="-1" autocomplete="off" /></label>
                    </p>

                    <div class="ttb-form__row">
                        <label for="ttb-name"><?php esc_html_e( 'Name', 'talenttrack-branding' ); ?></label>
                        <input id="ttb-name" type="text" name="ttb_name" required maxlength="120" />
                    </div>

                    <div class="ttb-form__row">
                        <label for="ttb-email"><?php esc_html_e( 'Email', 'talenttrack-branding' ); ?></label>
                        <input id="ttb-email" type="email" name="ttb_email" required maxlength="160" />
                    </div>

                    <div class="ttb-form__row">
                        <label for="ttb-club"><?php esc_html_e( 'Club / academy (optional)', 'talenttrack-branding' ); ?></label>
                        <input id="ttb-club" type="text" name="ttb_club" maxlength="160" />
                    </div>

                    <div class="ttb-form__row">
                        <label for="ttb-topic"><?php esc_html_e( 'Topic', 'talenttrack-branding' ); ?></label>
                        <select id="ttb-topic" name="ttb_topic">
                            <option value="general"><?php esc_html_e( 'General question', 'talenttrack-branding' ); ?></option>
                            <option value="pilot"><?php esc_html_e( 'Pilot programme application', 'talenttrack-branding' ); ?></option>
                            <option value="demo"><?php esc_html_e( 'Guided demo request', 'talenttrack-branding' ); ?></option>
                            <option value="pricing"><?php esc_html_e( 'Pricing question', 'talenttrack-branding' ); ?></option>
                            <option value="press"><?php esc_html_e( 'Press / partnership', 'talenttrack-branding' ); ?></option>
                        </select>
                    </div>

                    <div class="ttb-form__row">
                        <label for="ttb-message"><?php esc_html_e( 'Message', 'talenttrack-branding' ); ?></label>
                        <textarea id="ttb-message" name="ttb_message" required rows="6" maxlength="5000"></textarea>
                    </div>

                    <div class="ttb-form__actions">
                        <button type="submit" class="ttb-btn ttb-btn--primary"><?php esc_html_e( 'Send message', 'talenttrack-branding' ); ?></button>
                        <?php if ( $email !== '' ) : ?>
                            <span class="ttb-form__or">
                                <?php
                                printf(
                                    /* translators: %s is the contact email address rendered as a mailto link */
                                    esc_html__( 'or email %s directly', 'talenttrack-branding' ),
                                    '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>'
                                );
                                ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </section>
        <?php
        return Layout::wrap( 'contact', (string) ob_get_clean() );
    }

    /**
     * admin-post.php handler. Validates, sends, redirects with a
     * status code in the query string so refreshes don't resubmit.
     */
    public static function handleSubmit(): void {
        $back = self::contactUrl();

        if ( ! isset( $_POST['_ttb_nonce'] ) || ! wp_verify_nonce( (string) $_POST['_ttb_nonce'], 'ttb_contact' ) ) {
            wp_safe_redirect( add_query_arg( 'ttb_contact', 'invalid', $back ) ); exit;
        }

        // Honeypot — bots happily fill this; humans don't see it.
        if ( ! empty( $_POST['ttb_hp'] ) ) {
            wp_safe_redirect( add_query_arg( 'ttb_contact', 'sent', $back ) ); exit; // pretend success
        }

        $name    = sanitize_text_field( wp_unslash( (string) ( $_POST['ttb_name']    ?? '' ) ) );
        $email   = sanitize_email(      wp_unslash( (string) ( $_POST['ttb_email']   ?? '' ) ) );
        $club    = sanitize_text_field( wp_unslash( (string) ( $_POST['ttb_club']    ?? '' ) ) );
        $topic   = sanitize_key(        wp_unslash( (string) ( $_POST['ttb_topic']   ?? 'general' ) ) );
        $message = sanitize_textarea_field( wp_unslash( (string) ( $_POST['ttb_message'] ?? '' ) ) );

        if ( $name === '' || $email === '' || ! is_email( $email ) || $message === '' ) {
            wp_safe_redirect( add_query_arg( 'ttb_contact', 'invalid', $back ) ); exit;
        }

        $to       = (string) Settings::get( 'contact_email', get_option( 'admin_email' ) );
        $subject  = sprintf( '[TalentTrack — %s] %s', $topic, $name );
        $body     = "From: $name <$email>\n";
        if ( $club !== '' ) $body .= "Club: $club\n";
        $body    .= "Topic: $topic\n";
        $body    .= "----\n$message\n";

        $headers  = [ 'Reply-To: ' . $name . ' <' . $email . '>' ];

        $sent = wp_mail( $to, $subject, $body, $headers );

        wp_safe_redirect( add_query_arg( 'ttb_contact', $sent ? 'sent' : 'error', $back ) );
        exit;
    }

    private static function contactUrl(): string {
        $pages = (array) get_option( 'ttb_pages', [] );
        if ( ! empty( $pages['tt_brand_contact'] ) ) {
            $url = get_permalink( (int) $pages['tt_brand_contact'] );
            if ( is_string( $url ) ) return $url;
        }
        return home_url( '/contact/' );
    }
}
