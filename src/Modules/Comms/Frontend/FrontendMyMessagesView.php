<?php
namespace TT\Modules\Comms\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Repositories\CommsInboxRepository;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendMyMessagesView (#2606, Gate C) — `?tt_view=my-messages`.
 *
 * The in-app inbox. `InappChannelAdapter` has written `tt_comms_inbox`
 * since v3.110.0 and nothing has ever read it, so every message sent on
 * that channel has been delivered into a room with no door. The v3.110.0
 * changelog said the inbox UI would land separately; it never did.
 *
 * ## A separate surface from the staff log, deliberately
 *
 * Different audience, different data, different scope. The staff log is
 * metadata about everyone's messages; this is one person's own messages
 * with their text. Folding them together would mean one screen whose
 * contents depended on who was looking, which is the shape that leaks.
 *
 * Reads through `CommsInboxRepository`, which takes the recipient's user
 * id in every WHERE clause — so a parent seeing only their own family's
 * messages is a property of the query rather than a check that could be
 * got round.
 *
 * ## Navigation (§5)
 *
 * Breadcrumb chain plus the `tt_back` pill, on every path including the
 * signed-out early return. Nothing else.
 *
 * ## Mobile (§2)
 *
 * Parents read this on a phone, so the base stylesheet is the 360px one
 * and the desktop layout is the media query. Marking read goes through
 * `PATCH /comms/inbox/{id}` rather than a form post, so the page does not
 * reload under someone's thumb — with a plain link fallback for a browser
 * with no JavaScript.
 */
final class FrontendMyMessagesView extends FrontendViewBase {

    private const PER_PAGE = 25;

    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-my-messages',
            TT_PLUGIN_URL . 'assets/css/frontend-my-messages.css',
            [ 'tt-public' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-frontend-my-messages',
            TT_PLUGIN_URL . 'assets/js/frontend-my-messages.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-frontend-my-messages', 'TTMyMessages', [
            'root'  => esc_url_raw( rest_url( 'talenttrack/v1/comms/inbox' ) ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'i18n'  => [
                'markRead'  => __( 'Mark as read', 'talenttrack' ),
                'read'      => _x( 'Read', 'an in-app message that has been opened', 'talenttrack' ),
                'failed'    => __( 'That did not save. Try again.', 'talenttrack' ),
                /* translators: %d: number of unread messages */
                'unread'    => __( '%d unread', 'talenttrack' ),
                'allRead'   => __( 'All read', 'talenttrack' ),
            ],
        ] );
    }

    public static function render( int $user_id, bool $is_admin = false ): void {
        $title = __( 'My messages', 'talenttrack' );

        if ( $user_id <= 0 ) {
            FrontendBreadcrumbs::fromDashboard( $title );
            echo '<p class="tt-notice">' . esc_html__( 'You need to be signed in to read your messages.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        FrontendBreadcrumbs::fromDashboard( $title );
        self::renderHeader( $title );

        $repo     = new CommsInboxRepository();
        $page     = isset( $_GET['ipage'] ) ? max( 1, absint( $_GET['ipage'] ) ) : 1;
        $unread   = $repo->unreadCount( $user_id );
        $total    = $repo->countForUser( $user_id );
        $messages = $repo->forUser( $user_id, false, $page, self::PER_PAGE );

        self::renderUnreadCount( $unread );

        if ( $messages === [] ) {
            echo '<p class="tt-notice">' . esc_html__( 'You have no messages yet.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<ul class="tt-inbox-list">';
        foreach ( $messages as $message ) {
            self::renderMessage( $message );
        }
        echo '</ul>';

        self::renderPagination( $total, $page );
    }

    private static function renderUnreadCount( int $unread ): void {
        printf(
            '<p class="tt-inbox-count" data-tt-unread-count>%s</p>',
            $unread > 0
                ? esc_html( sprintf(
                    /* translators: %d: number of unread messages */
                    _n( '%d unread message', '%d unread messages', $unread, 'talenttrack' ),
                    $unread
                ) )
                : esc_html__( 'All read', 'talenttrack' )
        );
    }

    /** @param array<string,mixed> $message */
    private static function renderMessage( array $message ): void {
        $id      = (int) ( $message['id'] ?? 0 );
        $read_at = (string) ( $message['read_at'] ?? '' );
        $is_read = $read_at !== '';
        $subject = (string) ( $message['subject'] ?? '' );
        if ( $subject === '' ) {
            $subject = self::templateLabel( (string) ( $message['template_key'] ?? '' ) );
        }
        ?>
        <li class="tt-inbox-item<?php echo $is_read ? '' : ' is-unread'; ?>" data-tt-message-id="<?php echo esc_attr( (string) $id ); ?>">
            <div class="tt-inbox-item-head">
                <h2 class="tt-inbox-subject"><?php echo esc_html( $subject ); ?></h2>
                <time class="tt-inbox-date" datetime="<?php echo esc_attr( (string) ( $message['created_at'] ?? '' ) ); ?>">
                    <?php echo esc_html( self::humanDate( (string) ( $message['created_at'] ?? '' ) ) ); ?>
                </time>
            </div>
            <div class="tt-inbox-body"><?php echo wp_kses_post( wpautop( (string) ( $message['body'] ?? '' ) ) ); ?></div>
            <?php if ( ! $is_read ) : ?>
                <button type="button" class="tt-btn tt-btn-secondary tt-inbox-mark" data-tt-mark-read="<?php echo esc_attr( (string) $id ); ?>">
                    <?php esc_html_e( 'Mark as read', 'talenttrack' ); ?>
                </button>
            <?php else : ?>
                <p class="tt-inbox-readmark"><?php echo esc_html( _x( 'Read', 'an in-app message that has been opened', 'talenttrack' ) ); ?></p>
            <?php endif; ?>
        </li>
        <?php
    }

    private static function renderPagination( int $total, int $page ): void {
        $pages = (int) max( 1, ceil( $total / self::PER_PAGE ) );
        if ( $pages <= 1 ) return;

        $base = remove_query_arg(
            [ 'ipage' ],
            isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : home_url( '/' )
        );
        ?>
        <nav class="tt-inbox-pagination">
            <?php if ( $page > 1 ) : ?>
                <a class="tt-btn tt-btn-secondary" href="<?php echo esc_url( add_query_arg( 'ipage', $page - 1, $base ) ); ?>">&larr; <?php esc_html_e( 'Newer', 'talenttrack' ); ?></a>
            <?php endif; ?>
            <span><?php printf(
                /* translators: 1: current page, 2: total pages */
                esc_html__( 'Page %1$d of %2$d', 'talenttrack' ),
                (int) $page, (int) $pages
            ); ?></span>
            <?php if ( $page < $pages ) : ?>
                <a class="tt-btn tt-btn-secondary" href="<?php echo esc_url( add_query_arg( 'ipage', $page + 1, $base ) ); ?>"><?php esc_html_e( 'Older', 'talenttrack' ); ?> &rarr;</a>
            <?php endif; ?>
        </nav>
        <?php
    }

    private static function templateLabel( string $key ): string {
        $template = TemplateRegistry::get( $key );
        return $template !== null ? $template->label() : __( 'Message', 'talenttrack' );
    }

    private static function humanDate( string $mysql ): string {
        $stamp = $mysql !== '' ? strtotime( $mysql ) : false;
        if ( $stamp === false ) return $mysql;
        return date_i18n( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $stamp );
    }
}
