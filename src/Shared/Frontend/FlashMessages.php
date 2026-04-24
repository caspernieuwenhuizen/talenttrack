<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FlashMessages — transient-backed flash queue for the frontend.
 *
 * Introduced in #0019 Sprint 1. The frontend has no analogue of
 * `admin_notices`, so every view has been cobbling together its own
 * post-save feedback. This class is the shared sink: any code path can
 * enqueue a message with `FlashMessages::add( $type, $message )` before
 * issuing a redirect, and the next page load for that user pops and
 * renders everything queued.
 *
 * **Storage:** a WP user meta key `_tt_flash_queue`. Per-user so messages
 * don't leak between sessions. Not transients — those are site-scoped
 * and would fan out on busy installs.
 *
 * **Types:** `success` | `info` | `warning` | `error`. Other values are
 * coerced to `info` at render time.
 *
 * **No-JS fallback:** the server-rendered path emits plain `<div>` banners
 * with a `×` close link (`?tt_flash_dismiss=<id>`). The JS layer (to land
 * in this same sprint) progressively enhances those into dismissible
 * animated banners.
 *
 * **Usage:**
 *   FlashMessages::add( 'success', __( 'Evaluation saved.', 'talenttrack' ) );
 *   FlashMessages::render(); // emits pending messages + clears queue
 */
class FlashMessages {

    public const META_KEY = '_tt_flash_queue';

    public const TYPE_SUCCESS = 'success';
    public const TYPE_INFO    = 'info';
    public const TYPE_WARNING = 'warning';
    public const TYPE_ERROR   = 'error';

    private const VALID_TYPES = [ self::TYPE_SUCCESS, self::TYPE_INFO, self::TYPE_WARNING, self::TYPE_ERROR ];

    /**
     * Queue a message for the current user. Safe no-op for anonymous
     * visitors (no user id to key against).
     */
    public static function add( string $type, string $message, int $user_id = 0 ): void {
        $uid = $user_id > 0 ? $user_id : (int) get_current_user_id();
        if ( $uid <= 0 || $message === '' ) return;

        $type = in_array( $type, self::VALID_TYPES, true ) ? $type : self::TYPE_INFO;

        $queue = self::read_queue( $uid );
        $queue[] = [
            'id'      => self::new_id(),
            'type'    => $type,
            'message' => $message,
            'at'      => time(),
        ];
        update_user_meta( $uid, self::META_KEY, $queue );
    }

    /**
     * Read + clear the queue for the current user. Returns messages in
     * insertion order.
     *
     * @return array<int, array{id:string,type:string,message:string,at:int}>
     */
    public static function consume( int $user_id = 0 ): array {
        $uid = $user_id > 0 ? $user_id : (int) get_current_user_id();
        if ( $uid <= 0 ) return [];
        $queue = self::read_queue( $uid );
        if ( $queue ) {
            delete_user_meta( $uid, self::META_KEY );
        }
        return $queue;
    }

    /**
     * Read without clearing — handy for the JS path that consumes via a
     * REST call after initial page load.
     *
     * @return array<int, array{id:string,type:string,message:string,at:int}>
     */
    public static function peek( int $user_id = 0 ): array {
        $uid = $user_id > 0 ? $user_id : (int) get_current_user_id();
        if ( $uid <= 0 ) return [];
        return self::read_queue( $uid );
    }

    /**
     * Remove a single message by id. Used by the no-JS `×` dismiss link.
     */
    public static function dismiss( string $message_id, int $user_id = 0 ): void {
        $uid = $user_id > 0 ? $user_id : (int) get_current_user_id();
        if ( $uid <= 0 || $message_id === '' ) return;
        $queue = self::read_queue( $uid );
        if ( ! $queue ) return;
        $filtered = [];
        foreach ( $queue as $msg ) {
            if ( ( $msg['id'] ?? '' ) !== $message_id ) $filtered[] = $msg;
        }
        if ( count( $filtered ) === count( $queue ) ) return;
        if ( $filtered ) {
            update_user_meta( $uid, self::META_KEY, $filtered );
        } else {
            delete_user_meta( $uid, self::META_KEY );
        }
    }

