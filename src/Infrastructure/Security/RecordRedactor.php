<?php
namespace TT\Infrastructure\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * RecordRedactor (#4690) — the one place that decides which sensitive
 * fields of a player-linked record a reader may see.
 *
 * The fields and the right that unlocks each live in
 * `config/sensitive_fields.php`. A controller redacts what it returns
 * (`redact()` / `redactRows()`), a view asks `isMasked()` and renders
 * `Components\MaskedValue` in place of the value, and `filterResponse()` on
 * `rest_post_dispatch` is the HTTP safety net behind both for the routes the
 * registry lists.
 *
 * Rights are resolved once per request per reader and player, and a list is
 * resolved in one pass: the players' teams come from the rows, or from one
 * statement for the whole list, never from a query per player.
 */
final class RecordRedactor {

    /**
     * The rules a registry entry may name. A field naming any other rule is
     * hidden from everybody, so a typo in the registry fails closed.
     *
     *  - `staff_for_player`: {@see AuthorizationService::isStaffForPlayer()}.
     *  - `staff_or_family`: staff for the player, the player themselves, or a
     *    guardian linked to them.
     *  - `guardian_contact`: on a person row (the id is the person's), the
     *    contact of a guardian of a live linked child goes to the person
     *    themselves and to staff for at least one of those children. A person
     *    with no such child is not a guardian here and is left as it is.
     *  - `evaluation_notes`: staff for the player, or whoever may write the
     *    player's evaluations (the `evaluations` change right, globally or on
     *    the player's team).
     *  - `evaluation_comment`: those, and the player and a linked guardian:
     *    the comments a family already reads.
     */
    private const RULES = [ 'staff_for_player', 'staff_or_family', 'guardian_contact', 'evaluation_notes', 'evaluation_comment' ];

    /** @var array<string, bool> "user|person" => may read that person's guardian contact */
    private static array $guardian = [];

    /** @var array<string, bool> "user|player" => may write that player's evaluations */
    private static array $writer = [];

    /** @var array<string, bool> "user|team" => may write evaluations for that team's players */
    private static array $writer_team = [];

    /** @var array<string, array{routes: array<string, array{rows: string, id_key: string}>, id_key: string, fields: array<string, string>}>|null */
    private static ?array $registry = null;

    /** @var array<string, bool> "user|staff_for_player|player" => staff */
    private static array $memo = [];

    /** @var array<int, array<int, true>> user id => the players they are or are a guardian of */
    private static array $family = [];

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
     * @param string|null                $id_key The key holding the player id in these rows;
     *                                           the entity's default when null.
     * @return list<array<string, mixed>>
     */
    public static function redactRows( string $entity, array $rows, int $user_id, ?string $id_key = null ): array {
        $id_key = $id_key ?? ( self::registry()[ $entity ]['id_key'] ?? '' );
        if ( $id_key === '' || self::fields( $entity ) === [] ) return $rows;

        self::primeRows( $entity, $rows, $id_key, $user_id );

        $out = [];
        foreach ( $rows as $row ) {
            $id = (int) ( $row[ $id_key ] ?? 0 );
            // A row with no id under the key is not a row of this entity (an
            // activity's guest has no player record), the same line the
            // response filter draws.
            $out[] = $id > 0 ? self::redact( $entity, $row, $user_id, $id ) : $row;
        }
        return $out;
    }

    /**
     * May this reader read this registered field on every player in the
     * academy? For a query that would otherwise match on the field's content
     * across rows it cannot redact one by one, such as a free-text search.
     * Family readers never qualify: their reach is their own children.
     */
    public static function readsEverywhere( string $entity, string $field, int $user_id ): bool {
        $rule = self::fields( $entity )[ $field ] ?? null;
        if ( $rule === null ) return true;
        if ( $user_id <= 0 || ! in_array( $rule, self::RULES, true ) ) return false;

        // Academy-wide staff are staff for every child, so for every
        // guardian's contact too.
        if ( AuthorizationService::isStaffAcademyWide( $user_id ) ) return true;
        if ( $rule === 'evaluation_notes' || $rule === 'evaluation_comment' ) {
            $gate = \TT\Modules\Authorization\MatrixGate::class;
            return $gate::can( $user_id, 'evaluations', $gate::CHANGE, $gate::SCOPE_GLOBAL );
        }
        return false;
    }

    /**
     * May this reader read this registered field on every player of these
     * teams? True when {@see self::readsEverywhere()} is, or when every team
     * passes the team half of the staff rule or, for the evaluation rules,
     * the evaluations change right on that team. An empty list is false.
     *
     * @param list<int> $team_ids
     */
    public static function readsOnTeams( string $entity, string $field, int $user_id, array $team_ids ): bool {
        if ( self::readsEverywhere( $entity, $field, $user_id ) ) return true;

        $rule = self::fields( $entity )[ $field ] ?? null;
        if ( $rule === null || $user_id <= 0 || $team_ids === [] ) return false;
        if ( ! in_array( $rule, [ 'staff_for_player', 'staff_or_family', 'evaluation_notes', 'evaluation_comment' ], true ) ) return false;

        foreach ( $team_ids as $team_id ) {
            $team_id = (int) $team_id;
            if ( AuthorizationService::isStaffOnTeam( $user_id, $team_id ) ) continue;
            if ( ( $rule === 'evaluation_notes' || $rule === 'evaluation_comment' ) && self::writesEvaluationsOn( $user_id, $team_id ) ) continue;
            return false;
        }
        return true;
    }

    /** Is this registered field hidden from this reader, for this player? */
    public static function isMasked( string $entity, string $field, int $user_id, ?int $player_id ): bool {
        $rule = self::fields( $entity )[ $field ] ?? null;
        if ( $rule === null ) return false;
        return ! self::mayRead( $rule, $user_id, (int) $player_id );
    }

    /**
     * Resolve the staff answer for these players in one pass. A null team is
     * read from `tt_players`, in one statement for all of them.
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
            self::$writer[ $user_id . '|' . $pid ] = self::writesEvaluationsOn( $user_id, $team_id );
        }
    }

    /**
     * The evaluations change right for a player on this team: at global
     * scope, or at team scope on the player's team. The same question
     * `EvaluationInserter::mayWriteFor()` asks, answered from the team the
     * caller already resolved, once per distinct team.
     */
    private static function writesEvaluationsOn( int $user_id, int $team_id ): bool {
        $key = $user_id . '|' . $team_id;
        if ( isset( self::$writer_team[ $key ] ) ) return self::$writer_team[ $key ];

        $gate    = \TT\Modules\Authorization\MatrixGate::class;
        $allowed = $gate::can( $user_id, 'evaluations', $gate::CHANGE, $gate::SCOPE_GLOBAL )
            || ( $team_id > 0 && $gate::can( $user_id, 'evaluations', $gate::CHANGE, $gate::SCOPE_TEAM, $team_id ) );
        self::$writer_team[ $key ] = $allowed;
        return $allowed;
    }

    /**
     * `rest_post_dispatch`: for a response whose matched route pattern the
     * registry lists, strip every registered field the reader may not read
     * from the rows at that route's row path. Arrays, objects and
     * `JsonSerializable` values are walked alike and keep their JSON shape.
     *
     * @param mixed $result
     * @param mixed $server
     * @param mixed $request
     * @return mixed
     */
    public static function filterResponse( $result, $server, $request ) {
        if ( ! $result instanceof \WP_REST_Response ) return $result;

        // The pattern the server dispatched to, not the request path: WordPress
        // matches routes case-insensitively, so a path is not a safe key.
        $route = (string) $result->get_matched_route();
        if ( $route === '' ) return $result;

        $user_id = get_current_user_id();
        foreach ( self::registry() as $entity => $spec ) {
            if ( ! isset( $spec['routes'][ $route ] ) ) continue;

            $segments = self::segments( $spec['routes'][ $route ]['rows'] );
            $id_key   = $spec['routes'][ $route ]['id_key'];
            $data     = $result->get_data();

            $rows = [];
            self::collectAt( $data, $segments, $id_key, $rows );
            if ( $rows === [] ) continue;
            self::primeRows( $entity, $rows, $id_key, $user_id );

            $result->set_data( self::redactAt( $entity, $data, $segments, $id_key, $user_id ) );
        }
        return $result;
    }

    public static function register(): void {
        static $registered = false;
        if ( $registered ) return;
        $registered = true;
        add_filter( 'rest_post_dispatch', [ __CLASS__, 'filterResponse' ], 10, 3 );
    }

    /**
     * Resolve `guardian_contact` for these person ids in one pass: one read
     * of the people, one of their live children, and the staff answer once
     * per distinct team.
     *
     * @param list<int> $person_ids
     */
    public static function primePeople( int $user_id, array $person_ids ): void {
        $ask = [];
        foreach ( $person_ids as $id ) {
            $id = (int) $id;
            if ( $id > 0 && ! isset( self::$guardian[ $user_id . '|' . $id ] ) ) $ask[ $id ] = $id;
        }
        if ( $ask === [] ) return;
        if ( $user_id <= 0 ) {
            foreach ( $ask as $id ) self::$guardian[ $user_id . '|' . $id ] = false;
            return;
        }

        global $wpdb;
        $in   = implode( ',', array_fill( 0, count( $ask ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, wp_user_id FROM {$wpdb->prefix}tt_people WHERE club_id = %d AND id IN ({$in})",
            CurrentClub::id(),
            ...array_values( $ask )
        ) );
        $account = [];
        foreach ( (array) $rows as $r ) {
            $account[ (int) ( $r->id ?? 0 ) ] = (int) ( $r->wp_user_id ?? 0 );
        }

        $children = \TT\Infrastructure\Players\ParentChildResolver::childTeamsForParents( array_values( array_filter( $account ) ) );
        $teams    = [];
        foreach ( $children as $by_player ) {
            foreach ( $by_player as $pid => $team_id ) $teams[ $pid ] = $team_id;
        }
        self::prime( $user_id, $teams );

        foreach ( $ask as $id ) {
            $wp_user = $account[ $id ] ?? 0;
            $kids    = $wp_user > 0 ? ( $children[ $wp_user ] ?? [] ) : [];
            $allowed = $kids === [] || $wp_user === $user_id;
            foreach ( array_keys( $kids ) as $pid ) {
                if ( $allowed ) break;
                $allowed = ! empty( self::$memo[ $user_id . '|staff_for_player|' . $pid ] );
            }
            self::$guardian[ $user_id . '|' . $id ] = $allowed;
        }
    }

    public static function flush(): void {
        self::$memo        = [];
        self::$family      = [];
        self::$guardian    = [];
        self::$writer      = [];
        self::$writer_team = [];
    }

    // Internals

    private static function mayRead( string $rule, int $user_id, int $player_id ): bool {
        if ( ! in_array( $rule, self::RULES, true ) ) return false;
        if ( $user_id <= 0 || $player_id <= 0 ) return false;

        if ( $rule === 'guardian_contact' ) {
            // The id is a person's, not a player's.
            self::primePeople( $user_id, [ $player_id ] );
            return self::$guardian[ $user_id . '|' . $player_id ] ?? false;
        }

        if ( ( $rule === 'staff_or_family' || $rule === 'evaluation_comment' )
            && isset( self::familyOf( $user_id )[ $player_id ] ) ) {
            return true;
        }

        if ( $rule === 'evaluation_notes' || $rule === 'evaluation_comment' ) {
            if ( ! isset( self::$writer[ $user_id . '|' . $player_id ] ) ) self::prime( $user_id, [ $player_id => null ] );
            if ( self::$writer[ $user_id . '|' . $player_id ] ?? false ) return true;
        }

        $key = $user_id . '|staff_for_player|' . $player_id;
        if ( ! isset( self::$memo[ $key ] ) ) {
            self::$memo[ $key ] = AuthorizationService::isStaffForPlayer( $user_id, $player_id );
        }
        return self::$memo[ $key ];
    }

    private static function known( int $user_id, int $player_id ): bool {
        return isset( self::$memo[ $user_id . '|staff_for_player|' . $player_id ], self::$writer[ $user_id . '|' . $player_id ] );
    }

    /**
     * The players this user is (their own record) or is a linked guardian
     * of, from the canonical resolver: `AccountPlayerLinks::forUser()`,
     * which applies the club, the player's status and the record lifecycle,
     * so a guardian whose access ended (released, archived, binned) is not
     * family here either. Read once per reader per request.
     *
     * @return array<int, true>
     */
    private static function familyOf( int $user_id ): array {
        if ( isset( self::$family[ $user_id ] ) ) return self::$family[ $user_id ];

        $links = \TT\Infrastructure\Players\AccountPlayerLinks::forUser( $user_id );
        $set   = [];
        if ( $links['player'] !== null && $links['player']['id'] > 0 ) {
            $set[ $links['player']['id'] ] = true;
        }
        foreach ( $links['children'] as $child ) {
            if ( $child['id'] > 0 && $child['access'] !== \TT\Infrastructure\Players\ParentChildResolver::ACCESS_NONE ) {
                $set[ $child['id'] ] = true;
            }
        }
        self::$family[ $user_id ] = $set;
        return $set;
    }

    /** @param list<array<string, mixed>> $rows */
    private static function primeRows( string $entity, array $rows, string $id_key, int $user_id ): void {
        if ( in_array( 'guardian_contact', self::fields( $entity ), true ) ) {
            $ids = [];
            foreach ( $rows as $row ) $ids[] = (int) ( $row[ $id_key ] ?? 0 );
            self::primePeople( $user_id, $ids );
            return;
        }

        $team_by_player = [];
        foreach ( $rows as $row ) {
            $pid = (int) ( $row[ $id_key ] ?? 0 );
            if ( $pid <= 0 ) continue;
            // A row's own `team_id` is the player's only when the row is the
            // player record itself.
            $team_by_player[ $pid ] = $id_key === 'id' && array_key_exists( 'team_id', $row ) ? (int) $row['team_id'] : null;
        }
        self::prime( $user_id, $team_by_player );
    }

    /**
     * `data.rows.*` => [ 'data', 'rows', '*' ]; an empty path is the top.
     *
     * @return list<string>
     */
    private static function segments( string $path ): array {
        return $path === '' ? [] : explode( '.', $path );
    }

    /**
     * The node as an array to read, or null when it holds nothing to walk:
     * an array as it is, a `stdClass` by its properties, a `JsonSerializable`
     * by what it serializes to.
     *
     * @param mixed $node
     * @return array<mixed>|null
     */
    private static function view( $node ): ?array {
        if ( is_array( $node ) ) return $node;
        if ( $node instanceof \stdClass ) return get_object_vars( $node );
        if ( $node instanceof \JsonSerializable ) {
            $raw = $node->jsonSerialize();
            if ( is_array( $raw ) ) return $raw;
            if ( $raw instanceof \stdClass ) return get_object_vars( $raw );
        }
        return null;
    }

    /**
     * The rows at the end of `$segments`: a node there with a positive id
     * under `$id_key`.
     *
     * @param mixed                      $node
     * @param list<string>               $segments
     * @param list<array<string, mixed>> $rows
     */
    private static function collectAt( $node, array $segments, string $id_key, array &$rows ): void {
        $view = self::view( $node );
        if ( $view === null ) return;

        if ( $segments === [] ) {
            if ( self::isRow( $view, $id_key ) ) {
                /** @var array<string, mixed> $view */
                $rows[] = $view;
            }
            return;
        }

        $head = array_shift( $segments );
        if ( $head === '*' ) {
            foreach ( $view as $child ) self::collectAt( $child, $segments, $id_key, $rows );
        } elseif ( array_key_exists( $head, $view ) ) {
            self::collectAt( $view[ $head ], $segments, $id_key, $rows );
        }
    }

    /**
     * The node with the rows at `$segments` redacted, of the same JSON
     * shape: an array stays an array, an object stays an object (so an
     * empty `{}` stays `{}`), and a `JsonSerializable` that needed no change
     * is returned as it came.
     *
     * @param mixed        $node
     * @param list<string> $segments
     * @return mixed
     */
    private static function redactAt( string $entity, $node, array $segments, string $id_key, int $user_id ) {
        $view = self::view( $node );
        if ( $view === null ) return $node;

        if ( $segments === [] ) {
            $new = self::isRow( $view, $id_key )
                ? self::redact( $entity, $view, $user_id, (int) $view[ $id_key ] )
                : $view;
        } else {
            $head = array_shift( $segments );
            $new  = $view;
            if ( $head === '*' ) {
                foreach ( $view as $key => $child ) {
                    $new[ $key ] = self::redactAt( $entity, $child, $segments, $id_key, $user_id );
                }
            } elseif ( array_key_exists( $head, $view ) ) {
                $new[ $head ] = self::redactAt( $entity, $view[ $head ], $segments, $id_key, $user_id );
            }
        }

        return self::reshape( $node, $view, $new );
    }

    /**
     * Put a walked node back in the form it arrived in.
     *
     * @param mixed        $original
     * @param array<mixed> $view
     * @param array<mixed> $new
     * @return mixed
     */
    private static function reshape( $original, array $view, array $new ) {
        if ( is_array( $original ) ) return $new;
        if ( $original instanceof \stdClass ) return (object) $new;

        // JsonSerializable: untouched when nothing changed, otherwise what it
        // serialized to, as an object when that was an object.
        if ( wp_json_encode( $new ) === wp_json_encode( $view ) ) return $original;
        $raw = $original instanceof \JsonSerializable ? $original->jsonSerialize() : null;
        if ( $raw instanceof \stdClass || $view === [] || array_keys( $view ) !== range( 0, count( $view ) - 1 ) ) {
            return (object) $new;
        }
        return $new;
    }

    /** @param array<mixed> $node */
    private static function isRow( array $node, string $id_key ): bool {
        return isset( $node[ $id_key ] ) && is_numeric( $node[ $id_key ] ) && (int) $node[ $id_key ] > 0;
    }

    /** @return array<string, array{routes: array<string, array{rows: string, id_key: string}>, id_key: string, fields: array<string, string>}> */
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
            $default_key = is_string( $spec['id_key'] ?? null ) && $spec['id_key'] !== '' ? $spec['id_key'] : 'player_id';
            $routes      = [];
            foreach ( (array) ( $spec['routes'] ?? [] ) as $pattern => $route ) {
                if ( ! is_string( $pattern ) || $pattern === '' || ! is_array( $route ) || ! is_string( $route['rows'] ?? null ) ) continue;
                $routes[ $pattern ] = [
                    'rows'   => $route['rows'],
                    'id_key' => is_string( $route['id_key'] ?? null ) && $route['id_key'] !== '' ? $route['id_key'] : $default_key,
                ];
            }
            $fields = [];
            foreach ( (array) ( $spec['fields'] ?? [] ) as $field => $rule ) {
                if ( is_string( $field ) && is_string( $rule ) ) $fields[ $field ] = $rule;
            }
            self::$registry[ (string) $entity ] = [
                'routes' => $routes,
                'id_key' => $default_key,
                'fields' => $fields,
            ];
        }
        return self::$registry;
    }
}
