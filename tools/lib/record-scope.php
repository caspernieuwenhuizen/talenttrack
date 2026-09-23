<?php
/**
 * The parser behind `tools/check-record-scope.php` (#4004).
 *
 * Split out from the CLI script so it can be tested against snippets rather
 * than against the repository — a gate whose own behaviour is unverified is
 * the same category of problem as the bug it exists to catch. See
 * `tests/php/RecordScopeGateTest.php`.
 *
 * WHAT IT DECIDES
 *
 * Every capability in this plugin is club-wide. `tt_view_activities` says the
 * caller reads activities; it never says whose. So a surface that takes a
 * record id and asks only a capability has asked the wrong question, and four
 * fixes in two weeks (#3987, #3998, #4000, #4001, #4002, #4003) were all that
 * one shape. This finds by-id surfaces and asks whether each one performs a
 * per-record check or declares why it does not.
 *
 * The unit of judgement is a FUNCTION, with two deliberate widenings:
 *
 *   - A REST route's `callback` and `permission_callback` are ONE unit. They
 *     are two halves of one decision and the check may sit in either; asking
 *     them separately produced about fifteen false negatives in the audit
 *     this gate came out of.
 *   - A check one level deep counts. `mayWrite()`, `goalRefusal()`,
 *     `scopedActivityId()` — the fix for this shape is usually a small private
 *     helper, and a gate that could not see through one call would push people
 *     to inline it.
 *
 * WHAT IT CANNOT DECIDE
 *
 * It reads for the call, not for the meaning. A surface that calls
 * `canManage()` where `canManageForTeam()` was meant passes — that is the
 * example in the issue, and it is what review is still for. A surface that
 * checks one of two records it touches passes too.
 *
 * It is a review aid that makes the question unavoidable, not a proof that the
 * answer is right — which is why the marker asks for a reason rather than
 * setting a flag.
 *
 * The generic token walk (function bodies, innermost enclosing unit, body text
 * with comments dropped) is shared with `attendance-scope.php` rather than
 * copied: the two gates ask different questions about the same parse.
 */

declare( strict_types = 1 );

require_once __DIR__ . '/attendance-scope.php';

const TT_RS_MARKER = 'record-scope-ok';

/**
 * How many same-class calls deep a check still counts. See the comment at the
 * expansion site: three is what the shipped refusal helpers actually need, not
 * a round number.
 */
const TT_RS_CALL_DEPTH = 3;

/** The reasons a marker may give. A fourth is a decision, not a typo. */
const TT_RS_REASONS = [
    // Lookups, vocabulary, configuration, a module toggle. There is no player
    // or team the record belongs to, so there is nothing to narrow to.
    'no player dimension',
    // The WHERE already names the caller's own player or team, so the query
    // cannot return anybody else's row. `FrontendMyGoalsView` is the model.
    'caller-scoped query',
    // A share link, an invitation, a signed URL. Holding the token IS the
    // grant; there is no session to ask a question about.
    'token is the grant',
];

/**
 * A by-id surface the gate found, and what it decided about it.
 *
 * `key` is the `file::method` spelling the grandfather list uses.
 *
 * @phpstan-type RecordScopeUnit array{
 *   key:string, name:string, line:int, kind:string,
 *   passed:bool, marked:bool, bad_reason:string
 * }
 */

/**
 * Every by-id surface in one file.
 *
 * @param array{checks:list<string>, capability_gates:list<string>, id_params:list<string>, loaders:list<string>} $config
 * @return list<array{key:string, name:string, line:int, kind:string, passed:bool, marked:bool, bad_reason:string}>
 */
