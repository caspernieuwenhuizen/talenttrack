<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Threads\Domain\ThreadVisibility;
use TT\Modules\Threads\ThreadMessagesRepository;
use TT\Modules\Threads\ThreadReadsRepository;
use TT\Modules\Threads\ThreadTypeRegistry;

/**
 * FrontendThreadView (#0028) — drop-in chat-style thread renderer.
 *
 * Usage:
 *   FrontendThreadView::render( 'goal', $goal_id, get_current_user_id() );
 *
 * Stateless: server emits the initial message list inline + bootstrap
 * JSON; the JS hydrator polls every 30s while the page is foregrounded
 * and POSTs new messages via REST.
 *
 * Mobile-first per CLAUDE.md § 2: 16 px textarea, 48 px send button,
 * single column at 360 px, focus-visible rings, reduced-motion respected.
 */
final class FrontendThreadView {

    public static function render( string $thread_type, int $thread_id, int $user_id ): void {
        $adapter = ThreadTypeRegistry::get( $thread_type );
        if ( ! $adapter || ! $adapter->canRead( $user_id, $thread_id ) ) {
            return;
        }
        self::enqueueAssets();

        $can_post    = $adapter->canPost( $user_id, $thread_id );
        $is_coach    = current_user_can( 'tt_edit_evaluations' ) || current_user_can( 'tt_view_settings' );
        $messages    = ( new ThreadMessagesRepository() )->listForThread( $thread_type, $thread_id, $is_coach );
        $reads_repo  = new ThreadReadsRepository();
        $last_read   = $reads_repo->lastReadAt( $user_id, $thread_type, $thread_id );
        $reads_repo->markRead( $user_id, $thread_type, $thread_id );

        $bootstrap = [
            'rest_url'        => esc_url_raw( rest_url( 'talenttrack/v1/threads/' . sanitize_key( $thread_type ) . '/' . (int) $thread_id ) ),
            'rest_nonce'      => wp_create_nonce( 'wp_rest' ),
            'thread_type'     => sanitize_key( $thread_type ),
            'thread_id'       => (int) $thread_id,
            'current_user_id' => $user_id,
            'edit_window_seconds' => ThreadMessagesRepository::EDIT_WINDOW_SECONDS,
            'is_coach_view'   => $is_coach,
            'last_read_at'    => $last_read,
            'i18n'            => self::i18nStrings(),
        ];

        echo '<section class="tt-thread" data-tt-thread role="region" aria-label="' . esc_attr__( 'Conversation', 'talenttrack' ) . '">';
        echo '<ol class="tt-thread-list" data-tt-thread-list aria-live="polite">';
        $last_id = 0;
        foreach ( $messages as $msg ) {
            echo self::renderMessage( $msg, $user_id, $last_read );
            if ( (int) $msg->id > $last_id ) $last_id = (int) $msg->id;
        }
        if ( empty( $messages ) ) {
            echo '<li class="tt-thread-empty">' . esc_html__( 'No messages yet. Start the conversation.', 'talenttrack' ) . '</li>';
        }
        echo '</ol>';

        if ( $can_post ) {
            echo '<form class="tt-thread-compose" data-tt-thread-compose>';
            echo '<label class="screen-reader-text" for="tt-thread-body">' . esc_html__( 'Your message', 'talenttrack' ) . '</label>';
            echo '<textarea id="tt-thread-body" name="body" rows="3" inputmode="text" required placeholder="' . esc_attr__( 'Write a message…', 'talenttrack' ) . '"></textarea>';
            echo '<div class="tt-thread-compose-row">';
            if ( $is_coach ) {
                echo '<label class="tt-thread-private">'
                    . '<input type="checkbox" name="visibility" value="' . esc_attr( ThreadVisibility::PRIVATE_COACH ) . '" />'
                    . '<span>' . esc_html__( 'Coaches only', 'talenttrack' ) . '</span>'
                    . '</label>';
            }
            echo '<button type="submit" class="tt-thread-send">' . esc_html__( 'Send', 'talenttrack' ) . '</button>';
            echo '</div>';
            echo '</form>';
        }

        echo '<script type="application/json" data-tt-thread-bootstrap>' . wp_json_encode( $bootstrap ) . '</script>';
        echo '</section>';

        // Hand the polling layer the last seen id.
        echo '<script>(function(){var s=document.querySelector("[data-tt-thread]");if(s)s.dataset.lastId=' . (int) $last_id . ';})();</script>';
    }

