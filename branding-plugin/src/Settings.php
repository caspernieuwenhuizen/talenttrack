<?php
namespace TTB;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Settings — the option store + sanitised getters for the branding
 * site's configurable knobs.
 *
 * The option `ttb_settings` is a flat associative array. Defaults are
 * applied at read time so introducing a new key never requires a data
 * migration.
 */
final class Settings {

    public const OPTION = 'ttb_settings';

    /** @return array<string, mixed> */
    public static function defaults(): array {
        return [
            'show_screenshots' => false,                          // user: "not happy with current content … I want to be able to disable them"
            'tagline'          => 'Talent management for serious youth football academies.',
            'demo_url'         => 'https://jg4it.mediamaniacs.nl',
            'contact_email'    => (string) get_option( 'admin_email' ),
            'pilot_open'       => true,
            'price_monthly'    => '€29',                          // headline price; matrix lives in the page itself
            'currency_note'    => 'per club, per month — billed yearly. 30-day full trial, no card.',
            'github_url'       => 'https://github.com/caspernieuwenhuizen/talenttrack',
        ];
    }

    /** @return array<string, mixed> */
    public static function all(): array {
        $stored = get_option( self::OPTION, [] );
        if ( ! is_array( $stored ) ) $stored = [];
        return array_merge( self::defaults(), $stored );
    }

    /**
     * @param string $key
     * @param mixed  $fallback
     * @return mixed
     */
    public static function get( string $key, $fallback = null ) {
        $all = self::all();
        return $all[ $key ] ?? $fallback;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function sanitize( array $input ): array {
        $clean = [];

        $clean['show_screenshots'] = ! empty( $input['show_screenshots'] );
        $clean['pilot_open']       = ! empty( $input['pilot_open'] );

        $clean['tagline']       = sanitize_text_field( (string) ( $input['tagline']       ?? '' ) );
        $clean['price_monthly'] = sanitize_text_field( (string) ( $input['price_monthly'] ?? '' ) );
        $clean['currency_note'] = sanitize_text_field( (string) ( $input['currency_note'] ?? '' ) );

        $clean['demo_url']      = esc_url_raw( (string) ( $input['demo_url']    ?? '' ) );
        $clean['github_url']    = esc_url_raw( (string) ( $input['github_url']  ?? '' ) );

        $email = sanitize_email( (string) ( $input['contact_email'] ?? '' ) );
        $clean['contact_email'] = $email !== '' ? $email : (string) get_option( 'admin_email' );

        return $clean;
    }
}