function tt_rs_units( string $code, string $relative, array $config ): array {
    $tokens = token_get_all( $code );
    $count  = count( $tokens );

    $units = tt_rs_function_units( $tokens, $count );
    if ( $units === [] ) return [];

    // name() => the unit, for callee expansion and for resolving a route's
    // callback pair. A name declared twice in one file (two closures, or a
    // method inside an anonymous class) keeps the first — the ambiguity is
    // rare and resolving it wrongly would only ever widen the searched text.
    $by_name = [];
    foreach ( $units as $unit ) {
        $plain = rtrim( $unit['name'], '()' );
        if ( $plain !== '' && ! isset( $by_name[ $plain ] ) ) {
            $by_name[ $plain ] = $unit;
        }
    }

    $body = [];
    foreach ( $units as $index => $unit ) {
        $body[ $index ] = tt_unit_code( $tokens, $unit['open'], $unit['close'] );
    }

    // Candidate surfaces: method name => [ kind, [ unit indexes ] ]. A REST
    // pair contributes two indexes under one key.
    $candidates = [];

    foreach ( tt_rs_rest_routes( $tokens, $count, $config['id_params'] ) as $route ) {
        $indexes = [];
        $names   = [];
        foreach ( $route['handlers'] as $handler ) {
            if ( ! isset( $by_name[ $handler ] ) ) continue;
            $indexes[] = $by_name[ $handler ]['index'];
            $names[]   = $handler;
        }
        if ( $indexes === [] ) continue;

        $key = implode( ' + ', $names );
        if ( isset( $candidates[ $key ] ) ) continue;
        $candidates[ $key ] = [
            'kind'    => 'rest route ' . $route['route'],
            'indexes' => $indexes,
            'line'    => $route['line'],
            'name'    => $key,
        ];
    }

    $is_view    = strpos( $relative, '/Frontend/' ) !== false;
    $is_exporter = strpos( $relative, '/Exporters/' ) !== false;
    $handlers   = tt_rs_hooked_handlers( $tokens, $count );

    foreach ( $units as $index => $unit ) {
        $plain = rtrim( $unit['name'], '()' );
        $text  = $body[ $index ];

        $reads_id = tt_rs_reads_request_id( $text, $config['id_params'] );
        $loads    = tt_rs_loads_record( $text, $config['loaders'] );

        $kind = null;
        if ( $is_view && $reads_id && $loads ) {
            $kind = 'view';
        } elseif ( isset( $handlers[ $plain ] ) && $reads_id && $loads ) {
            $kind = $handlers[ $plain ];
        } elseif ( $is_exporter && $plain === 'collect' && tt_rs_reads_export_id( $text ) ) {
            $kind = 'exporter';
        }
        if ( $kind === null ) continue;

        if ( isset( $candidates[ $plain ] ) ) continue;
        $candidates[ $plain ] = [
            'kind'    => $kind,
            'indexes' => [ $index ],
            'line'    => $unit['line'],
            'name'    => $unit['name'],
        ];
    }

    if ( $candidates === [] ) return [];

    $out = [];
    foreach ( $candidates as $candidate ) {
        // The searched text is the unit(s) plus the same-class methods they
        // reach within TT_RS_CALL_DEPTH calls. The depth is not a round
        // number: it is what the shipped fixes need.
        // `ActivitiesRestController::set_status()` asks
        // `refuseUnlessActivityWritable()`, which asks
        // `refuseUnlessTeamWritable()`, which asks `gridAllowedTeamIds()`,
        // which is where `get_teams_for_coach()` finally appears. A shallower
        // walk would report a checked route as unchecked, which is the kind of
        // false alarm that gets a gate switched off.
        $searched = '';
        foreach ( $candidate['indexes'] as $index ) {
            $searched .= tt_rs_expanded( $index, $body, $by_name, TT_RS_CALL_DEPTH );
        }

        $marked     = false;
        $bad_reason = '';
        foreach ( $candidate['indexes'] as $index ) {
            $unit   = $units[ $index ];
            $found  = tt_rs_marker_reason( $tokens, $unit['open'], $unit['close'] );
            if ( $found === null ) continue;
            if ( in_array( $found, TT_RS_REASONS, true ) ) {
                $marked = true;
            } else {
                $bad_reason = $found;
            }
        }

        $out[] = [
            'key'        => $relative . '::' . rtrim( $candidate['name'], '()' ),
            'name'       => $candidate['name'],
            'line'       => (int) $candidate['line'],
            'kind'       => (string) $candidate['kind'],
            'passed'     => tt_rs_has_check( $searched, $config ),
            'marked'     => $marked,
            'bad_reason' => $bad_reason,
        ];
    }

    return $out;
}

/**
 * A unit's body plus the bodies of the same-class methods it reaches within
 * `$depth` calls. Visited names are tracked, so mutual recursion terminates.
 *
 * @param array<int, string> $body
 * @param array<string, array{index:int, open:int, close:int, name:string, line:int}> $by_name
 * @param array<string, true> $seen
 */