    private static function renderMessage( object $msg, int $viewer_id, ?string $last_read ): string {
        $is_self    = (int) $msg->author_user_id === $viewer_id;
        $is_system  = (int) $msg->is_system === 1;
        $is_private = (string) $msg->visibility === ThreadVisibility::PRIVATE_COACH;
        $is_unread  = $last_read !== null && (int) $msg->author_user_id !== $viewer_id && (string) $msg->created_at > $last_read;

        $cls = 'tt-thread-msg';
        if ( $is_self ) $cls .= ' is-self';
        if ( $is_system ) $cls .= ' is-system';
        if ( $is_private ) $cls .= ' is-private';
        if ( $is_unread ) $cls .= ' is-unread';

        $author = '';
        if ( ! $is_system && (int) $msg->author_user_id > 0 ) {
            $u = get_user_by( 'id', (int) $msg->author_user_id );
            if ( $u instanceof \WP_User ) $author = (string) $u->display_name;
        }

        $when = strtotime( (string) $msg->created_at . ' UTC' );
        $when_label = $when !== false
            ? sprintf( /* translators: %s is human-readable relative time */ __( '%s ago', 'talenttrack' ), human_time_diff( $when, current_time( 'timestamp', true ) ) )
            : '';

        $body = (string) $msg->body;
        if ( $msg->deleted_at !== null ) {
            $body = __( 'Message deleted.', 'talenttrack' );
            $cls .= ' is-deleted';
        }

        $html  = '<li class="' . esc_attr( $cls ) . '" data-tt-thread-msg="' . (int) $msg->id . '">';
        if ( ! $is_system ) {
            $html .= '<div class="tt-thread-msg-head">'
                . '<span class="tt-thread-msg-author">' . esc_html( $author !== '' ? $author : __( 'Unknown', 'talenttrack' ) ) . '</span>'
                . '<span class="tt-thread-msg-when">' . esc_html( $when_label ) . '</span>'
                . ( $is_private ? '<span class="tt-thread-msg-private">' . esc_html__( 'Coaches only', 'talenttrack' ) . '</span>' : '' )
                . '</div>';
        }
        $html .= '<div class="tt-thread-msg-body">' . wp_kses_post( $body ) . '</div>';
        if ( $msg->edited_at !== null && $msg->deleted_at === null ) {
            $html .= '<div class="tt-thread-msg-edited">' . esc_html__( '(edited)', 'talenttrack' ) . '</div>';
        }
        $html .= '</li>';
        return $html;
    }

    private static function enqueueAssets(): void {
        wp_enqueue_style(
            'tt-frontend-threads',
            TT_PLUGIN_URL . 'assets/css/frontend-threads.css',
            [ 'tt-public' ],
            TT_VERSION
        );
        // v3.110.3 — ttConfirm modal so the per-message Delete button
        // can use the in-app dialog instead of window.confirm().
        wp_enqueue_script(
            'tt-confirm',
            TT_PLUGIN_URL . 'assets/js/components/confirm.js',
            [],
            TT_VERSION,
            true
        );
        wp_enqueue_script(
            'tt-frontend-threads',
            TT_PLUGIN_URL . 'assets/js/frontend-threads.js',
            [ 'tt-confirm' ],
            TT_VERSION,
            true
        );
    }

    /** @return array<string,string> */
    private static function i18nStrings(): array {
        return [
            'sending'       => __( 'Sending…', 'talenttrack' ),
            'send'          => __( 'Send', 'talenttrack' ),
            'failed'        => __( 'Could not send message.', 'talenttrack' ),
            'just_now'      => __( 'just now', 'talenttrack' ),
            'message_deleted' => __( 'Message deleted.', 'talenttrack' ),
            'unread_since'  => __( 'New messages', 'talenttrack' ),
            'edited'        => __( '(edited)', 'talenttrack' ),
            'coaches_only'  => __( 'Coaches only', 'talenttrack' ),
            'edit'          => __( 'Edit', 'talenttrack' ),
            'delete'        => __( 'Delete', 'talenttrack' ),
            'cancel'        => __( 'Cancel', 'talenttrack' ),
            'save'          => __( 'Save', 'talenttrack' ),
            'confirm_delete' => __( 'Delete this message?', 'talenttrack' ),
            'edit_window_expired' => __( 'Edit window has expired.', 'talenttrack' ),
        ];
    }
}
