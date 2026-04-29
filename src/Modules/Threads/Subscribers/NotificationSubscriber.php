<?php
namespace TT\Modules\Threads\Subscribers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Threads\Domain\ThreadVisibility;
use TT\Modules\Threads\ThreadTypeRegistry;

/**
 * NotificationSubscriber (#0028) — fans out tt_thread_message_posted
 * to participants via email (v1) or push (when #0042 ships).
 *
 * Author is excluded from the fan-out. private_to_coach messages are
 * sent only to coaches + admins (`tt_view_settings` or
 * `tt_edit_evaluations`).
 *
 * Each recipient receives one short message with a link back to the
 * goal detail. wp_mail() is the v1 transport; #0042's PushDispatcher
 * wraps this layer when push subscriptions exist.
 */
final class NotificationSubscriber {

    public static function init(): void {
        add_action( 'tt_thread_message_posted', [ self::class, 'onPosted' ], 20, 5 );
    }

    public static function onPosted( string $type, int $thread_id, int $msg_id, int $author_user_id, string $visibility ): void {
        // Admins can disable notification fan-out via tt_config.
        if ( class_exists( '\\TT\\Infrastructure\\Query\\QueryHelpers' )
            && \TT\Infrastructure\Query\QueryHelpers::get_config( 'threads.notify_on_post', '1' ) === '0'
        ) {
            return;
        }

        $adapter = ThreadTypeRegistry::get( $type );
        if ( ! $adapter ) return;

        $participants = $adapter->participantUserIds( $thread_id );
        $recipients = array_values( array_filter( $participants, static fn( int $id ): bool => $id !== $author_user_id ) );
        if ( empty( $recipients ) ) return;

        if ( $visibility === ThreadVisibility::PRIVATE_COACH ) {
            $recipients = array_values( array_filter( $recipients, static function ( int $uid ): bool {
                return user_can( $uid, 'tt_view_settings' ) || user_can( $uid, 'tt_edit_evaluations' );
            } ) );
            if ( empty( $recipients ) ) return;
        }

        $author = (int) $author_user_id > 0 ? get_user_by( 'id', $author_user_id ) : null;
        $author_name = $author instanceof \WP_User ? (string) $author->display_name : __( 'Someone', 'talenttrack' );
        $entity_label = $adapter->entityLabel( $thread_id );

        $subject = sprintf(
            /* translators: %s is a label like "Marcus's goal: Improve first-touch under pressure" */
            __( 'New message on %s', 'talenttrack' ),
            $entity_label
        );
        $intro = sprintf(
            /* translators: %s is the author's display name */
            __( '%s wrote:', 'talenttrack' ),
            $author_name
        );
        $message_body = self::messagePreview( $msg_id );
        $deep_link = self::goalDeepLink( $thread_id );

        $body = $intro . "\n\n" . $message_body;
        if ( $deep_link !== '' ) $body .= "\n\n" . __( 'View:', 'talenttrack' ) . ' ' . $deep_link;

        foreach ( $recipients as $uid ) {
            $u = get_user_by( 'id', $uid );
            if ( ! $u instanceof \WP_User || empty( $u->user_email ) ) continue;
            wp_mail( (string) $u->user_email, $subject, $body );
        }
    }

    private static function messagePreview( int $msg_id ): string {
        global $wpdb;
        $body = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT body FROM {$wpdb->prefix}tt_thread_messages WHERE id = %d",
            $msg_id
        ) );
        $body = wp_strip_all_tags( $body );
        return mb_substr( $body, 0, 500 );
    }

    private static function goalDeepLink( int $goal_id ): string {
        // The frontend dashboard owns the goal-detail surface today.
        $base = home_url( '/' );
        return add_query_arg( [ 'tt_view' => 'goals', 'goal_id' => $goal_id ], $base );
    }
}
