<?php
/**
 * check-record-scope.php (#4004)
 *
 * Four fixes in two weeks were the same shape: a surface that loads a record
 * by id and asks only "may you do this kind of thing", never "may you do it to
 * THIS record". #3987, #3998, #4000, #4001, #4002 and #4003 all closed
 * instances of it. This gate makes the next one impossible to merge unnoticed.
 *
 * Every capability in this plugin is club-wide, which is why the shape keeps
 * coming back: `tt_view_activities` is true for every coach, so a
 * `permission_callback` that asks it has answered a different question than
 * the one the route needs. The capability is necessary and never sufficient.
 *
 * WHAT IT ASSERTS
 *
 * Every by-id record surface either performs a per-record check or declares
 * why it does not. Three detectors find the surfaces:
 *
 *   1. REST — `register_rest_route()` whose route carries a record-id
 *      parameter. The `callback` and `permission_callback` are resolved
 *      together and judged as ONE unit: they are two halves of one decision
 *      and the check may sit in either.
 *   2. Views — a method under `src/**\/Frontend/` that reads an id out of the
 *      request and loads a record with it.
 *   3. Handlers — `admin_post_*`, `wp_ajax_*` and `template_redirect` targets
 *      that do the same, plus every `Exporters/*::collect()` reading the id it
 *      was asked for. `ExportService::run()` treats `collect()` as the
 *      authoritative check, so that is where an exporter's belongs.
 *
 * WHAT PASSES
 *
 * A call — in the resolved method or one level deep — to a name in
 * `config/record_scope_checks.php`. A check one level deep counts because the
 * fix for this shape is usually a small private helper (`mayWrite()`,
 * `goalRefusal()`, `scopedActivityId()`), and a gate that could not see
 * through one call would push people to inline it.
 *
 * HOW AN EXCEPTION DECLARES ITSELF
 *
 *     /* record-scope-ok: no player dimension *\/
 *
 * anywhere inside the method, following the `both-kinds-ok` precedent in
 * `check-attendance-scope.php`. Three reasons are pre-approved and the gate
 * accepts no others:
 *
 *   - `no player dimension` — a lookup, config, vocabulary. Nothing to narrow.
 *   - `caller-scoped query` — the WHERE already names the caller's own player
 *     or team, so it cannot return anybody else's row.
 *   - `token is the grant` — a share link or invitation. The token IS the
 *     credential; there is no session to ask about.
 *
 * A marker on a surface that is actually wrong is worse than no gate at all.
 * Do not add one to make the build pass.
 *
 * GRANDFATHERING
 *
 * `config/record_scope_grandfathered.php` lists the surfaces that were already
 * unchecked when this landed. The gate fails only on a surface not listed —
 * the same arrangement as the inline-style gate (#1389), and what lets this
 * ship in one PR instead of a sweep. Every later fix deletes a line; when the
 * file is empty, delete it.
 *
 * WHAT IT CANNOT DECIDE
 *
 * It catches an absent check, not a check asking the wrong question.
 * `FrontendTeamBlueprintsView` calling the global `canManage()` where
 * `canManageForTeam()` was meant would have passed this. That is what review
 * is still for.
 *
 * Usage:  php tools/check-record-scope.php
 *         php tools/check-record-scope.php --list   (print the offenders as a
 *                                                    grandfather-file body)
 * Exit:   0 clean, 1 an unchecked, unmarked, ungrandfathered surface — or a
 *         grandfather entry that no longer names a surface, because a stale
 *         exemption is how a gate goes quietly blind.
 */

declare( strict_types = 1 );

require_once __DIR__ . '/lib/record-scope.php';

$root = dirname( __DIR__ );
$src  = $root . '/src';

if ( ! is_dir( $src ) ) {
    fwrite( STDERR, "check-record-scope: cannot read src/\n" );
    exit( 1 );
}

$config = require $root . '/config/record_scope_checks.php';
foreach ( [ 'checks', 'capability_gates', 'exempt_directories', 'id_params', 'loaders' ] as $key ) {
    if ( ! isset( $config[ $key ] ) || ! is_array( $config[ $key ] ) ) {
        fwrite( STDERR, "check-record-scope: config/record_scope_checks.php is missing `{$key}`.\n" );
        exit( 1 );
    }
}

$grandfather_file = $root . '/config/record_scope_grandfathered.php';
$grandfathered    = is_file( $grandfather_file ) ? (array) require $grandfather_file : [];

$list_mode = in_array( '--list', $argv ?? [], true );

$offenders   = [];
$passed      = 0;
$marked      = 0;
$exempted    = 0;
$bad_reasons = [];
$seen_keys   = [];

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS )
);

