<?php
namespace TT\Modules\Documentation;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DocFrontMatter — the metadata block at the top of every help topic.
 *
 * Topic metadata used to live in two places: title / group / summary in a
 * PHP literal inside HelpTopics, and the audience in an HTML comment inside
 * the markdown file. Keeping the two in step was manual, so they drifted —
 * 53 files on disk were never registered. The file now carries everything:
 *
 *   ---
 *   title: Match minutes
 *   group: performance
 *   summary: Record minutes played per player per fixture.
 *   audience: [user, admin]
 *   views: [match-minutes, minutes-grid]
 *   module: TT\Modules\MatchExecution\MatchExecutionModule
 *   feature: match_minutes
 *   tier: standard
 *   capability: tt_view_minutes
 *   ---
 *
 * `title` / `group` / `summary` / `audience` are read today. The remaining
 * keys are parsed and exposed but not yet enforced — the live-gating and
 * view-mapping work consumes them.
 *
 * Deliberately not a YAML implementation. We control every input file, so
 * this covers scalars and inline lists and nothing else, in the same spirit
 * as the hand-rolled Markdown renderer next to it. Anything richer belongs
 * in a dependency we have chosen not to take.
 *
 * The parse operates on a string rather than a path so a backend that keeps
 * docs somewhere other than the local filesystem can feed it directly.
 */
final class DocFrontMatter {

    /** Fence delimiting the block, at the very start of the file. */
    private const FENCE = '---';

    /** How much of a file to read when only the block is wanted. */
    private const HEAD_BYTES = 4096;

    /**
     * Parse the leading front-matter block.
     *
     * Returns an empty array when the source has no block, when the block
     * is never closed, or when it holds no recognisable keys. Callers treat
     * "no front matter" as "not a registered topic" rather than an error —
     * that is how a developer-only doc opts out of the in-product index.
     *
     * @return array<string, string|list<string>>
     */
    public static function parse( string $source ): array {
        $source = str_replace( [ "\r\n", "\r" ], "\n", $source );
        if ( strpos( $source, self::FENCE . "\n" ) !== 0 ) {
            return [];
        }

        $lines = explode( "\n", substr( $source, strlen( self::FENCE ) + 1 ) );
        $out   = [];
        $closed = false;

        foreach ( $lines as $line ) {
            if ( rtrim( $line ) === self::FENCE ) {
                $closed = true;
                break;
            }
            $trimmed = trim( $line );
            if ( $trimmed === '' || $trimmed[0] === '#' ) {
                continue;
            }
            if ( ! preg_match( '/^([a-z_][a-z0-9_]*)\s*:\s*(.*)$/i', $trimmed, $m ) ) {
                continue;
            }
            $key = strtolower( $m[1] );
            $out[ $key ] = self::value( trim( $m[2] ) );
        }

        // An unterminated block is a malformed file, not a document whose
        // body happens to start with `---`. Returning nothing keeps it out
        // of the index where the lint can see it.
        return $closed ? $out : [];
    }

    /**
     * The document with its front-matter block removed, ready to render.
     * Sources without a block come back untouched.
     */
    public static function strip( string $source ): string {
        $normalised = str_replace( [ "\r\n", "\r" ], "\n", $source );
        if ( strpos( $normalised, self::FENCE . "\n" ) !== 0 ) {
            return $source;
        }
        $end = strpos( $normalised, "\n" . self::FENCE, strlen( self::FENCE ) );
        if ( $end === false ) {
            return $source;
        }
        $after = substr( $normalised, $end + 1 + strlen( self::FENCE ) );
        return ltrim( (string) $after, "\n" );
    }

    /**
     * Read the block from a file without pulling the whole document into
     * memory. Returns an empty array for a missing or unreadable path.
     *
     * @return array<string, string|list<string>>
     */
    public static function fromFile( ?string $path ): array {
        if ( $path === null || $path === '' || ! is_readable( $path ) ) {
            return [];
        }
        $head = (string) @file_get_contents( $path, false, null, 0, self::HEAD_BYTES );
        if ( $head === '' ) {
            return [];
        }
        return self::parse( $head );
    }

    /**
     * A single key as a string. Lists collapse to their first entry so a
     * caller wanting one value never has to type-check.
     */
    public static function string( array $data, string $key, string $default = '' ): string {
        $raw = $data[ $key ] ?? null;
        if ( is_array( $raw ) ) {
            $raw = $raw[0] ?? null;
        }
        return is_string( $raw ) && $raw !== '' ? $raw : $default;
    }

    /**
     * A single key as a list. Scalars promote to a one-element list so
     * `audience: user` and `audience: [user]` behave the same.
     *
     * @return list<string>
     */
    public static function list( array $data, string $key ): array {
        $raw = $data[ $key ] ?? null;
        if ( is_string( $raw ) ) {
            return $raw === '' ? [] : [ $raw ];
        }
        return is_array( $raw ) ? array_values( $raw ) : [];
    }

    /**
     * Scalar, or inline `[a, b]` list. Quotes around either form are
     * stripped so a summary containing a colon can be quoted safely.
     *
     * @return string|list<string>
     */
    private static function value( string $raw ) {
        if ( strlen( $raw ) > 1 && $raw[0] === '[' && substr( $raw, -1 ) === ']' ) {
            $inner = substr( $raw, 1, -1 );
            $items = [];
            foreach ( explode( ',', $inner ) as $item ) {
                $item = self::unquote( trim( $item ) );
                if ( $item !== '' ) {
                    $items[] = $item;
                }
            }
            return $items;
        }
        return self::unquote( $raw );
    }

    private static function unquote( string $raw ): string {
        $len = strlen( $raw );
        if ( $len >= 2 ) {
            $first = $raw[0];
            $last  = $raw[ $len - 1 ];
            if ( ( $first === '"' && $last === '"' ) || ( $first === "'" && $last === "'" ) ) {
                return substr( $raw, 1, -1 );
            }
        }
        return $raw;
    }
}
