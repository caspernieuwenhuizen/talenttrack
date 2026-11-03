<?php
namespace TT\Infrastructure\Search;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SearchText (#4671) — the one fold function for search, and the writer of
 * the folded columns.
 *
 * A searchable table carries `search_title` (the folded primary label),
 * `search_text` (the folded secondary text, from the row's own columns only)
 * and `search_v` (the {@see self::VERSION} that produced them; null means not
 * folded yet). The stored text and the query are folded by the same
 * function, so they cannot disagree about what "José" or "Den Besten" is.
 *
 * Folding: lower case, accents removed, every character that is not a letter
 * or a digit becomes a space, single-spaced, with one leading space so the
 * start of a word is `LIKE '% tok%'`. Dutch name particles (van, de, den,
 * ter) stay: they are tokens like any other, so "den b" finds Den Besten.
 *
 * Only the row's own columns go in, never text from another table, so a
 * write to one table never leaves another table's search text stale. A
 * stale or missing fold costs a missed match, never a disclosure: access is
 * decided by the provider's scope on the record's own table.
 *
 * Every write to a source column calls {@see self::refresh()}.
 * `tools/check-search-text.php` keeps new write sites honest and
 * `SearchTextSweep` folds what a write path missed, on the engine heartbeat.
 */
final class SearchText {

    /** Bump when the fold changes; the sweep re-folds every row below it. */
    public const VERSION = 1;

    /**
     * The searchable kinds: table, alias-free source columns of the title
     * and of the secondary text, and the demo-tag entity type.
     *
     * @var array<string, array{table:string, title:list<string>, text:list<string>, demo:string}>
     */
    public const KINDS = [
        'player'   => [ 'table' => 'tt_players',    'title' => [ 'first_name', 'last_name' ], 'text' => [],              'demo' => 'player' ],
        'team'     => [ 'table' => 'tt_teams',      'title' => [ 'name' ],                    'text' => [ 'age_group' ], 'demo' => 'team' ],
        'activity' => [ 'table' => 'tt_activities', 'title' => [ 'title' ],                   'text' => [ 'location' ],  'demo' => 'activity' ],
        'person'   => [ 'table' => 'tt_people',     'title' => [ 'first_name', 'last_name' ], 'text' => [],              'demo' => 'person' ],
    ];

    /** The most characters a folded column holds. */
    private const TITLE_MAX = 255;
    private const TEXT_MAX  = 500;

    /**
     * Fold a piece of text: '' stays '', anything else comes back with a
     * leading space.
     */
    public static function fold( string $text ): string {
        if ( $text === '' ) return '';
        if ( function_exists( 'remove_accents' ) ) $text = remove_accents( $text );
        $text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
        $text = (string) preg_replace( '/[^a-z0-9]+/', ' ', $text );
        $text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
        return $text === '' ? '' : ' ' . $text;
    }

    /**
     * The tokens of a query, folded, each once, in the order typed.
     *
     * @return list<string>
     */
    public static function tokens( string $query ): array {
        $folded = trim( self::fold( $query ) );
        if ( $folded === '' ) return [];
        return array_values( array_unique( explode( ' ', $folded ) ) );
    }

    /**
     * The folded title and text of one row, from its own source columns.
     *
     * @param array<string,mixed> $row
     * @return array{title:string, text:string}
     */
    public static function forRow( string $kind, array $row ): array {
        $spec = self::KINDS[ $kind ] ?? null;
        if ( $spec === null ) return [ 'title' => '', 'text' => '' ];

        $join = static function ( array $columns ) use ( $row ): string {
            $parts = [];
            foreach ( $columns as $column ) {
                $value = trim( (string) ( $row[ $column ] ?? '' ) );
                if ( $value !== '' ) $parts[] = $value;
            }
            return implode( ' ', $parts );
        };

        return [
            'title' => self::clip( self::fold( $join( $spec['title'] ) ), self::TITLE_MAX ),
            'text'  => self::clip( self::fold( $join( $spec['text'] ) ), self::TEXT_MAX ),
        ];
    }

    /**
     * Re-fold these rows of one kind, in the current club. A row whose
     * folded columns already match is not written. Safe to call with ids
     * that no longer exist.
     *
     * @param list<int>|int $ids
     * @return int rows written
     */
    public static function refresh( string $kind, $ids ): int {
        $spec = self::KINDS[ $kind ] ?? null;
        if ( $spec === null ) return 0;
        $ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ), static fn( int $id ): bool => $id > 0 ) ) );
        if ( $ids === [] || ! self::ready( $kind ) ) return 0;

        global $wpdb;
        $table   = $wpdb->prefix . $spec['table'];
        $columns = implode( ', ', array_merge( [ 'id', 'search_title', 'search_text', 'search_v' ], $spec['title'], $spec['text'] ) );
        $written = 0;
        foreach ( array_chunk( $ids, 200 ) as $chunk ) {
            $in   = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT {$columns} FROM {$table} WHERE club_id = %d AND id IN ({$in})",
                (int) \TT\Infrastructure\Tenancy\CurrentClub::id(),
                ...$chunk
            ), ARRAY_A );
            $written += self::writeRows( $kind, is_array( $rows ) ? $rows : [] );
        }
        return $written;
    }

    /**
     * Write the fold for rows already read (`id`, the folded columns and the
     * source columns). Used by {@see self::refresh()} and the sweep.
     *
     * @param array<mixed> $rows rows as `ARRAY_A`
     * @return int rows written
     */
    public static function writeRows( string $kind, array $rows ): int {
        $spec = self::KINDS[ $kind ] ?? null;
        if ( $spec === null ) return 0;

        global $wpdb;
        $table   = $wpdb->prefix . $spec['table'];
        $written = 0;
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $id = (int) ( $row['id'] ?? 0 );
            if ( $id <= 0 ) continue;
            $fold  = self::forRow( $kind, $row );
            $same  = isset( $row['search_v'] )
                  && (int) $row['search_v'] === self::VERSION
                  && (string) ( $row['search_title'] ?? '' ) === $fold['title']
                  && (string) ( $row['search_text'] ?? '' ) === $fold['text'];
            if ( $same ) continue;
            $ok = $wpdb->update(
                $table,
                [ 'search_title' => $fold['title'], 'search_text' => $fold['text'], 'search_v' => self::VERSION ],
                [ 'id' => $id ],
                [ '%s', '%s', '%d' ],
                [ '%d' ]
            );
            if ( $ok !== false ) $written++;
        }
        return $written;
    }

    /**
     * Do the folded columns exist on this kind's table? Before the migration
     * has run, nothing is written and the providers match the raw columns.
     */
    public static function ready( string $kind ): bool {
        static $ready = [];
        $spec = self::KINDS[ $kind ] ?? null;
        if ( $spec === null ) return false;
        if ( ! isset( $ready[ $kind ] ) ) {
            global $wpdb;
            $ready[ $kind ] = (bool) $wpdb->get_var( $wpdb->prepare(
                'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s LIMIT 1',
                $wpdb->prefix . $spec['table'],
                'search_v'
            ) );
        }
        return $ready[ $kind ];
    }

    /**
     * The `WHERE` part that every token must match: in the title or the
     * text, or through `$extra`, an extra per-token condition a provider
     * adds (an activity type name, say).
     *
     * A folded row is matched on its folded columns only, an unfolded one
     * on its raw columns only (lower-cased; accents are folded on the
     * sweep). The two never mix in one expression, so the folded columns —
     * ASCII with a binary collation — are compared fast and never against a
     * column of another collation.
     *
     * @param list<string>                                   $tokens folded tokens
     * @param array<string, array{sql:string, params:list<mixed>}> $extra token => condition
     * @return array{sql:string, params:list<mixed>}
     */
    public static function whereSql( string $kind, string $alias, array $tokens, array $extra = [] ): array {
        $parts  = [];
        $params = [];
        foreach ( $tokens as $token ) {
            $like  = '%' . $token . '%';
            $match = self::split( $kind, $alias, static fn( string $title, string $text ): array => [
                'sql'    => "( {$title} LIKE %s OR {$text} LIKE %s )",
                'params' => [ $like, $like ],
            ], false );
            $piece  = $match['sql'];
            $params = array_merge( $params, $match['params'] );
            if ( isset( $extra[ $token ] ) ) {
                $piece  = "( {$piece} OR {$extra[ $token ]['sql']} )";
                $params = array_merge( $params, $extra[ $token ]['params'] );
            }
            $parts[] = $piece;
        }
        return [ 'sql' => $parts === [] ? '1=1' : implode( ' AND ', $parts ), 'params' => $params ];
    }

    /**
     * A condition that any of these `LIKE` patterns matches the title or the
     * text. Used for patterns such as the age group.
     *
     * @param list<string> $likes
     * @return array{sql:string, params:list<mixed>}
     */
    public static function anySql( string $kind, string $alias, array $likes ): array {
        if ( $likes === [] ) return [ 'sql' => '1=0', 'params' => [] ];
        return self::split( $kind, $alias, static function ( string $title, string $text ) use ( $likes ): array {
            $parts  = [];
            $params = [];
            foreach ( $likes as $like ) {
                $parts[] = "{$title} LIKE %s OR {$text} LIKE %s";
                array_push( $params, $like, $like );
            }
            return [ 'sql' => '( ' . implode( ' OR ', $parts ) . ' )', 'params' => $params ];
        }, false );
    }

    /**
     * The score of a row, in SQL: 100 when the title is exactly the query,
     * then per token 60 at the start of a word in the title, 30 inside the
     * title, 12 in the secondary text (or `$extra`).
     *
     * @param list<string>                                   $tokens
     * @param array<string, array{sql:string, params:list<mixed>}> $extra
     * @return array{sql:string, params:list<mixed>}
     */
    public static function scoreSql( string $kind, string $alias, array $tokens, array $extra = [] ): array {
        if ( $tokens === [] ) return [ 'sql' => '0', 'params' => [] ];
        return self::split( $kind, $alias, static function ( string $title, string $text ) use ( $tokens, $extra ): array {
            $parts  = [ "( CASE WHEN {$title} = %s THEN 100 ELSE 0 END )" ];
            $params = [ ' ' . implode( ' ', $tokens ) ];
            foreach ( $tokens as $token ) {
                $secondary  = "{$text} LIKE %s";
                $sec_params = [ '%' . $token . '%' ];
                if ( isset( $extra[ $token ] ) ) {
                    $secondary  = "( {$secondary} OR {$extra[ $token ]['sql']} )";
                    $sec_params = array_merge( $sec_params, $extra[ $token ]['params'] );
                }
                $parts[]  = "( CASE WHEN {$title} LIKE %s THEN 60 WHEN {$title} LIKE %s THEN 30 WHEN {$secondary} THEN 12 ELSE 0 END )";
                $params[] = '% ' . $token . '%';
                $params[] = '%' . $token . '%';
                $params   = array_merge( $params, $sec_params );
            }
            return [ 'sql' => '( ' . implode( ' + ', $parts ) . ' )', 'params' => $params ];
        }, true );
    }

    /**
     * Build an expression over the folded columns for folded rows and over
     * the raw columns for rows not folded yet.
     *
     * @param callable(string, string): array{sql:string, params:list<mixed>} $build
     * @param bool $numeric a value (CASE) rather than a condition (OR)
     * @return array{sql:string, params:list<mixed>}
     */
    private static function split( string $kind, string $alias, callable $build, bool $numeric ): array {
        $raw = $build( self::rawSql( $alias, self::KINDS[ $kind ]['title'] ?? [] ), self::rawSql( $alias, self::KINDS[ $kind ]['text'] ?? [] ) );
        if ( ! self::ready( $kind ) ) return $raw;
        $folded = $build( "{$alias}.search_title", "{$alias}.search_text" );
        if ( $numeric ) {
            return [
                'sql'    => "( CASE WHEN {$alias}.search_v IS NULL THEN {$raw['sql']} ELSE {$folded['sql']} END )",
                'params' => array_merge( $raw['params'], $folded['params'] ),
            ];
        }
        return [
            'sql'    => "( ( {$alias}.search_v IS NOT NULL AND {$folded['sql']} ) OR ( {$alias}.search_v IS NULL AND {$raw['sql']} ) )",
            'params' => array_merge( $folded['params'], $raw['params'] ),
        ];
    }

    /**
     * The same score, in PHP, for kinds matched in memory (sections, help
     * topics, settings). Null when a token does not match at all.
     *
     * @param list<string> $tokens
     */
    public static function scoreText( string $title, string $text, array $tokens ): ?int {
        $title = self::fold( $title );
        $text  = self::fold( $text );
        $score = $tokens !== [] && $title === ' ' . implode( ' ', $tokens ) ? 100 : 0;
        foreach ( $tokens as $token ) {
            if ( strpos( $title, ' ' . $token ) !== false ) {
                $score += 60;
            } elseif ( strpos( $title, $token ) !== false ) {
                $score += 30;
            } elseif ( strpos( $text, $token ) !== false ) {
                $score += 12;
            } else {
                return null;
            }
        }
        return $score;
    }

    /**
     * The raw columns of a row not folded yet, lower-cased, with the same
     * leading space a folded column has.
     *
     * @param list<string> $source
     */
    private static function rawSql( string $alias, array $source ): string {
        if ( $source === [] ) return "''";
        return "CONCAT( ' ', LOWER( CONCAT_WS( ' ', " . implode( ', ', array_map( static fn( string $c ): string => "{$alias}.{$c}", $source ) ) . ' ) ) )';
    }

    private static function clip( string $text, int $max ): string {
        if ( strlen( $text ) <= $max ) return $text;
        $cut = substr( $text, 0, $max );
        $sp  = strrpos( $cut, ' ' );
        return $sp !== false && $sp > 0 ? substr( $cut, 0, $sp ) : $cut;
    }
}