foreach ( $it as $file ) {
    if ( ! $file->isFile() || strtolower( $file->getExtension() ) !== 'php' ) continue;

    $relative = str_replace( '\\', '/', $file->getPathname() );
    $prefix   = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';
    if ( strpos( $relative, $prefix ) === 0 ) {
        $relative = substr( $relative, strlen( $prefix ) );
    }

    $skip = false;
    foreach ( $config['exempt_directories'] as $dir ) {
        if ( strpos( $relative, $dir ) === 0 ) { $skip = true; break; }
    }
    if ( $skip ) { $exempted++; continue; }

    $code = (string) @file_get_contents( $file->getPathname() );
    if ( $code === '' ) continue;

    foreach ( tt_rs_units( $code, $relative, $config ) as $unit ) {
        $seen_keys[ $unit['key'] ] = true;

        if ( $unit['bad_reason'] !== '' ) {
            $bad_reasons[] = [ $unit['key'], $unit['line'], $unit['bad_reason'] ];
            continue;
        }
        if ( $unit['passed'] ) { $passed++; continue; }
        if ( $unit['marked'] ) { $marked++; continue; }
        if ( isset( $grandfathered[ $unit['key'] ] ) ) continue;

        $offenders[] = $unit;
    }
}

$checked = $passed + $marked + count( $offenders );

if ( $checked === 0 ) {
    fwrite(
        STDERR,
        "check-record-scope: found no by-id surfaces at all — either the detectors\n"
        . "stopped matching or this gate has gone blind. Fix the gate before merging.\n"
    );
    exit( 1 );
}

if ( $list_mode ) {
    // No line numbers: a grandfather entry outlives the lines around it, and a
    // stale number reads as information when it is noise.
    foreach ( $offenders as $unit ) {
        printf( "    '%s' => '%s',\n", $unit['key'], str_replace( "'", '', $unit['kind'] ) );
    }
    exit( 0 );
}

$stale = [];
foreach ( array_keys( $grandfathered ) as $key ) {
    if ( ! isset( $seen_keys[ (string) $key ] ) ) $stale[] = (string) $key;
}

if ( ! $offenders && ! $bad_reasons && ! $stale ) {
    printf(
        "check-record-scope OK — %d by-id surface(s): %d check the record, %d declare why not, %d grandfathered (%d director%s exempt).\n",
        $checked + count( $grandfathered ),
        $passed,
        $marked,
        count( $grandfathered ),
        count( $config['exempt_directories'] ),
        count( $config['exempt_directories'] ) === 1 ? 'y' : 'ies'
    );
    exit( 0 );
}

echo "check-record-scope FAILED\n\n";

if ( $offenders ) {
    echo "By-id surface(s) that neither check the record nor say why not:\n\n";
    foreach ( $offenders as $unit ) {
        printf( "  %s\n    %s, line %d\n", $unit['key'], $unit['kind'], $unit['line'] );
    }
    echo "\n";
    echo "Every capability here is club-wide: `tt_view_activities` says the caller reads\n";
    echo "activities, never whose. A surface that takes a record id has to ask the second\n";
    echo "question too. Either call one of the checks in config/record_scope_checks.php\n";
    echo "(directly, or from a helper the method calls)...\n\n";
    echo "    if ( ! ActivityTeamScope::coversActivity( \$user_id, \$activity_id ) ) { … }\n\n";
    echo "...or, when there is genuinely nothing to narrow to, say so at the site:\n\n";
    echo "    /* " . TT_RS_MARKER . ": no player dimension */\n";
    echo "    /* " . TT_RS_MARKER . ": caller-scoped query */\n";
    echo "    /* " . TT_RS_MARKER . ": token is the grant */\n\n";
    echo "A refusal should answer exactly as a record that does not exist, so an id\n";
    echo "cannot be walked to learn what the academy holds. See docs/access-control.md.\n\n";
}

if ( $bad_reasons ) {
    echo "Marker(s) giving a reason this gate does not recognise:\n\n";
    foreach ( $bad_reasons as [ $key, $line, $reason ] ) {
        printf( "  %s (line %d)\n    reason: %s\n", $key, $line, $reason === '' ? '(none given)' : $reason );
    }
    echo "\nThe three recognised reasons are:\n";
    foreach ( TT_RS_REASONS as $reason ) {
        echo "  - {$reason}\n";
    }
    echo "\nA fourth reason is a decision about the access model, so it is a change to\n";
    echo "tools/lib/record-scope.php and a line in docs/contributing.md, not a typo.\n\n";
}

if ( $stale ) {
    echo "Grandfather entry/entries that no longer name a by-id surface:\n\n";
    foreach ( $stale as $key ) {
        echo "  {$key}\n";
    }
    echo "\nThe surface was fixed, renamed or deleted. Delete the line from\n";
    echo "config/record_scope_grandfathered.php — a stale exemption is how a gate goes\n";
    echo "quietly blind. When the file is empty, delete the file.\n\n";
}

exit( 1 );
