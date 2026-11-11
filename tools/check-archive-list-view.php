<?php
/**
 * check-archive-list-view.php (#5118)
 *
 * An ordinary list never shows items in the recycle bin. The recycle bin is
 * its own screen, behind `tt_manage_recycle_bin`; every other list reads
 * `trashed` as `active` through `ArchiveRepository::listView()` /
 * `listViewClause()`.
 *
 * `ArchiveRepository::filterClause()` answers every state, the bin
 * included, so this gate fails any call to it outside the archive and
 * recycle-bin code whose state is not a literal ordinary-list state
 * (`'active'`, `'archived'`, `'all'`) or a `listView()` result. The parser
 * and the exempt paths are in `tools/lib/archive-list-view.php`.
 *
 * Usage:  php tools/check-archive-list-view.php
 * Exit:   0 clean, 1 a call that could pass the recycle bin to a list.
 */

declare( strict_types = 1 );

require_once __DIR__ . '/lib/archive-list-view.php';

$root = dirname( __DIR__ );
$src  = $root . '/src';
if ( ! is_dir( $src ) ) {
    fwrite( STDERR, "check-archive-list-view: cannot read src/\n" );
    exit( 1 );
}

$prefix    = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';
$offenders = [];
$calls     = 0;

$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
    if ( ! $file->isFile() || strtolower( $file->getExtension() ) !== 'php' ) continue;
    $relative = str_replace( '\\', '/', $file->getPathname() );
    if ( strpos( $relative, $prefix ) === 0 ) $relative = substr( $relative, strlen( $prefix ) );

    foreach ( TT_ARCHIVE_VIEW_EXEMPT as $exempt ) {
        if ( strpos( $relative, $exempt ) === 0 ) continue 2;
    }

    $code = (string) file_get_contents( $file->getPathname() );
    if ( strpos( $code, 'filterClause' ) === false ) continue;
    $calls += substr_count( $code, 'filterClause' );
    foreach ( tt_archive_view_offenders( $code ) as $line ) {
        $offenders[] = "{$relative}:{$line}";
    }
}

if ( $offenders !== [] ) {
    foreach ( $offenders as $where ) {
        echo "::error file=" . explode( ':', $where )[0] . "::filterClause() given a state that could be the recycle bin. Pass a literal state or ArchiveRepository::listView(), or use listViewClause().\n";
        echo "  {$where}\n";
    }
    echo 'check-archive-list-view FAILED — ' . count( $offenders ) . " call(s).\n";
    exit( 1 );
}

echo "check-archive-list-view OK — {$calls} filterClause mention(s) outside the archive code, each with a literal state or a listView() result.\n";
exit( 0 );
