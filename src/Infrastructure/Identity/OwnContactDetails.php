<?php
namespace TT\Infrastructure\Identity;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Players\ParentChildResolver;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * OwnContactDetails (#3691) — every contact detail the academy holds
 * about the caller, gathered from the stores that hold them.
 *
 * A parent's details sit in three places that can disagree: the WP
 * account, their `tt_people` row, and the `guardian_*` columns an admin
 * typed onto each child's player file. Nothing showed a parent the third
 * one, so a number they corrected on My settings could stay wrong on the
 * file staff actually ring. This answers "what do you have about me?" in
 * one read.
 *
 * OWN-IDENTITY MATCH ONLY
 *
 * A child's guardian fields may describe somebody else — the other
 * parent, a grandparent, an emergency contact. Those stay hidden, which
 * is the rule #1725 set when it made the player detail view's guardians
 * card staff-only. So a `guardian_*` field is returned **only** when its
 * stored value matches one of the caller's own values: their name, their
 * email, or their phone. A field that does not match is absent from the
 * payload rather than present and empty — "we hold nothing of yours
 * here" and "we hold somebody else's here" must read the same.
 *
 * Matching is normalised, because these stores are typed by hand:
 * whitespace collapsed and case folded for names, case folded for email,
 * digits only for phone with the national trunk zero and the country
 * code reconciled (`+31 6 12345678` and `06 12345678` are one number).
 *
 * Read-only. The correction path is #3794.
 */
final class OwnContactDetails {

    /** Digits compared when two numbers are written with different prefixes. */
    private const PHONE_TAIL = 9;

    /** The player-file columns this ever discloses. */
    private const GUARDIAN_FIELDS = [ 'guardian_name', 'guardian_email', 'guardian_phone' ];

    /**
     * @return array{
     *     account: array{display_name:string, first_name:string, last_name:string, email:string, phone:string},
     *     person: array{id:int, name:string, email:string, phone:string, role_type:string}|null,
     *     children: list<array{player_id:int, player_name:string, fields:array<string,string>}>
     * }
     */
    public static function forUser( int $user_id ): array {
        $empty = [
            'account'  => [
                'display_name' => '',
                'first_name'   => '',
                'last_name'    => '',
                'email'        => '',
                'phone'        => '',
            ],
            'person'   => null,
            'children' => [],
        ];

        if ( $user_id <= 0 ) return $empty;

        $user = get_userdata( $user_id );
        if ( ! $user ) return $empty;

        $account = [
            'display_name' => (string) $user->display_name,
            'first_name'   => (string) $user->first_name,
            'last_name'    => (string) $user->last_name,
            'email'        => (string) $user->user_email,
            'phone'        => PhoneMeta::get( $user_id ),
        ];

        $person = self::person( $user_id );

        return [
            'account'  => $account,
            'person'   => $person,
            'children' => self::childFields( $user_id, self::identity( $account, $person ) ),
        ];
    }

    /**
     * The caller's own `tt_people` row, when they have one. `ContactSync`
     * keeps it in step with the account, so it is usually a copy — but it
     * is a separate store and this screen exists to show where each value
     * actually lives.
     *
     * @return array{id:int, name:string, email:string, phone:string, role_type:string}|null
     */
    private static function person( int $user_id ): ?array {
        global $wpdb;

        $found = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, first_name, last_name, email, phone, role_type
               FROM {$wpdb->prefix}tt_people
              WHERE wp_user_id = %d AND status = 'active' AND club_id = %d
              LIMIT 1",
            $user_id, CurrentClub::id()
        ) );
        if ( ! $found ) return null;

        $row  = (array) $found;
        $name = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );

        return [
            'id'        => (int) ( $row['id'] ?? 0 ),
            'name'      => $name,
            'email'     => (string) ( $row['email'] ?? '' ),
            'phone'     => (string) ( $row['phone'] ?? '' ),
            'role_type' => (string) ( $row['role_type'] ?? '' ),
        ];
    }

    /**
     * Everything the caller is known by, normalised for comparison.
     *
     * @param array{display_name:string, first_name:string, last_name:string, email:string, phone:string} $account
     * @param array{id:int, name:string, email:string, phone:string, role_type:string}|null               $person
     * @return array{names:list<string>, emails:list<string>, phones:list<string>}
     */
    private static function identity( array $account, ?array $person ): array {
        $names = [
            $account['display_name'],
            trim( $account['first_name'] . ' ' . $account['last_name'] ),
        ];
        $emails = [ $account['email'] ];
        $phones = [ $account['phone'] ];

        if ( $person !== null ) {
            $names[]  = $person['name'];
            $emails[] = $person['email'];
            $phones[] = $person['phone'];
        }

        return [
            'names'  => self::normalizedSet( $names, static function ( string $v ): string {
                return self::normalizeName( $v );
            } ),
            'emails' => self::normalizedSet( $emails, static function ( string $v ): string {
                return self::normalizeEmail( $v );
            } ),
            'phones' => self::normalizedSet( $phones, static function ( string $v ): string {
                return self::normalizePhone( $v );
            } ),
        ];
    }

    /**
     * @param list<string> $values
     * @param callable(string):string $normalize
     * @return list<string>
     */
    private static function normalizedSet( array $values, callable $normalize ): array {
        $out = [];
        foreach ( $values as $value ) {
            $normalized = $normalize( $value );
            if ( $normalized !== '' && ! in_array( $normalized, $out, true ) ) $out[] = $normalized;
        }
        return $out;
    }

    /**
     * The guardian fields on each linked child's file that describe the
     * caller. A child with no matching field still appears, carrying an
     * empty `fields` — that is the answer "your child's file records
     * nothing of yours", which is exactly the case worth correcting.
     *
     * @param array{names:list<string>, emails:list<string>, phones:list<string>} $identity
     * @return list<array{player_id:int, player_name:string, fields:array<string,string>}>
     */
    private static function childFields( int $user_id, array $identity ): array {
        $out = [];

        foreach ( ParentChildResolver::children( $user_id ) as $child ) {
            $row = (array) $child;
            if ( (int) ( $row['id'] ?? 0 ) <= 0 || ! empty( $row['archived_at'] ) ) continue;

            $fields = [];
            foreach ( self::GUARDIAN_FIELDS as $field ) {
                $value = trim( (string) ( $row[ $field ] ?? '' ) );
                if ( $value === '' ) continue;
                if ( self::isOwn( $field, $value, $identity ) ) $fields[ $field ] = $value;
            }

            $out[] = [
                'player_id'   => (int) $row['id'],
                'player_name' => QueryHelpers::player_display_name( $child ),
                'fields'      => $fields,
            ];
        }

        return $out;
    }

    /**
     * @param array{names:list<string>, emails:list<string>, phones:list<string>} $identity
     */
    private static function isOwn( string $field, string $value, array $identity ): bool {
        switch ( $field ) {
            case 'guardian_name':
                return in_array( self::normalizeName( $value ), $identity['names'], true );
            case 'guardian_email':
                return in_array( self::normalizeEmail( $value ), $identity['emails'], true );
            case 'guardian_phone':
                $candidate = self::normalizePhone( $value );
                if ( $candidate === '' ) return false;
                foreach ( $identity['phones'] as $own ) {
                    if ( self::samePhone( $candidate, $own ) ) return true;
                }
                return false;
        }
        return false;
    }

    /** Case- and whitespace-insensitive. */
    private static function normalizeName( string $raw ): string {
        $collapsed = preg_replace( '/\s+/u', ' ', trim( $raw ) ) ?? '';
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $collapsed, 'UTF-8' ) : strtolower( $collapsed );
    }

    private static function normalizeEmail( string $raw ): string {
        return strtolower( trim( $raw ) );
    }

    /** Digits only — formatting is not part of a phone number. */
    private static function normalizePhone( string $raw ): string {
        return preg_replace( '/\D+/', '', $raw ) ?? '';
    }

    /**
     * Two numbers are the same when their digits match, or when the last
     * nine agree. The second rule is what makes `+31612345678` and
     * `0612345678` one number: an admin typed the guardian column at the
     * gate in national form, and the parent gave their account the E.164
     * one the app asks for. Nine digits is a subscriber number, not a
     * coincidence — and a number too short for the tail rule is compared
     * whole rather than loosely.
     */
    private static function samePhone( string $a, string $b ): bool {
        if ( $a === '' || $b === '' ) return false;
        if ( $a === $b ) return true;
        if ( strlen( $a ) < self::PHONE_TAIL || strlen( $b ) < self::PHONE_TAIL ) return false;
        return substr( $a, -self::PHONE_TAIL ) === substr( $b, -self::PHONE_TAIL );
    }
}