    /**
     * Emit a server-rendered block of pending messages and clear the
     * queue. Safe to call multiple times per request — second call will
     * see an empty queue and render nothing. Outputs directly to echo.
     */
    public static function render(): void {
        $messages = self::consume();
        if ( ! $messages ) return;

        $dismiss_base = remove_query_arg( 'tt_flash_dismiss' );
        echo '<div class="tt-flash-stack" data-tt-flash-stack="1" style="margin:0 0 16px; display:flex; flex-direction:column; gap:8px;">';
        foreach ( $messages as $msg ) {
            $type  = in_array( $msg['type'] ?? '', self::VALID_TYPES, true ) ? $msg['type'] : self::TYPE_INFO;
            $dismiss_url = esc_url( add_query_arg( 'tt_flash_dismiss', (string) $msg['id'], $dismiss_base ) );
            $colors = self::type_colors( $type );
            printf(
                '<div class="tt-flash tt-flash-%1$s" data-tt-flash-id="%2$s" style="display:flex; align-items:flex-start; gap:10px; padding:10px 14px; border-radius:6px; border:1px solid %3$s; background:%4$s; color:%5$s; font-size:14px;">' .
                    '<span style="flex:1;">%6$s</span>' .
                    '<a href="%7$s" aria-label="%8$s" style="color:inherit; text-decoration:none; opacity:0.7;">×</a>' .
                '</div>',
                esc_attr( $type ),
                esc_attr( (string) $msg['id'] ),
                esc_attr( $colors['border'] ),
                esc_attr( $colors['bg'] ),
                esc_attr( $colors['text'] ),
                esc_html( (string) $msg['message'] ),
                $dismiss_url,
                esc_attr__( 'Dismiss', 'talenttrack' )
            );
        }
        echo '</div>';
    }

    /**
     * Intercept `?tt_flash_dismiss=<id>` query arg on any frontend
     * request and clear the message before the page renders. Called
     * once at plugin boot.
     */
    public static function init(): void {
        add_action( 'template_redirect', [ __CLASS__, 'maybe_handle_dismiss' ], 1 );
    }

    public static function maybe_handle_dismiss(): void {
        if ( empty( $_GET['tt_flash_dismiss'] ) ) return;
        $id = sanitize_text_field( wp_unslash( (string) $_GET['tt_flash_dismiss'] ) );
        self::dismiss( $id );
        // Redirect to the same URL without the query arg so a refresh
        // doesn't keep trying to dismiss a now-missing message.
        $clean = remove_query_arg( 'tt_flash_dismiss' );
        if ( $clean ) {
            wp_safe_redirect( $clean );
            exit;
        }
    }

    /* ═══ Internals ═══ */

    /**
     * @return array<int, array{id:string,type:string,message:string,at:int}>
     */
    private static function read_queue( int $user_id ): array {
        $raw = get_user_meta( $user_id, self::META_KEY, true );
        if ( ! is_array( $raw ) ) return [];
        $out = [];
        foreach ( $raw as $row ) {
            if ( ! is_array( $row ) ) continue;
            $out[] = [
                'id'      => (string) ( $row['id'] ?? '' ),
                'type'    => (string) ( $row['type'] ?? self::TYPE_INFO ),
                'message' => (string) ( $row['message'] ?? '' ),
                'at'      => (int) ( $row['at'] ?? time() ),
            ];
        }
        return $out;
    }

    private static function new_id(): string {
        try {
            return 'f_' . bin2hex( random_bytes( 6 ) );
        } catch ( \Throwable $_ ) {
            return 'f_' . substr( md5( uniqid( 'tt_flash', true ) ), 0, 12 );
        }
    }

    /**
     * @return array{bg:string, border:string, text:string}
     */
    private static function type_colors( string $type ): array {
        switch ( $type ) {
            case self::TYPE_SUCCESS:
                return [ 'bg' => '#ebf7ee', 'border' => '#00a32a', 'text' => '#0a5c1a' ];
            case self::TYPE_WARNING:
                return [ 'bg' => '#fef7e0', 'border' => '#dba617', 'text' => '#7c5a00' ];
            case self::TYPE_ERROR:
                return [ 'bg' => '#fbebeb', 'border' => '#b32d2e', 'text' => '#7a1818' ];
            case self::TYPE_INFO:
            default:
                return [ 'bg' => '#eef6fb', 'border' => '#2271b1', 'text' => '#104f7a' ];
        }
    }
}
