<?php
namespace TT\Modules\Invitations\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Invitations\GuardianContact\GuardianContactRequest;

/**
 * GuardianContactView (#3794) — renders
 * `?tt_view=guardian-contact&token=<token>`.
 *
 * A family with no account at all fills in the contact details the
 * academy holds about them. No login: the token is the credential, the
 * same arrangement the invitation acceptance page uses, and the people
 * this is for are precisely the ones the academy cannot reach.
 *
 * WHAT THIS PAGE KNOWS
 *
 * The child's name. Not their team, not their birthday, not what is on
 * file already — those details may describe the other parent, and this
 * is a form, not a window into a minor's record. Every refusal renders
 * the same sentence, so the page cannot be used to test whether a token
 * exists.
 *
 * The page is `noindex`: a contact form for one child has no business
 * in a search index.
 */
final class GuardianContactView {

    public static function render(): void {
        self::enqueue();

        if ( self::justSubmitted() ) {
            self::renderThanks();
            return;
        }

        $token   = isset( $_GET['token'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['token'] ) ) : '';
        $request = GuardianContactRequest::resolve( $token );

        if ( $request === null ) {
            self::renderNotice(
                __( 'This link is no longer valid', 'talenttrack' ),
                __( 'It may have been used already, or it may have expired. Ask the academy to send you a new one.', 'talenttrack' )
            );
            return;
        }

        self::renderForm( $request, $token );
    }

    private static function renderForm( object $request, string $token ): void {
        $row         = (array) $request;
        $player_name = GuardianContactRequest::playerName( (int) ( $row['target_player_id'] ?? 0 ) );
        ?>
        <div class="tt-root tt-guardian-contact">
            <h1 class="tt-guardian-contact-title">
                <?php
                printf(
                    /* translators: %s: the child's name */
                    esc_html__( 'Your contact details for %s', 'talenttrack' ),
                    esc_html( $player_name )
                );
                ?>
            </h1>
            <p class="tt-guardian-contact-lead">
                <?php
                printf(
                    /* translators: %s: the academy's name */
                    esc_html__( '%s needs a way to reach you — a cancelled training, a change of time, an injury. Fill in your details below and they go straight onto the record. It takes a minute.', 'talenttrack' ),
                    esc_html( (string) get_bloginfo( 'name' ) )
                );
                ?>
            </p>

            <form method="post" class="tt-guardian-contact-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_guardian_contact_submit' ); ?>
                <input type="hidden" name="action" value="tt_guardian_contact_submit" />
                <input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>" />

                <div class="tt-gc-field">
                    <label class="tt-gc-label" for="tt-gc-name"><?php esc_html_e( 'Your name', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-gc-name" name="guardian_name" class="tt-gc-input"
                           autocomplete="name" required />
                </div>

                <div class="tt-gc-field">
                    <label class="tt-gc-label" for="tt-gc-email"><?php esc_html_e( 'Your email address', 'talenttrack' ); ?></label>
                    <input type="email" inputmode="email" id="tt-gc-email" name="guardian_email" class="tt-gc-input"
                           autocomplete="email" />
                </div>

                <div class="tt-gc-field">
                    <label class="tt-gc-label" for="tt-gc-phone"><?php esc_html_e( 'Your phone number', 'talenttrack' ); ?></label>
                    <input type="tel" inputmode="tel" id="tt-gc-phone" name="guardian_phone" class="tt-gc-input"
                           autocomplete="tel" />
                    <p class="tt-gc-hint"><?php esc_html_e( 'A mobile the academy can ring or text. Fill in at least an email address or a phone number.', 'talenttrack' ); ?></p>
                </div>

                <div class="tt-gc-field tt-gc-consent">
                    <label class="tt-gc-consent-label" for="tt-gc-consent">
                        <input type="checkbox" id="tt-gc-consent" name="consent" value="1" required />
                        <span><?php esc_html_e( 'These are my details, and the academy may use them to contact me about my child.', 'talenttrack' ); ?></span>
                    </label>
                </div>

                <button type="submit" class="tt-gc-submit"><?php esc_html_e( 'Send my details', 'talenttrack' ); ?></button>

                <p class="tt-gc-hint">
                    <?php esc_html_e( 'This link works once. Your details are visible to the academy staff who look after your child, and to nobody else.', 'talenttrack' ); ?>
                </p>
            </form>
        </div>
        <?php
    }

    private static function renderThanks(): void {
        self::renderNotice(
            __( 'Thank you — that is saved', 'talenttrack' ),
            __( 'The academy has your details and can reach you. You can close this page.', 'talenttrack' )
        );
    }

    private static function renderNotice( string $title, string $body ): void {
        echo '<div class="tt-root tt-guardian-contact">';
        echo '<h1 class="tt-guardian-contact-title">' . esc_html( $title ) . '</h1>';
        echo '<p class="tt-guardian-contact-lead">' . esc_html( $body ) . '</p>';
        echo '</div>';
    }

    /** The one query flag the success page needs; it carries no token. */
    private static function justSubmitted(): bool {
        return isset( $_GET['tt_gc'] ) && sanitize_key( (string) wp_unslash( $_GET['tt_gc'] ) ) === 'done';
    }

    /**
     * Keep the page out of indexes and shared caches. Runs on
     * `template_redirect`, while headers can still be sent — the view
     * itself renders inside `the_content`, far too late for that.
     */
    public static function guardIndexing(): void {
        $view = isset( $_GET['tt_view'] ) ? sanitize_key( (string) wp_unslash( $_GET['tt_view'] ) ) : '';
        if ( $view !== GuardianContactRequest::VIEW_SLUG ) return;

        header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
        header( 'Referrer-Policy: no-referrer', true );
        nocache_headers();

        add_action( 'wp_head', [ self::class, 'noindexMeta' ], 1 );
    }

    public static function noindexMeta(): void {
        echo '<meta name="robots" content="noindex, nofollow, noarchive" />' . "\n";
    }

    /**
     * Its own sheet, and the tokens it reads, enqueued explicitly: a
     * logged-out visitor gets none of the dashboard chrome, so nothing
     * else on this page would have loaded them.
     */
    private static function enqueue(): void {
        wp_enqueue_style( 'tt-tokens', TT_PLUGIN_URL . 'assets/css/tokens.css', [], TT_VERSION );
        wp_enqueue_style(
            'tt-frontend-guardian-contact',
            TT_PLUGIN_URL . 'assets/css/frontend-guardian-contact.css',
            [ 'tt-tokens' ],
            TT_VERSION
        );
    }
}
