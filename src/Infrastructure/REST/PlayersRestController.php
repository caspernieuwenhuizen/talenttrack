<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\CustomFields\CustomFieldsRepository;
use TT\Infrastructure\CustomFields\CustomValuesRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Validation\CustomFieldValidator;

/**
 * PlayersRestController — /wp-json/talenttrack/v1/players
 *
 * v2.6.1 changes:
 *   - GET includes `custom_fields: { field_key: value }` on list and single.
 *   - POST/PUT accept a `custom_fields` object; values are validated and
 *     upserted. Validation failure returns 422 with the errors array and
 *     the player row is not modified.
 *   - Full field coverage (matches admin form). Previously stub-only.
 *   - Continues to use RestResponse envelope introduced in v2.2.0.
 */
class PlayersRestController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/players', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_players' ],
                'permission_callback' => function () { return is_user_logged_in(); },
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_player' ],
                'permission_callback' => function () { return current_user_can( 'tt_manage_players' ); },
            ],
        ]);
        register_rest_route( self::NS, '/players/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_player' ],
                'permission_callback' => function () { return is_user_logged_in(); },
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update_player' ],
                'permission_callback' => function () { return current_user_can( 'tt_manage_players' ); },
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_player' ],
                'permission_callback' => function () { return current_user_can( 'tt_manage_players' ); },
            ],
        ]);
    }

    public static function list_players( \WP_REST_Request $r ) {
        $team_id = $r['team_id'] ? absint( $r['team_id'] ) : 0;
        $rows    = QueryHelpers::get_players( $team_id );
        return RestResponse::success( array_map( [ __CLASS__, 'fmt' ], $rows ) );
    }

    public static function get_player( \WP_REST_Request $r ) {
        $pl = QueryHelpers::get_player( (int) $r['id'] );
        if ( ! $pl ) return RestResponse::notFound();
        return RestResponse::success( self::fmt( $pl ) );
    }

    public static function create_player( \WP_REST_Request $r ) {
        // Validate custom fields first.
        $validation = self::validateCustomFields( $r );
        if ( ! empty( $validation['errors'] ) ) {
            return RestResponse::errors( $validation['errors'], 422 );
        }

        global $wpdb;
        $data = self::extract( $r );
        $wpdb->insert( $wpdb->prefix . 'tt_players', $data );
        $id = (int) $wpdb->insert_id;

        self::upsertCustomValues( $id, $validation['sanitized'] );

        do_action( 'tt_after_player_save', $id, $data );

        $pl = QueryHelpers::get_player( $id );
        return RestResponse::success( self::fmt( $pl ) );
    }

    public static function update_player( \WP_REST_Request $r ) {
        $id = (int) $r['id'];
        $existing = QueryHelpers::get_player( $id );
        if ( ! $existing ) return RestResponse::notFound();

        $validation = self::validateCustomFields( $r );
        if ( ! empty( $validation['errors'] ) ) {
            return RestResponse::errors( $validation['errors'], 422 );
        }

        global $wpdb;
        $data = self::extract( $r );
        $wpdb->update( $wpdb->prefix . 'tt_players', $data, [ 'id' => $id ] );

        self::upsertCustomValues( $id, $validation['sanitized'] );

        do_action( 'tt_after_player_save', $id, $data );

        $pl = QueryHelpers::get_player( $id );
        return RestResponse::success( self::fmt( $pl ) );
    }

    public static function delete_player( \WP_REST_Request $r ) {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'tt_players', [ 'status' => 'deleted' ], [ 'id' => (int) $r['id'] ] );
        return RestResponse::success( [ 'deleted' => true ] );
    }

    /* ═══ Helpers ═══ */

    /**
     * Validate the request's custom_fields payload against active field defs.
     *
     * @return array{errors: array<int, array{code:string, message:string, details:array<string,mixed>}>, sanitized: array<int, ?string>}
     */
    private static function validateCustomFields( \WP_REST_Request $r ): array {
        $fields = ( new CustomFieldsRepository() )->getActive( CustomFieldsRepository::ENTITY_PLAYER );
        if ( empty( $fields ) ) {
            return [ 'errors' => [], 'sanitized' => [] ];
        }
        $submitted = $r->get_param( 'custom_fields' );
        if ( ! is_array( $submitted ) ) {
            $submitted = [];
        }
        return ( new CustomFieldValidator() )->validate( $fields, $submitted );
    }

    /**
     * @param array<int, ?string> $sanitized  field_id → value map
     */
    private static function upsertCustomValues( int $player_id, array $sanitized ): void {
        $repo = new CustomValuesRepository();
        foreach ( $sanitized as $field_id => $value ) {
            $repo->upsert( CustomFieldsRepository::ENTITY_PLAYER, $player_id, (int) $field_id, $value );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function extract( \WP_REST_Request $r ): array {
        return [
            'first_name'          => sanitize_text_field( (string) ( $r['first_name'] ?? '' ) ),
            'last_name'           => sanitize_text_field( (string) ( $r['last_name'] ?? '' ) ),
            'date_of_birth'       => sanitize_text_field( (string) ( $r['date_of_birth'] ?? '' ) ),
            'nationality'         => sanitize_text_field( (string) ( $r['nationality'] ?? '' ) ),
            'height_cm'           => ! empty( $r['height_cm'] ) ? absint( $r['height_cm'] ) : null,
            'weight_kg'           => ! empty( $r['weight_kg'] ) ? absint( $r['weight_kg'] ) : null,
            'preferred_foot'      => sanitize_text_field( (string) ( $r['preferred_foot'] ?? '' ) ),
            'preferred_positions' => wp_json_encode(
                is_array( $r['preferred_positions'] ?? null )
                    ? array_map( 'sanitize_text_field', (array) $r['preferred_positions'] )
                    : []
            ),
            'jersey_number'       => ! empty( $r['jersey_number'] ) ? absint( $r['jersey_number'] ) : null,
            'team_id'             => absint( $r['team_id'] ?? 0 ),
            'date_joined'         => sanitize_text_field( (string) ( $r['date_joined'] ?? '' ) ),
            'photo_url'           => esc_url_raw( (string) ( $r['photo_url'] ?? '' ) ),
            'guardian_name'       => sanitize_text_field( (string) ( $r['guardian_name'] ?? '' ) ),
            'guardian_email'      => sanitize_email( (string) ( $r['guardian_email'] ?? '' ) ),
            'guardian_phone'      => sanitize_text_field( (string) ( $r['guardian_phone'] ?? '' ) ),
            'wp_user_id'          => absint( $r['wp_user_id'] ?? 0 ),
            'status'              => sanitize_text_field( (string) ( $r['status'] ?? 'active' ) ),
        ];
    }

    /**
     * Format a player row for API output, including custom_fields.
     *
     * @return array<string,mixed>
     */
    private static function fmt( ?object $pl ): array {
        if ( ! $pl ) return [];

        $custom = ( new CustomValuesRepository() )->getByEntityKeyed(
            CustomFieldsRepository::ENTITY_PLAYER,
            (int) $pl->id
        );

        return [
            'id'                  => (int) $pl->id,
            'first_name'          => (string) $pl->first_name,
            'last_name'           => (string) $pl->last_name,
            'date_of_birth'       => $pl->date_of_birth ?: null,
            'nationality'         => $pl->nationality ?: null,
            'height_cm'           => $pl->height_cm !== null ? (int) $pl->height_cm : null,
            'weight_kg'           => $pl->weight_kg !== null ? (int) $pl->weight_kg : null,
            'preferred_foot'      => $pl->preferred_foot ?: null,
            'preferred_positions' => json_decode( (string) $pl->preferred_positions, true ) ?: [],
            'jersey_number'       => $pl->jersey_number !== null ? (int) $pl->jersey_number : null,
            'team_id'             => (int) $pl->team_id,
            'date_joined'         => $pl->date_joined ?: null,
            'photo_url'           => $pl->photo_url ?: null,
            'guardian_name'       => $pl->guardian_name ?: null,
            'guardian_email'      => $pl->guardian_email ?: null,
            'guardian_phone'      => $pl->guardian_phone ?: null,
            'wp_user_id'          => (int) $pl->wp_user_id,
            'status'              => (string) $pl->status,
            'custom_fields'       => (object) $custom,
        ];
    }
}
