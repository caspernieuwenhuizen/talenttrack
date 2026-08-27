<?php
namespace TT\Infrastructure\Identity;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ContactSync (#2962, epic #2960) — keeps the two contact stores aligned.
 *
 * `ContactResolver` (#2961) made reads consistent by deciding which store
 * wins. This makes writes consistent, so the two stop drifting apart in
 * the first place: an email edited on the WP profile lands on the person
 * row, and an email edited on the People screen lands on the account.
 *
 * LOOP GUARD
 *
 * Both directions write, and a TT-side write calls `wp_update_user()`,
 * which fires `profile_update`, which would write back to TT, which would
 * fire again. The static `$syncing` flag is what stops that. It is not
 * belt-and-braces: without it the very first People-screen save recurses.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO
 *
 * It never creates a person row for an account that has none, and never
 * creates an account. Sync aligns two records that already represent the
 * same human; deciding that they do is somebody else's job (the invite
 * flow, or an admin linking them by hand).
 */
final class ContactSync {

    /** Re-entrancy guard — see the class docblock. */
    private static bool $syncing = false;

    public static function init(): void {
        add_action( 'profile_update', [ self::class, 'onProfileUpdate' ], 10, 2 );
        add_action( 'user_register', [ self::class, 'onUserRegister' ], 10, 1 );
    }

    /**
     * WP → TT. Fired after a WP account is saved.
     *
     * @param int              $user_id
     * @param \WP_User|mixed   $old_user_data
     */
    public static function onProfileUpdate( $user_id, $old_user_data = null ): void {
        self::pushToPerson( (int) $user_id );
    }

    /**
     * WP → TT for a freshly created account.
     *
     * @param int|string $user_id WP passes the id; the hook signature is
     *                            not strict, so accept what it sends.
     */
    public static function onUserRegister( $user_id ): void {
        self::pushToPerson( (int) $user_id );
    }

    /**
     * Copy the account's contact details onto its linked person row.
     *
     * A no-op when the account has no person row — see the class docblock.
     */
    public static function pushToPerson( int $user_id ): void {
        if ( self::$syncing || $user_id <= 0 ) return;

        global $wpdb;

        $person = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, email, phone FROM {$wpdb->prefix}tt_people
              WHERE wp_user_id = %d AND status = 'active' AND club_id = %d
              LIMIT 1",
            $user_id, CurrentClub::id()
        ) );
        if ( ! $person ) return;

        $user = get_userdata( $user_id );
        if ( ! $user ) return;

        $update = [];

        $account_email = sanitize_email( (string) $user->user_email );
        if ( $account_email !== '' && is_email( $account_email )
             && strcasecmp( $account_email, (string) ( $person->email ?? '' ) ) !== 0
        ) {
            $update['email'] = $account_email;
        }

        $account_phone = trim( PhoneMeta::get( $user_id ) );
        if ( $account_phone !== '' && $account_phone !== trim( (string) ( $person->phone ?? '' ) ) ) {
            $update['phone'] = $account_phone;
        }

        if ( empty( $update ) ) return;

        self::$syncing = true;
        try {
            $wpdb->update(
                "{$wpdb->prefix}tt_people",
                $update,
                [ 'id' => (int) $person->id, 'club_id' => CurrentClub::id() ]
            );
        } finally {
            self::$syncing = false;
        }
    }

    /**
     * TT → WP. Copy a person's edited contact details onto their account.
     *
     * Returns an error string when the write could not be made, so the
     * caller can surface it rather than reporting a save that silently did
     * half of what the user asked. A duplicate address is the case that
     * matters: `wp_update_user()` rejects it, and a silent no-op would
     * leave the admin believing they had changed where mail goes.
     *
     * @return string '' on success (or nothing to do), else the reason.
     */
    public static function pushToAccount( int $person_id, ?string $email, ?string $phone ): string {
        if ( self::$syncing || $person_id <= 0 ) return '';

        global $wpdb;

        $user_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT wp_user_id FROM {$wpdb->prefix}tt_people
              WHERE id = %d AND club_id = %d",
            $person_id, CurrentClub::id()
        ) );
        if ( $user_id <= 0 ) return '';

        $user = get_userdata( $user_id );
        if ( ! $user ) return '';

        self::$syncing = true;
        try {
            if ( $email !== null ) {
                $email = sanitize_email( $email );
                if ( $email !== '' && is_email( $email )
                     && strcasecmp( $email, (string) $user->user_email ) !== 0
                ) {
                    $existing = email_exists( $email );
                    if ( $existing && (int) $existing !== $user_id ) {
                        return sprintf(
                            /* translators: %s: the email address that is already taken */
                            __( 'Another account already uses %s, so the sign-in email was left unchanged.', 'talenttrack' ),
                            $email
                        );
                    }

                    $result = wp_update_user( [ 'ID' => $user_id, 'user_email' => $email ] );
                    if ( is_wp_error( $result ) ) {
                        Logger::error( 'identity.sync.email_write_failed', [
                            'person_id' => $person_id,
                            'user_id'   => $user_id,
                            'error'     => $result->get_error_message(),
                        ] );
                        return (string) $result->get_error_message();
                    }
                }
            }

            if ( $phone !== null ) {
                $phone = trim( $phone );
                if ( $phone !== '' && $phone !== trim( PhoneMeta::get( $user_id ) ) ) {
                    PhoneMeta::set( $user_id, $phone );
                }
            }
        } finally {
            self::$syncing = false;
        }

        return '';
    }
}
