<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AuditMeta — reusable "Created by X on <date> · Last changed by Y on
 * <date>" footer for a record detail page (#1471).
 *
 * Each half renders only when its author id is present, so records
 * created before audit columns existed (or system-created rows) show
 * nothing rather than a bogus "user 0". Display names resolve through
 * `get_userdata()`, falling back to the login and then to a generic
 * label when the WP user no longer exists.
 *
 * @phpstan-type Args array{
 *     created_by?: int|null,
 *     created_at?: string,
 *     updated_by?: int|null,
 *     updated_at?: string,
 * }
 */
final class AuditMeta {

    public static function render( array $args ): void {
        $created_by = (int) ( $args['created_by'] ?? 0 );
        $updated_by = (int) ( $args['updated_by'] ?? 0 );
        $created_at = (string) ( $args['created_at'] ?? '' );
        $updated_at = (string) ( $args['updated_at'] ?? '' );

        $parts = [];

        if ( $created_by > 0 ) {
            $parts[] = sprintf(
                /* translators: 1: user name, 2: date/time */
                esc_html__( 'Created by %1$s on %2$s', 'talenttrack' ),
                esc_html( self::nameFor( $created_by ) ),
                esc_html( self::formatDate( $created_at ) )
            );
        }

        if ( $updated_by > 0 ) {
            $parts[] = sprintf(
                /* translators: 1: user name, 2: date/time */
                esc_html__( 'Last changed by %1$s on %2$s', 'talenttrack' ),
                esc_html( self::nameFor( $updated_by ) ),
                esc_html( self::formatDate( $updated_at ) )
            );
        }

        if ( $parts === [] ) return;

        echo '<p class="tt-audit-meta" style="margin:8px 0 0; font-size:.8125rem; color:#5b6e75;">'
            . implode( ' <span aria-hidden="true">&middot;</span> ', $parts ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — parts pre-escaped above
            . '</p>';
    }

    /**
     * Display name for a WP user id, with graceful fallbacks for a
     * deleted account.
     */
    private static function nameFor( int $user_id ): string {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return __( 'a former user', 'talenttrack' );
        }
        $name = (string) $user->display_name;
        return $name !== '' ? $name : (string) $user->user_login;
    }

    /**
     * Localized date + time via the academy date-notation preset (#1481).
     * Returns '' for an empty / unparseable timestamp.
     */
    private static function formatDate( string $mysql_datetime ): string {
        $mysql_datetime = trim( $mysql_datetime );
        if ( $mysql_datetime === '' || $mysql_datetime === '0000-00-00 00:00:00' ) {
            return '';
        }
        return \TT\Shared\Dates\TTDate::dateTime( $mysql_datetime );
    }
}
