<?php
namespace TT\Tests\Php\Support;

use PHPUnit\Framework\Assert;

/**
 * GoldenSnapshot (#4958) — stored "before" payloads for the golden report
 * tests, with the ids a fixture made replaced by stable tokens.
 *
 * A report payload carries auto-increment ids (team, player, activity,
 * evaluation, …), which differ between runs and between CI and a local
 * database. So before a payload is stored or compared, every id the fixture
 * registered (`id( 'player', $id )`) becomes `{player:1}`, `{player:2}`, …,
 * numbered in the order the fixture made them; a date within a year of today
 * becomes `{day:-30}`. Only registered ids are replaced, and only where an id
 * sits: an `id` or `…_id` field, an id-keyed map, or an id in a URL
 * parameter. A jersey number that happens to equal a player id stays a
 * jersey number.
 *
 * An id field that still holds an unregistered id after tokenising fails the
 * test, naming the path: a snapshot that kept a raw id would pass here and
 * fail on the next database.
 *
 * Snapshots live in `tests/php/fixtures/golden/snapshots/`: arrays as
 * pretty-printed JSON, strings (rendered HTML) as `.html`, so a changed
 * figure fails with a readable line diff. `TT_GOLDEN_RECORD=1` writes them;
 * without it, a missing snapshot fails. A deliberate figure change records
 * again in the same PR, and the snapshot diff is the change under review.
 */
final class GoldenSnapshot {

    /** Field names whose id is of another kind than their prefix says. */
    private const ALIASES = [
        'author'     => 'user',
        'coach'      => 'user',
        'assessor'   => 'user',
        'owner'      => 'user',
        'owner_coach'=> 'user',
        'created_by' => 'user',
        'updated_by' => 'user',
        'rated_by'   => 'user',
        'set_by'     => 'user',
        'file'       => 'pdp_file',
        'pdp_file'   => 'pdp_file',
        'thread'     => 'player',
        'parent'     => 'category',
        'source_entity' => 'event_source',
    ];

    /**
     * Names under which an `id` is of a known kind: the nearest one above an
     * `id` decides, through any lists in between (`goals.items.0.id` is a
     * goal).
     */
    private const COLLECTIONS = [
        'evaluations'    => 'evaluation',
        'goals'          => 'goal',
        'injuries'       => 'injury',
        'conversations'  => 'conversation',
        'notes'          => 'thread_message',
        'thread_notes'   => 'thread_message',
        'events'         => 'event',
        'journey'        => 'event',
        'recent_journey' => 'event',
        'players'        => 'player',
        'fixtures'       => 'activity',
        'activities'     => 'activity',
        'file'           => 'pdp_file',
        'behaviour'      => 'behaviour',
        'potential'      => 'potential',
        'tests'          => 'definition',
    ];

    /** @var array<string, array<int,int>> kind => id => its number */
    private static array $ids = [];

    /** Start a fixture: forget every id registered before. */
    public static function reset(): void {
        self::$ids = [];
    }

    /** Register an id the fixture made, of a kind. Returns the id. */
    public static function id( string $kind, int $id ): int {
        if ( $id <= 0 ) return $id;
        if ( ! isset( self::$ids[ $kind ][ $id ] ) ) {
            self::$ids[ $kind ][ $id ] = count( self::$ids[ $kind ] ?? [] ) + 1;
        }
        return $id;
    }

    public static function recording(): bool {
        return (string) getenv( 'TT_GOLDEN_RECORD' ) === '1';
    }

    /**
     * Compare a payload with its stored snapshot, or store it when recording.
     *
     * @param array<mixed>|string $payload
     */
    public static function assertMatches( string $name, $payload, string $message = '' ): void {
        $path = self::path( $name, is_string( $payload ) );
        $text = self::serialise( $payload );

        if ( self::recording() ) {
            if ( ! is_dir( dirname( $path ) ) ) mkdir( dirname( $path ), 0777, true );
            file_put_contents( $path, $text );
            Assert::assertFileExists( $path );
            return;
        }

        self::compare( $name, $payload, $message );
    }

    /**
     * Compare with the stored snapshot, whether or not recording.
     *
     * @param array<mixed>|string $payload
     */
    public static function compare( string $name, $payload, string $message = '' ): void {
        $path = self::path( $name, is_string( $payload ) );
        Assert::assertFileExists( $path, "No snapshot {$name}. Record it with TT_GOLDEN_RECORD=1 and commit it." );
        Assert::assertSame( (string) file_get_contents( $path ), self::serialise( $payload ), $message !== '' ? $message : "snapshot {$name}" );
    }