function tt_rs_expanded( int $index, array $body, array $by_name, int $depth, array &$seen = [] ): string {
    if ( ! isset( $body[ $index ] ) ) return '';

    $out = $body[ $index ];
    if ( $depth <= 0 ) return $out;

    foreach ( tt_rs_callees( $body[ $index ] ) as $callee ) {
        if ( isset( $seen[ $callee ] ) || ! isset( $by_name[ $callee ] ) ) continue;
        $seen[ $callee ] = true;
        $out .= tt_rs_expanded( $by_name[ $callee ]['index'], $body, $by_name, $depth - 1, $seen );
    }
    return $out;
}

/**
 * Function bodies in declaration order, carrying their own index so a callee
 * can be looked up by name.
 *
 * @param array<int, array{0:int, 1:string, 2:int}|string> $tokens
 * @return list<array{index:int, open:int, close:int, name:string, line:int}>
 */
function tt_rs_function_units( array $tokens, int $count ): array {
    $units = [];
    for ( $i = 0; $i < $count; $i++ ) {
        $tok = $tokens[ $i ];
        if ( ! is_array( $tok ) || $tok[0] !== T_FUNCTION ) continue;

        $open = tt_body_open( $tokens, $i, $count );
        if ( $open === null ) continue;
        $close = tt_body_close( $tokens, $open, $count );
        if ( $close === null ) continue;

        $units[] = [
            'index' => count( $units ),
            'open'  => $open,
            'close' => $close,
            'name'  => tt_function_name( $tokens, $i ),
            'line'  => (int) $tok[2],
        ];
    }
    return $units;
}

/**
 * `register_rest_route()` calls whose route carries a record-id parameter.
 *
 * The route is read from the call's own arguments rather than from a regex
 * over the file, so a controller registering thirty routes is thirty
 * decisions and not one.
 *
 * @param array<int, array{0:int, 1:string, 2:int}|string> $tokens
 * @param list<string> $id_params
 * @return list<array{route:string, line:int, handlers:list<string>}>
 */
function tt_rs_rest_routes( array $tokens, int $count, array $id_params ): array {
    $out = [];
    for ( $i = 0; $i < $count; $i++ ) {
        $tok = $tokens[ $i ];
        if ( ! is_array( $tok ) || $tok[0] !== T_STRING || $tok[1] !== 'register_rest_route' ) continue;

        $args = tt_rs_call_arguments( $tokens, $i, $count );
        if ( $args === null ) continue;

        $route = tt_rs_id_route( $args['text'], $id_params );
        if ( $route === null ) continue;

        $out[] = [
            'route'    => $route,
            'line'     => (int) $tok[2],
            'handlers' => tt_rs_quoted_strings( $args['text'] ),
        ];
    }
    return $out;
}

/**
 * The route pattern when it names a record id, else null.
 *
 * `(?P<id>\d+)` and `(?P<player_id>[0-9]+)` count; `(?P<slug>[a-z-]+)` and
 * `(?P<section>…)` do not — a slug is a lookup key, not a record somebody
 * owns.
 *
 * @param list<string> $id_params
 */
function tt_rs_id_route( string $args, array $id_params ): ?string {
    if ( ! preg_match_all( '/\(\?P<([A-Za-z_]+)>/', $args, $m ) ) return null;

    foreach ( $m[1] as $param ) {
        foreach ( $id_params as $wanted ) {
            if ( $param === $wanted || $param === 'uuid' ) {
                // The route literal, for the report — first quoted string in
                // the call that carries the parameter.
                if ( preg_match( '/[\'"]([^\'"]*\(\?P<' . preg_quote( $param, '/' ) . '>[^\'"]*)[\'"]/', $args, $rm ) ) {
                    return $rm[1];
                }
                return '(?P<' . $param . '>…)';
            }
        }
    }
    return null;
}

/**
 * Methods hooked to an id-bearing entry point: `admin_post_*`, `wp_ajax_*`,
 * `template_redirect`. These are the surfaces with no permission callback and
 * no view dispatcher above them, so nothing else asks the question for them.
 *
 * @param array<int, array{0:int, 1:string, 2:int}|string> $tokens
 * @return array<string, string> method name => the hook kind, for the report
 */
