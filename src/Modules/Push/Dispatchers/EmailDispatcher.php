<?php
namespace TT\Modules\Push\Dispatchers;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * EmailDispatcher — sends to the target user's WP `user_email`
 * (#0042). Pure wrapper around `wp_mail`, identical posture to the
 * existing `TaskMailer` flow used by the workflow engine. Lives in
 * the Push module so the dispatcher chain can address all three
 * channels (push / parent_email / email) through one interface.
 */
final class EmailDispatcher implements DispatcherInterface {

    public function key(): string { return 'email'; }

    public function applicableTo( array $context ): bool {
        $user_id = (int) ( $context['user_id'] ?? 0 );
        if ( $user_id <= 0 ) return false;
        $user = get_userdata( $user_id );
        return $user && ! empty( $user->user_email );
    }

    public function deliver( array $context ): bool {
        $user_id = (int) ( $context['user_id'] ?? 0 );
        if ( $user_id <= 0 ) return false;
        $user = get_userdata( $user_id );
        if ( ! $user || empty( $user->user_email ) ) return false;

        $title = (string) ( $context['title'] ?? '' );
        $body  = (string) ( $context['body']  ?? '' );
        $url   = (string) ( $context['url']   ?? '' );

        $lines = [ $body ];
        if ( $url !== '' ) {
            $lines[] = '';
            $lines[] = $url;
        }
        return (bool) wp_mail(
            (string) $user->user_email,
            $title,
            implode( "\n", $lines )
        );
    }
}