    /**
     * The payload as it is stored: tokenised, then JSON or the string.
     *
     * @param array<mixed>|string $payload
     */
    public static function serialise( $payload ): string {
        if ( is_string( $payload ) ) {
            // A rendered document prints when it was made: level it, as a
            // test levels the payload's own clock fields.
            $payload = (string) preg_replace( '/Generated [^<]*/', 'Generated {now}', $payload );
            // One tag per line, so a changed figure in rendered HTML shows
            // as a changed line rather than as one line of the whole page.
            return (string) preg_replace( '/>\s*</', ">\n<", self::tokeniseString( $payload ) ) . "\n";
        }
        // Rows a repository returns as objects become arrays first, so they
        // are tokenised (and guarded) like everything else.
        $plain = json_decode( (string) wp_json_encode( $payload ), true );
        Assert::assertIsArray( $plain, 'The payload must encode.' );
        $tokenised = self::tokenise( $plain, '', '' );
        $json      = wp_json_encode( $tokenised, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        Assert::assertIsString( $json, 'The payload must encode.' );
        return $json . "\n";
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function tokenise( $value, string $key, string $path, string $collection = '' ) {
        if ( is_array( $value ) ) {
            $is_list = array_is_list( $value );
            $out     = [];
            foreach ( $value as $k => $v ) {
                $here = $path === '' ? (string) $k : $path . '.' . $k;
                // An id-keyed map: the key is an id of the kind its values are.
                $new_key = $k;
                if ( ! $is_list && is_int( $k ) ) {
                    $token = self::tokenFor( $k, self::kindOfKey( $key ), $here, false );
                    if ( $token !== null ) $new_key = $token;
                }
                $inner           = is_string( $k ) && isset( self::COLLECTIONS[ $k ] ) ? $k : $collection;
                $out[ $new_key ] = self::tokenise( $v, is_string( $k ) ? $k : $key, $here, $inner );
            }
            return $out;
        }

        // An id field first: a repository row carries its ids as strings.
        if ( ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) && self::isIdKey( $key ) ) {
            $kind  = $key === 'id' ? ( self::COLLECTIONS[ $collection ] ?? null ) : self::kindOfField( $key );
            $token = self::tokenFor( (int) $value, $kind, $path, true );
            return $token ?? $value;
        }
        if ( is_string( $value ) ) return self::tokeniseString( $value );
        return $value;
    }

    /**
     * The token for an id, or null when it is not one the fixture made.
     *
     * @param bool $strict an id field: an unregistered id fails the test
     */
    private static function tokenFor( int $id, ?string $kind, string $path, bool $strict ): ?string {
        if ( $id <= 0 ) return null;
        if ( $kind !== null && isset( self::$ids[ $kind ][ $id ] ) ) {
            return '{' . $kind . ':' . self::$ids[ $kind ][ $id ] . '}';
        }

        $kinds = [];
        foreach ( self::$ids as $k => $ids ) {
            if ( isset( $ids[ $id ] ) ) $kinds[] = $k;
        }
        if ( count( $kinds ) === 1 && ( $kind === null || ! isset( self::$ids[ $kind ] ) ) ) {
            return '{' . $kinds[0] . ':' . self::$ids[ $kinds[0] ][ $id ] . '}';
        }
        if ( $strict ) {
            Assert::fail( $kinds === []
                ? "Unregistered id {$id} at {$path} (kind " . ( $kind ?? '?' ) . '): register it in the fixture with GoldenSnapshot::id().'
                : "Ambiguous id {$id} at {$path}: it is a " . implode( ' and a ', $kinds ) . '. Name the kind in GoldenSnapshot::ALIASES or COLLECTIONS.' );
        }
        return null;
    }

    /** Ids in a URL parameter (`player_id=12`, `id=12`), and dates near today. */
    private static function tokeniseString( string $value ): string {
        $value = (string) preg_replace_callback(
            '/([?&;](?:amp;)?|&#0?38;)([a-z_]*id)=(\d+)/',
            static function ( array $m ): string {
                $kind  = $m[2] === 'id' ? null : self::kindOfField( $m[2] );
                $token = self::tokenFor( (int) $m[3], $kind, 'url ' . $m[2], false );
                return $m[1] . $m[2] . '=' . ( $token ?? $m[3] );
            },
            $value
        );

        // A date near today is relative to the run, and a time on it is the
        // moment the fixture ran: neither belongs in a snapshot.
        $today = strtotime( gmdate( 'Y-m-d' ) . ' UTC' );
        return (string) preg_replace_callback(
            '/\b(\d{4})-(\d{2})-(\d{2})((?:[ T]\d{2}:\d{2}(?::\d{2})?)?)\b/',
            static function ( array $m ) use ( $today ): string {
                $date = $m[1] . '-' . $m[2] . '-' . $m[3];
                $ts   = strtotime( $date . ' UTC' );
                if ( $ts === false || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) return $m[0];
                $days = (int) round( ( $ts - $today ) / DAY_IN_SECONDS );
                if ( abs( $days ) > 366 ) return $m[0];
                // A date with a time within a day of now is the moment the
                // fixture wrote the row, in UTC or in site time: `{now}`,
                // whichever side of midnight either clock is on.
                if ( $m[4] !== '' && abs( $days ) <= 1 ) return '{now}';
                return '{day:' . ( $days >= 0 ? '+' : '' ) . $days . '}' . ( $m[4] !== '' ? ' {time}' : '' );
            },
            $value
        );
    }

    /** Id fields that are not a record the fixture made: the tenant. */
    private const NOT_FIXTURE_IDS = [ 'club_id' ];

    private static function isIdKey( string $key ): bool {
        if ( in_array( $key, self::NOT_FIXTURE_IDS, true ) ) return false;
        return $key === 'id' || str_ends_with( $key, '_id' ) || isset( self::ALIASES[ $key ] );
    }

    /** The kind an id field names: `player_id` a player, `author_id` a user. */
    private static function kindOfField( string $key ): ?string {
        $stem = str_ends_with( $key, '_id' ) ? substr( $key, 0, -3 ) : $key;
        return self::ALIASES[ $stem ] ?? self::ALIASES[ $key ] ?? $stem;
    }

    /** The kind of an id-keyed map, from its name: `players` a player. */
    private static function kindOfKey( string $key ): ?string {
        if ( isset( self::COLLECTIONS[ $key ] ) ) return self::COLLECTIONS[ $key ];
        return null;
    }

    private static function path( string $name, bool $is_string ): string {
        return dirname( __DIR__ ) . '/fixtures/golden/snapshots/' . $name . ( $is_string ? '.html' : '.json' );
    }
}