function tt_rs_hooked_handlers( array $tokens, int $count ): array {
    $out = [];
    for ( $i = 0; $i < $count; $i++ ) {
        $tok = $tokens[ $i ];
        if ( ! is_array( $tok ) || $tok[0] !== T_STRING || $tok[1] !== 'add_action' ) continue;

        $args = tt_rs_call_arguments( $tokens, $i, $count );
        if ( $args === null ) continue;

        $strings = tt_rs_quoted_strings( $args['text'] );
        $hook    = null;
        foreach ( tt_rs_all_quoted( $args['text'] ) as $value ) {
            if ( strpos( $value, 'admin_post_' ) === 0 ) { $hook = 'admin-post handler'; break; }
            if ( strpos( $value, 'wp_ajax_' ) === 0 )    { $hook = 'ajax handler'; break; }
            if ( $value === 'template_redirect' )        { $hook = 'template_redirect handler'; break; }
        }
        if ( $hook === null ) continue;

        foreach ( $strings as $name ) {
            $out[ $name ] = $hook;
        }
    }
    return $out;
}

/**
 * The text of a function call's argument list, parentheses excluded.
 *
 * @param array<int, array{0:int, 1:string, 2:int}|string> $tokens
 * @return array{text:string}|null
 */
function tt_rs_call_arguments( array $tokens, int $name_index, int $count ): ?array {
    $open = null;
    for ( $i = $name_index + 1; $i < $count; $i++ ) {
        if ( $tokens[ $i ] === '(' ) { $open = $i; break; }
        if ( is_array( $tokens[ $i ] ) && $tokens[ $i ][0] === T_WHITESPACE ) continue;
        return null; // not a call
    }
    if ( $open === null ) return null;

    $depth = 0;
    $text  = '';
    for ( $i = $open; $i < $count; $i++ ) {
        $tok = $tokens[ $i ];
        if ( $tok === '(' ) {
            $depth++;
            if ( $depth === 1 ) continue;
        }
        if ( $tok === ')' ) {
            $depth--;
            if ( $depth === 0 ) return [ 'text' => $text ];
        }
        $text .= is_string( $tok ) ? $tok : $tok[1];
    }
    return null;
}

/**
 * Quoted strings in a call's arguments that look like a PHP method name, so a
 * `[ self::class, 'get_player' ]` callback resolves while `'GET'`, a route
 * pattern and a namespace do not.
 *
 * @return list<string>
 */
