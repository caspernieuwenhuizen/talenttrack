<?php
namespace TT\Infrastructure\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * RecordRedactor (#4690) — the one place that decides which sensitive
 * fields of a player-linked record a reader may see.
 *
 * The fields and the right that unlocks each live in
 * `config/sensitive_fields.php`. A REST answer from a registered route is
 * redacted after the controller has built it (`filterResponse()` on
 * `rest_post_dispatch`), and a view asks `isMasked()` and renders
 * `Components\MaskedValue` in place of the value.
 *
 * Rights are resolved once per request per reader and player, and a list is
 * resolved in one pass: the players' teams come from the rows, or from one
 * statement for the whole list, never from a query per player.
 */
final class RecordRedactor {

    /**
     * The rules a registry entry may name. A field naming any other rule is
     * hidden from everybody, so a typo in the registry fails closed.
     */
    private const RULES = [ 'staff_for_player' ];

    /** @var array<string, array{routes: list<string>, id_key: string, fields: array<string, string>}>|null */
    private static ?array $registry = null;

    /** @var array<string, bool> "user|rule|player" => may read */
    private static array $memo = [];

    /**
     * The registered fields of an entity, field => rule.
     *
     * @return array<string, string>
     */
    public static function fields( string $entity ): array {
        return self::registry()[ $entity ]['fields'] ?? [];
    }

    /**
     * The row without every registered field this reader may not read.
     * Fields that are not registered are left alone.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function redact( string $entity, array $row, int $user_id, ?int $player_id ): array {
        $fields = self::fields( $entity );
        if ( $fields === [] ) return $row;

        foreach ( $fields as $field => $rule ) {
            if ( ! array_key_exists( $field, $row ) ) continue;
            if ( ! self::mayRead( $rule, $user_id, (int) $player_id ) ) unset( $row[ $field ] );
        }
        return $row;
    }

    /**
     * {@see self::redact()} for a list of rows, with the rights for every
     * player in it resolved together.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function redactRows( string $entity, array $rows, int $user_id ): array {
        $id_key = self::registry()[ $entity ]['id_key'] ?? '';
        if ( $id_key === '' || self::fields( $entity ) === [] ) return $rows;

        $team_by_player = [];
        foreach ( $rows as $row ) {
            $pid = (int) ( $row[ $id_key ] ?? 0 );
            if ( $pid <= 0 ) continue;
            $team_by_player[ $pid ] = array_key_exists( 'team_id', $row ) && $id_key === 'id' ? (int) $row['team_id'] : null;
        }
        self::prime( $user_id, $team_by_player );

        $out = [];
        foreach ( $rows as $row ) {
            $out[] = self::redact( $entity, $row, $user_id, (int) ( $row[ $id_key ] ?? 0 ) );
        }
        return $out;
    }

    /** Is this registered field hidden from this reader, for this player? */
    public static function isMasked( string $entity, string $field, int $user_id, ?int $player_id ): bool {
        $rule = self::fields( $entity )[ $field ] ?? null;
        if ( $rule === null ) return false;
        return ! self::mayRead( $rule, $user_id, (int) $player_id );
    }

    /**
     * Resolve every rule for these players in one pass. A null team is read
     * from `tt_players`, in one statement for all of them.
     *
     * @param array<int, int|null> $team_by_player player id => team id, or null when unknown.
     */
    public static function prime( int $user_id, array $team_by_player ): void {
        $missing = [];
        foreach ( $team_by_player as $pid => $team_id ) {
            if ( $team_id === null && ! self::known( $user_id, (int) $pid ) ) $missing[] = (int) $pid;
        }
        if ( $missing !== [] && $user_id > 0 ) {
            global $wpdb;
            $in   = implode( ',', array_fill( 0, count( $missing ), '%d' ) );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, team_id FROM {$wpdb->prefix}tt_players WHERE club_id = %d AND id IN ({$in})",
                CurrentClub::id(),
                ...$missing
            ) );
            foreach ( (array) $rows as $r ) {
                $team_by_player[ (int) ( $r->id ?? 0 ) ] = (int) ( $r->team_id ?? 0 );
            }
        }

        $ask = [];
        foreach ( $team_by_player as $pid => $team_id ) {
            $pid = (int) $pid;
            if ( $pid <= 0 || self::known( $user_id, $pid ) ) continue;
            $ask[ $pid ] = (int) $team_id;
        }
        if ( $ask === [] ) return;

        $staff = AuthorizationService::staffForPlayers( $user_id, $ask );
        foreach ( $ask as $pid => $team_id ) {
            self::$memo[ $user_id . '|staff_for_player|' . $pid ] = ! empty( $staff[ $pid ] );
        }
    }

    /**
     * `rest_post_dispatch`: strip every registered field the reader may not
     * read from a response of a registered route, wherever in the payload a
     * row of that entity sits.
     *
     * @param mixed $result
     * @return mixed
     */
    public static function filterResponse( $result, $server, $request ) {
        if ( ! $result instanceof \WP_REST_Response || ! $request instanceof \WP_REST_Request ) return $result;

        $path = (string) $request->get_route();
        if ( strpos( $path, '/talenttrack/' ) !== 0 ) return $result;

        $user_id = get_current_user_id();
        foreach ( self::registry() as $entity => $spec ) {
            if ( ! self::routeMatches( $spec['routes'], $path ) ) continue;

            $data = $result->get_data();
            if ( ! is_array( $data ) ) continue;

            $rows = [];
            self::collectRows( $data, $spec['id_key'], array_keys( $spec['fields'] ), $rows );
            if ( $rows === [] ) continue;

            $team_by_player = [];
            foreach ( $rows as $row ) {
                $pid = (int) $row[ $spec['id_key'] ];
                $team_by_player[ $pid ] = array_key_exists( 'team_id', $row ) && $spec['id_key'] === 'id' ? (int) $row['team_id'] : null;
            }
            self::prime( $user_id, $team_by_player );

            $result->set_data( self::redactTree( $entity, $data, $spec['id_key'], $user_id ) );
        }
        return $result;
    }

    public static function register(): void {
        static $registered = false;
        if ( $registered ) return;
        $registered = true;
        add_filter( 'rest_post_dispatch', [ __CLASS__, 'filterResponse' ], 10, 3 );
    }

    public static function flush(): void {
        self::$memo = [];
    }

    // Internals

    private static function mayRead( string $rule, int $user_id, int $player_id ): bool {
        if ( ! in_array( $rule, self::RULES, true ) ) return false;
        if ( $user_id <= 0 || $player_id <= 0 ) return false;

        $key = $user_id . '|' . $rule . '|' . $player_id;
        if ( ! isset( self::$memo[ $key ] ) ) {
            self::$memo[ $key ] = AuthorizationService::isStaffForPlayer( $user_id, $player_id );
        }
        return self::$memo[ $key ];
    }

    private static function known( int $user_id, int $player_id ): bool {
        return isset( self::$memo[ $user_id . '|staff_for_player|' . $player_id ] );
    }

    /** @param list<string> $patterns */
    private static function routeMatches( array $patterns, string $path ): bool {
        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, $path ) === 1 ) return true;
        }
        return false;
    }

    /**
     * A row is an array with a positive player id under `$id_key` and at
     * least one registered field.
     *
     * @param array<mixed>               $node
     * @param list<string>               $fields
     * @param list<array<string, mixed>> $rows
     */
    private static function collectRows( array $node, string $id_key, array $fields, array &$rows ): void {
        if ( self::isRow( $node, $id_key, $fields ) ) {
            /** @var array<string, mixed> $node */
            $rows[] = $node;
        }
        foreach ( $node as $child ) {
            if ( is_array( $child ) ) self::collectRows( $child, $id_key, $fields, $rows );
        }
    }

    /**
     * @param array<mixed> $node
     * @return array<mixed>
     */
    private static function redactTree( string $entity, array $node, string $id_key, int $user_id ): array {
        $fields = array_keys( self::fields( $entity ) );
        if ( self::isRow( $node, $id_key, $fields ) ) {
            /** @var array<string, mixed> $node */
            $node = self::redact( $entity, $node, $user_id, (int) $node[ $id_key ] );
        }
        foreach ( $node as $key => $child ) {
            if ( is_array( $child ) ) $node[ $key ] = self::redactTree( $entity, $child, $id_key, $user_id );
        }
        return $node;
    }

    /**
     * @param array<mixed> $node
     * @param list<string> $fields
     */
    private static function isRow( array $node, string $id_key, array $fields ): bool {
        if ( ! isset( $node[ $id_key ] ) || ! is_numeric( $node[ $id_key ] ) || (int) $node[ $id_key ] <= 0 ) return false;
        foreach ( $fields as $field ) {
            if ( array_key_exists( $field, $node ) ) return true;
        }
        return false;
    }

    /** @return array<string, array{routes: list<string>, id_key: string, fields: array<string, string>}> */
    private static function registry(): array {
        if ( self::$registry !== null ) return self::$registry;
        self::$registry = [];

        $path = defined( 'TT_PLUGIN_DIR' ) ? TT_PLUGIN_DIR . 'config/sensitive_fields.php' : '';
        if ( $path === '' || ! is_readable( $path ) ) return self::$registry;

        /** @var mixed $raw */
        $raw = require $path;
        if ( ! is_array( $raw ) ) return self::$registry;

        foreach ( $raw as $entity => $spec ) {
            if ( ! is_array( $spec ) ) continue;
            $routes = [];
            foreach ( (array) ( $spec['routes'] ?? [] ) as $pattern ) {
                if ( is_string( $pattern ) && $pattern !== '' ) $routes[] = $pattern;
            }
            $fields = [];
            foreach ( (array) ( $spec['fields'] ?? [] ) as $field => $rule ) {
                if ( is_string( $field ) && is_string( $rule ) ) $fields[ $field ] = $rule;
            }
            self::$registry[ (string) $entity ] = [
                'routes' => $routes,
                'id_key' => is_string( $spec['id_key'] ?? null ) ? $spec['id_key'] : 'player_id',
                'fields' => $fields,
            ];
        }
        return self::$registry;
    }
}
