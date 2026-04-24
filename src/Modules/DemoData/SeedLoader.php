<?php
namespace TT\Modules\DemoData;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SeedLoader — loads Dutch name / age-group / opponent / result lists
 * from src/Modules/DemoData/seeds/*.txt. Kept deliberately simple: one
 * entry per non-empty line, comments (lines starting with #) ignored.
 *
 * Files are plain text so translators or the user can tweak them
 * without touching PHP.
 */
class SeedLoader {

    private static ?string $dir = null;

    private static function dir(): string {
        if ( self::$dir === null ) {
            self::$dir = __DIR__ . '/seeds/';
        }
        return self::$dir;
    }

    /**
     * @return string[]
     */
    public static function load( string $filename ): array {
        $path = self::dir() . $filename;
        if ( ! is_readable( $path ) ) {
            return [];
        }
        $raw = (string) file_get_contents( $path );
        $lines = preg_split( '/\r\n|\r|\n/', $raw ) ?: [];
        $out = [];
        foreach ( $lines as $line ) {
            $line = trim( (string) $line );
            if ( $line === '' || $line[0] === '#' ) continue;
            $out[] = $line;
        }
        return $out;
    }

    /** @return string[] */
    public static function firstNames(): array { return self::load( 'first_names_nl.txt' ); }

    /** @return string[] */
    public static function lastNames(): array { return self::load( 'last_names_nl.txt' ); }

    /** @return string[] */
    public static function ageGroups(): array { return self::load( 'team_age_groups.txt' ); }

    /** @return string[] */
    public static function opponents(): array { return self::load( 'opponents.txt' ); }

    /** @return string[] */
    public static function matchResults(): array { return self::load( 'match_results.txt' ); }
}