function tt_rs_quoted_strings( string $args ): array {
    $out = [];
    foreach ( tt_rs_all_quoted( $args ) as $value ) {
        if ( ! preg_match( '/^[a-z_][a-z0-9_]*$/i', $value ) ) continue;
        if ( in_array( $value, [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', '__return_true', '__return_false' ], true ) ) continue;
        $out[] = $value;
    }
    return array_values( array_unique( $out ) );
}

/** @return list<string> */
function tt_rs_all_quoted( string $args ): array {
    if ( ! preg_match_all( '/\'([^\']*)\'|"([^"$]*)"/', $args, $m, PREG_SET_ORDER ) ) return [];
    $out = [];
    foreach ( $m as $set ) {
        $out[] = $set[1] !== '' ? $set[1] : ( $set[2] ?? '' );
    }
    return $out;
}

/**
 * Does the unit read a record id out of the request?
 *
 * @param list<string> $id_params
 */
function tt_rs_reads_request_id( string $text, array $id_params ): bool {
    foreach ( $id_params as $param ) {
        $quoted = preg_quote( $param, '/' );
        if ( preg_match( '/\$_(GET|POST|REQUEST)\s*\[\s*[\'"]' . $quoted . '[\'"]/', $text ) ) return true;
        if ( preg_match( '/\$r(equest)?\s*\[\s*[\'"]' . $quoted . '[\'"]/', $text ) ) return true;
        if ( preg_match( '/get_param\(\s*[\'"]' . $quoted . '[\'"]/', $text ) ) return true;
    }
    return false;
}

/** An exporter's `collect()` reading the id it was asked for. */
function tt_rs_reads_export_id( string $text ): bool {
    if ( strpos( $text, 'entityId' ) !== false ) return true;
    return (bool) preg_match( '/filters\s*\[\s*[\'"][a-z_]*_id[\'"]/', $text );
}

/**
 * Does the unit load a record by that id? A surface that reads an id and
 * passes it to a list filter is narrowing, not opening a record.
 *
 * @param list<string> $loaders
 */
function tt_rs_loads_record( string $text, array $loaders ): bool {
    foreach ( $loaders as $needle ) {
        if ( strpos( $text, $needle ) !== false ) return true;
    }
    // An inline single-row read: `FROM {$p}tt_things WHERE id = %d`.
    return (bool) preg_match( '/FROM\s+\{?\$\w+[^\s]*tt_\w+[\s\S]{0,400}?WHERE[\s\S]{0,200}?\bid\s*=\s*%d/i', $text );
}

/**
 * Same-class methods the unit calls, for the one-level-deep expansion.
 *
 * @return list<string>
 */
function tt_rs_callees( string $text ): array {
    if ( ! preg_match_all( '/(?:self|static)\s*::\s*([a-z_][a-z0-9_]*)\s*\(|\$this\s*->\s*([a-z_][a-z0-9_]*)\s*\(/i', $text, $m, PREG_SET_ORDER ) ) {
        return [];
    }
    $out = [];
    foreach ( $m as $set ) {
        $name = $set[1] !== '' ? $set[1] : ( $set[2] ?? '' );
        if ( $name !== '' ) $out[] = $name;
    }
    return array_values( array_unique( $out ) );
}

/**
 * Is there a per-record check in the searched text?
 *
 * Three ways to pass, all of them deliberate:
 *
 *   1. A name from `config/record_scope_checks.php`. A trailing `*` matches a
 *      family (`ActivityTeamScope::covers*`), because the family members ask
 *      the same question about different arguments.
 *   2. A capability the config names as an admin escape hatch. The recycle
 *      bin's `/permanent` routes are the case: reaching past every per-record
 *      rule is what the capability is for, and it is granted to nobody else.
 *   3. A caller-scoped load — `get_current_user_id()` reaching the loader, so
 *      the WHERE can only return the caller's own row. This is the
 *      `findForPlayer($id, $player->id)` shape the audit tripped over.
 *
 * @param array{checks:list<string>, capability_gates:list<string>, id_params:list<string>, loaders:list<string>} $config
 */
function tt_rs_has_check( string $text, array $config ): bool {
    foreach ( $config['checks'] as $needle ) {
        if ( substr( $needle, -1 ) === '*' ) {
            if ( strpos( $text, substr( $needle, 0, -1 ) ) !== false ) return true;
            continue;
        }
        if ( strpos( $text, $needle ) !== false ) return true;
    }

    foreach ( $config['capability_gates'] as $cap ) {
        if ( strpos( $text, $cap ) !== false ) return true;
    }

    return tt_rs_caller_scoped( $text );
}

/**
 * Does a `get_current_user_id()`-derived value reach a loader?
 *
 * Either passed inline, or through a variable assigned from it in the same
 * text. Both spellings mean the WHERE names the caller, which is a per-record
 * check written as a query rather than as an if.
 */
function tt_rs_caller_scoped( string $text ): bool {
    if ( strpos( $text, 'get_current_user_id' ) === false ) return false;

    // Inline: ->findFor( $id, get_current_user_id() )
    if ( preg_match( '/->\s*[a-z_][a-z0-9_]*\s*\([^()]*get_current_user_id\s*\(\s*\)/i', $text ) ) return true;

    if ( ! preg_match_all( '/\$([a-z_][a-z0-9_]*)\s*=\s*(?:\(int\)\s*)?get_current_user_id\s*\(\s*\)/i', $text, $m ) ) {
        return false;
    }
    foreach ( $m[1] as $var ) {
        if ( preg_match( '/->\s*[a-z_][a-z0-9_]*\s*\([^()]*\$' . preg_quote( $var, '/' ) . '\b/i', $text ) ) {
            return true;
        }
    }
    return false;
}

/**
 * The reason a `record-scope-ok` marker inside the unit gives, or null when
 * there is no marker. An empty string means the marker carried no reason,
 * which the gate reports as a bad reason rather than silently accepting.
 *
 * @param array<int, array{0:int, 1:string, 2:int}|string> $tokens
 */
function tt_rs_marker_reason( array $tokens, int $open, int $close ): ?string {
    for ( $i = $open; $i <= $close; $i++ ) {
        $tok = $tokens[ $i ];
        if ( ! is_array( $tok ) ) continue;
        if ( $tok[0] !== T_COMMENT && $tok[0] !== T_DOC_COMMENT ) continue;
        if ( strpos( $tok[1], TT_RS_MARKER ) === false ) continue;

        if ( preg_match( '/' . TT_RS_MARKER . '\s*:\s*([^*\/\r\n]+)/', $tok[1], $m ) ) {
            return trim( $m[1] );
        }
        return '';
    }
    return null;
}
