<?php
namespace TT\Infrastructure\Identity;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ContactResolver (#2961) — the one place that answers "where do we
 * reach this person?".
 *
 * A person's contact details live in two stores and neither is going
 * away: `tt_people` holds them for everyone the academy knows about,
 * including people with no account at all (a parent who never logged
 * in, a scout, a departed coach), while `wp_users` holds them for
 * everyone who can sign in. Before this class every send path picked
 * whichever field was nearest, and they disagreed — the People screen
 * edited one address while the nightly digest went to the other.
 *
 * Precedence: the person row wins when it has a value, because that is
 * what the academy admin sees and edits. The WP account is the
 * fallback, and the only source when there is no person row.
 *
 * The one deliberate exception is {@see self::emailForAccount()}, which
 * never consults the person row — see its docblock.
 */
final class ContactResolver {

    /**
     * Email for a person, by `tt_people.id`.
     *
     * `tt_people.email` first, then the linked WP account's address.
     */
    public static function emailForPerson( int $person_id ): ?string {
        if ( $person_id <= 0 ) return null;
        $row = self::personRow( $person_id );
        if ( ! $row ) return null;

        $email = sanitize_email( (string) ( $row->email ?? '' ) );
        if ( $email !== '' && is_email( $email ) ) return $email;

        return self::accountEmail( (int) ( $row->wp_user_id ?? 0 ) );
    }

    /**
     * Email for a WP account holder.
     *
     * Resolves through their person row first so that an address edited
     * in TalentTrack is the address they are actually reached at, then
     * falls back to the account's own.
     */
    public static function emailForUser( int $wp_user_id ): ?string {
        if ( $wp_user_id <= 0 ) return null;

        $person = self::personRowForUser( $wp_user_id );
        if ( $person ) {
            $email = sanitize_email( (string) ( $person->email ?? '' ) );
            if ( $email !== '' && is_email( $email ) ) return $email;
        }

        return self::accountEmail( $wp_user_id );
    }

    /**
     * The account's own address, ignoring the person row entirely.
     *
     * For anything that belongs to the *account* rather than the human:
     * password recovery above all. Routing a password reset through a
     * person row would mean an edit on the People screen could redirect
     * someone else's reset mail, so that path deliberately does not use
     * {@see self::emailForUser()}.
     */
    public static function emailForAccount( int $wp_user_id ): ?string {
        return self::accountEmail( $wp_user_id );
    }

    /*
     * There is no `emailForParent()` any more (#2997).
     *
     * It existed to preserve a WooCommerce `billing_email` lookup that
     * outranked the account's own address for parents. #2961 kept that
     * verbatim while nobody could say whether it was deliberate; the
     * answer came back that it was drift, so the lookup is gone and
     * parents resolve through `emailForUser()` like everyone else.
     *
     * Not kept as an alias on purpose: a method whose only remaining job
     * is to document a removed exception invites the next reader to
     * assume parents are still special, and they are not.
     */

    /** Phone for a person, by `tt_people.id`. */
    public static function phoneForPerson( int $person_id ): ?string {
        if ( $person_id <= 0 ) return null;
        $row = self::personRow( $person_id );
        if ( ! $row ) return null;

        $phone = trim( (string) ( $row->phone ?? '' ) );
        if ( $phone !== '' ) return $phone;

        return self::accountPhone( (int) ( $row->wp_user_id ?? 0 ) );
    }

    /**
     * Phone for a WP account holder — person row first, then the
     * `tt_phone` user meta that {@see PhoneMeta} manages.
     */
    public static function phoneForUser( int $wp_user_id ): ?string {
        if ( $wp_user_id <= 0 ) return null;

        $person = self::personRowForUser( $wp_user_id );
        if ( $person ) {
            $phone = trim( (string) ( $person->phone ?? '' ) );
            if ( $phone !== '' ) return $phone;
        }

        return self::accountPhone( $wp_user_id );
    }

    private static function accountEmail( int $wp_user_id ): ?string {
        if ( $wp_user_id <= 0 ) return null;
        $user = get_userdata( $wp_user_id );
        if ( ! $user ) return null;
        $email = sanitize_email( (string) $user->user_email );
        return ( $email !== '' && is_email( $email ) ) ? $email : null;
    }

    private static function accountPhone( int $wp_user_id ): ?string {
        if ( $wp_user_id <= 0 ) return null;
        $phone = trim( PhoneMeta::get( $wp_user_id ) );
        return $phone !== '' ? $phone : null;
    }

    private static function personRow( int $person_id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT email, phone, wp_user_id
               FROM {$wpdb->prefix}tt_people
              WHERE id = %d AND club_id = %d",
            $person_id, CurrentClub::id()
        ) );
    }

    /**
     * The active person row linked to a WP account, if any.
     *
     * Scoped to `status = 'active'` to match the 1:1 link that
     * `PeopleRepository` enforces — archived rows keep their
     * `wp_user_id` and must not win over the live one.
     */
    private static function personRowForUser( int $wp_user_id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT email, phone, wp_user_id
               FROM {$wpdb->prefix}tt_people
              WHERE wp_user_id = %d AND status = 'active' AND club_id = %d
              LIMIT 1",
            $wp_user_id, CurrentClub::id()
        ) );
    }
}
