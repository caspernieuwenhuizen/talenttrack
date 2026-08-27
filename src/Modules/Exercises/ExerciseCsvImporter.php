<?php
namespace TT\Modules\Exercises;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ExerciseCsvImporter — CSV bulk import for the exercise library (#2613).
 *
 * Filling the library was a one-at-a-time job through the authoring form.
 * That matters more since #2497: the generator ranks drills by how many of
 * a team's open player goals each one would serve, so the library's
 * usefulness scales with its size *and* with how completely each row is
 * tagged. An academy typing 150 drills by hand stops at 20, and the
 * generator keeps proposing the same handful.
 *
 * Follows `Players\PlayerCsvImporter` — same three-method shape
 * (`parse` / `preview` / `commit`), same header handling, same
 * accept-what-worked commit semantics. A club that has done the player
 * import once already knows how this behaves.
 *
 * THE COLUMN THAT EARNS THE FEATURE
 *
 * `principle_codes`. Without principle links a drill is invisible to the
 * generator's coverage ranking — it can be picked, but never *preferred*.
 * An import that skips them produces a large library that behaves like an
 * empty one. So unknown codes are reported per row rather than dropped,
 * and the preview counts how many rows carry none.
 *
 * VALIDATION IS REJECT, NOT CLAMP
 *
 * `ExercisesRepository::sanitizePayload()` clamps out-of-range numbers,
 * which is right for a REST caller that should not be able to store an
 * intensity of 47. It is wrong for an import: a spreadsheet saying `10`
 * in a 1-7 column is a mistake somebody should be told about, not
 * silently rewritten to 7 across 150 rows. Every numeric column is
 * therefore range-checked *here*, before the repository ever sees it, and
 * an out-of-range value fails its row.
 */
class ExerciseCsvImporter {

    /**
     * Columns accepted in the header row, case-insensitive.
     *
     * `organisation` is accepted and folded into `description` — the
     * authoring form labels that one field "Description and organisation",
     * so a club exporting what it typed there has somewhere to put it.
     * There is no separate column on `tt_exercises`.
     */
    private const ACCEPTED_FIELDS = [
        'name', 'description', 'organisation',
        'duration_minutes', 'duration_minutes_min', 'duration_minutes_max',
        'intensity_band', 'players_min', 'players_max',
        'age_min', 'age_max',
        'tactical_theme', 'principle_codes', 'category', 'visibility',
        'code', 'pitch_preset', 'diagram_url',
    ];

    /**
     * Numeric columns and the range each accepts, mirroring the
     * repository's clamps so an accepted row is one the repository will
     * store unchanged. Read from the constants, never re-typed as
     * literals — #2767 was a 1-5 literal silently downgrading every band
     * 6-7 exercise.
     *
     * @return array<string, array{0:int,1:int}>
     */
    private static function ranges(): array {
        return [
            'intensity_band'       => [ ExercisesRepository::INTENSITY_BAND_MIN, ExercisesRepository::INTENSITY_BAND_MAX ],
            'duration_minutes'     => [ 0, 240 ],
            'duration_minutes_min' => [ 0, 240 ],
            'duration_minutes_max' => [ 0, 240 ],
            'players_min'          => [ 1, 40 ],
            'players_max'          => [ 1, 40 ],
            'age_min'              => [ 4, 21 ],
            'age_max'              => [ 4, 21 ],
        ];
    }

    /**
     * Parse the CSV at `$path` into structured rows.
     *
     * @return array{headers: list<string>, rows: list<array<string,string>>, header_warnings: list<string>}
     */
    public static function parse( string $path ): array {
        $handle = fopen( $path, 'r' );
        if ( ! $handle ) {
            return [
                'headers'         => [],
                'rows'            => [],
                'header_warnings' => [ __( 'Could not open the uploaded file.', 'talenttrack' ) ],
            ];
        }

        $raw_headers = fgetcsv( $handle );
        if ( ! $raw_headers ) {
            fclose( $handle );
            return [
                'headers'         => [],
                'rows'            => [],
                'header_warnings' => [ __( 'The CSV file is empty or has no header row.', 'talenttrack' ) ],
            ];
        }

        $headers = array_map( static function ( $h ) {
            $h = strtolower( trim( (string) $h ) );
            return (string) preg_replace( '/^\xEF\xBB\xBF/', '', $h );
        }, $raw_headers );

        $header_warnings = [];
        foreach ( $headers as $h ) {
            if ( $h === '' ) continue;
            if ( in_array( $h, self::ACCEPTED_FIELDS, true ) ) continue;

            // Named explicitly rather than lumped in with typos: there is
            // no per-exercise coaching-points column. Coaching points hang
            // off a training-plan block, so they belong to the plan that
            // uses the drill, not to the drill.
            if ( $h === 'coaching_points' ) {
                $header_warnings[] = __(
                    'Column "coaching_points" is ignored: coaching points belong to a training plan\'s block, not to the exercise itself. Put anything that describes the drill in "description".',
                    'talenttrack'
                );
                continue;
            }

            $header_warnings[] = sprintf(
                /* translators: %s: CSV column name. */
                __( 'Column "%s" is not recognized and will be ignored.', 'talenttrack' ),
                $h
            );
        }

        if ( ! in_array( 'name', $headers, true ) ) {
            $header_warnings[] = __( 'The CSV must include a name column.', 'talenttrack' );
        }

        $rows = [];
        while ( ( $line = fgetcsv( $handle ) ) !== false ) {
            // Excel-exported CSVs routinely carry trailing empty rows.
            $non_empty = array_filter( $line, static fn( $v ) => trim( (string) $v ) !== '' );
            if ( ! $non_empty ) continue;

            $assoc = [];
            foreach ( $headers as $idx => $col ) {
                if ( $col === '' || ! in_array( $col, self::ACCEPTED_FIELDS, true ) ) continue;
                $assoc[ $col ] = isset( $line[ $idx ] ) ? trim( (string) $line[ $idx ] ) : '';
            }
            $rows[] = $assoc;
        }
        fclose( $handle );

        return [ 'headers' => $headers, 'rows' => $rows, 'header_warnings' => $header_warnings ];
    }

    /**
     * Dry run. Validates every row and reports what a commit would do,
     * without writing anything.
     *
     * Every row is validated, not just the previewed ones — a club needs
     * to know row 143 is broken before it starts, not after 142 rows are
     * already in. `$limit` bounds what is rendered, not what is checked.
     *
     * @return array{
     *   header_warnings: list<string>,
     *   total: int,
     *   valid: int,
     *   errored: int,
     *   without_principles: int,
     *   preview: list<array{row_number:int, data: array<string,string>, status: string, messages: list<string>}>
     * }
     */
    public static function preview( string $path, int $limit = 20 ): array {
        $parsed = self::parse( $path );

        $preview            = [];
        $valid              = 0;
        $errored            = 0;
        $without_principles = 0;

        $categories = self::categoriesBySlugAndLabel();
        $principles = self::principlesByCode();

        foreach ( $parsed['rows'] as $index => $row ) {
            $row_number = $index + 2; // +1 for the header, +1 for 1-based.
            $messages   = self::validate( $row, $categories, $principles );

            $has_error = $messages !== [];
            if ( $has_error ) {
                $errored++;
            } else {
                $valid++;
            }

            if ( trim( (string) ( $row['principle_codes'] ?? '' ) ) === '' ) {
                $without_principles++;
            }

            if ( count( $preview ) < $limit ) {
                $preview[] = [
                    'row_number' => $row_number,
                    'data'       => $row,
                    'status'     => $has_error ? 'error' : 'valid',
                    'messages'   => $messages,
                ];
            }
        }

        return [
            'header_warnings'    => $parsed['header_warnings'],
            'total'              => count( $parsed['rows'] ),
            'valid'              => $valid,
            'errored'            => $errored,
            'without_principles' => $without_principles,
            'preview'            => $preview,
        ];
    }

    /**
     * Write the valid rows.
     *
     * Accept-what-worked, like the player importer: a bad row at 47 does
     * not roll back 1-46 or stop 48+. It is reported instead. Rows that
     * failed validation in the preview are not attempted at all, so a
     * commit never half-writes a row it already knew was wrong.
     *
     * @return array{
     *   created: int,
     *   errored: int,
     *   errors: list<array{row_number:int, name: string, messages: list<string>}>,
     *   error_csv: string
     * }
     */
    public static function commit( string $path ): array {
        $parsed = self::parse( $path );

        $categories = self::categoriesBySlugAndLabel();
        $principles = self::principlesByCode();
        $repo       = new ExercisesRepository();

        $created = 0;
        $errors  = [];

        foreach ( $parsed['rows'] as $index => $row ) {
            $row_number = $index + 2;
            $messages   = self::validate( $row, $categories, $principles );

            if ( $messages === [] ) {
                $payload = self::toPayload( $row, $categories );
                $id      = $repo->create( $payload );

                if ( $id > 0 ) {
                    self::linkPrinciples( $id, (string) ( $row['principle_codes'] ?? '' ), $principles );
                    $created++;
                    continue;
                }

                $messages = [ __( 'The exercise could not be saved.', 'talenttrack' ) ];
            }

            $errors[] = [
                'row_number' => $row_number,
                'name'       => (string) ( $row['name'] ?? '' ),
                'messages'   => $messages,
                'data'       => $row,
            ];
        }

        return [
            'created'   => $created,
            'errored'   => count( $errors ),
            'errors'    => $errors,
            'error_csv' => self::errorCsv( $errors ),
        ];
    }

    /**
     * The failed rows, back in the shape they arrived in, plus a column
     * saying what was wrong.
     *
     * Handing back a correctable file matters more here than for players:
     * a 150-drill import that rejects nine rows for out-of-range bands is
     * a five-minute fix in a spreadsheet, and a list of row numbers in a
     * browser is not.
     *
     * @param list<array{row_number:int, name: string, messages: list<string>, data?: array<string,string>}> $errors
     */
    private static function errorCsv( array $errors ): string {
        if ( $errors === [] ) return '';

        $columns = self::ACCEPTED_FIELDS;
        $handle  = fopen( 'php://temp', 'r+' );
        if ( ! $handle ) return '';

        fputcsv( $handle, array_merge( $columns, [ 'import_error' ] ) );

        foreach ( $errors as $error ) {
            $data = $error['data'] ?? [];
            $line = [];
            foreach ( $columns as $column ) {
                $line[] = (string) ( $data[ $column ] ?? '' );
            }
            $line[] = implode( ' ', $error['messages'] );
            fputcsv( $handle, $line );
        }

        rewind( $handle );
        $csv = (string) stream_get_contents( $handle );
        fclose( $handle );

        return $csv;
    }

    /**
     * Everything wrong with one row, as reader-facing sentences.
     *
     * @param array<string,string>   $row
     * @param array<string,int>      $categories
     * @param array<string,int>      $principles
     * @return list<string>
     */
    private static function validate( array $row, array $categories, array $principles ): array {
        $messages = [];

        if ( trim( (string) ( $row['name'] ?? '' ) ) === '' ) {
            $messages[] = __( 'name is required.', 'talenttrack' );
        }

        foreach ( self::ranges() as $key => [ $low, $high ] ) {
            $raw = trim( (string) ( $row[ $key ] ?? '' ) );
            if ( $raw === '' ) continue;

            if ( ! preg_match( '/^-?\d+$/', $raw ) ) {
                $messages[] = sprintf(
                    /* translators: 1: CSV column name, 2: the value found. */
                    __( '%1$s must be a whole number, found "%2$s".', 'talenttrack' ),
                    $key,
                    $raw
                );
                continue;
            }

            $value = (int) $raw;
            if ( $value < $low || $value > $high ) {
                // Rejected, not clamped. A spreadsheet saying 10 in a 1-7
                // column is a mistake to report, not one to quietly rewrite
                // 150 times.
                $messages[] = sprintf(
                    /* translators: 1: CSV column name, 2: the value found, 3: lowest allowed, 4: highest allowed. */
                    __( '%1$s is %2$d, outside the allowed range %3$d-%4$d.', 'talenttrack' ),
                    $key,
                    $value,
                    $low,
                    $high
                );
            }
        }

        foreach ( [ [ 'players_min', 'players_max' ], [ 'age_min', 'age_max' ], [ 'duration_minutes_min', 'duration_minutes_max' ] ] as [ $min_key, $max_key ] ) {
            $min = trim( (string) ( $row[ $min_key ] ?? '' ) );
            $max = trim( (string) ( $row[ $max_key ] ?? '' ) );
            if ( $min === '' || $max === '' ) continue;
            if ( ! preg_match( '/^\d+$/', $min ) || ! preg_match( '/^\d+$/', $max ) ) continue;
            if ( (int) $min > (int) $max ) {
                $messages[] = sprintf(
                    /* translators: 1: the minimum column name, 2: the maximum column name. */
                    __( '%1$s is greater than %2$s.', 'talenttrack' ),
                    $min_key,
                    $max_key
                );
            }
        }

        $category = trim( (string) ( $row['category'] ?? '' ) );
        if ( $category !== '' && ! isset( $categories[ self::key( $category ) ] ) ) {
            $messages[] = sprintf(
                /* translators: %s: the category named in the CSV. */
                __( 'category "%s" does not exist. Create it in the exercise library first, or leave the column empty.', 'talenttrack' ),
                $category
            );
        }

        $visibility = strtolower( trim( (string) ( $row['visibility'] ?? '' ) ) );
        if ( $visibility !== '' ) {
            if ( ! in_array( $visibility, [ 'club', 'team', 'private' ], true ) ) {
                $messages[] = sprintf(
                    /* translators: %s: the visibility value found in the CSV. */
                    __( 'visibility "%s" is not one of club, team, private.', 'talenttrack' ),
                    $visibility
                );
            } elseif ( $visibility === 'club' && ! current_user_can( 'tt_edit_methodology' ) ) {
                // The same gate the authoring form applies. An import must
                // not be a way around a permission the form enforces.
                $messages[] = __( 'visibility "club" needs the methodology-editing permission, which you do not have.', 'talenttrack' );
            }
        }

        // Unknown principle codes are reported per row, never dropped: a
        // drill whose principle link silently vanished is one the
        // generator can pick but never prefer, which looks like the
        // feature not working rather than like an import problem.
        foreach ( self::splitCodes( (string) ( $row['principle_codes'] ?? '' ) ) as $code ) {
            if ( ! isset( $principles[ self::key( $code ) ] ) ) {
                $messages[] = sprintf(
                    /* translators: %s: the principle code found in the CSV. */
                    __( 'principle code "%s" does not match any principle.', 'talenttrack' ),
                    $code
                );
            }
        }

        return $messages;
    }

    /**
     * @param array<string,string> $row
     * @param array<string,int>    $categories
     * @return array<string,mixed>
     */
    private static function toPayload( array $row, array $categories ): array {
        $description = trim( (string) ( $row['description'] ?? '' ) );
        $organisation = trim( (string) ( $row['organisation'] ?? '' ) );

        if ( $organisation !== '' ) {
            $description = $description === ''
                ? $organisation
                : $description . "\n\n" . $organisation;
        }

        $payload = [
            'name'        => trim( (string) ( $row['name'] ?? '' ) ),
            'description' => $description === '' ? null : $description,
            // Deliberately narrower than the repository's default of
            // 'club'. A bulk import is exactly where an accidental
            // academy-wide publish is easiest to do and hardest to
            // notice, so an unspecified row lands team-scoped and is
            // promoted deliberately afterwards.
            'visibility'  => strtolower( trim( (string) ( $row['visibility'] ?? '' ) ) ) ?: 'team',
        ];

        $category = trim( (string) ( $row['category'] ?? '' ) );
        if ( $category !== '' ) {
            $payload['category_id'] = $categories[ self::key( $category ) ];
        }

        foreach ( array_keys( self::ranges() ) as $key ) {
            $raw = trim( (string) ( $row[ $key ] ?? '' ) );
            if ( $raw === '' ) continue;
            $payload[ $key ] = (int) $raw;
        }

        foreach ( [ 'code', 'tactical_theme', 'pitch_preset', 'diagram_url' ] as $key ) {
            $raw = trim( (string) ( $row[ $key ] ?? '' ) );
            if ( $raw === '' ) continue;
            $payload[ $key ] = $raw;
        }

        return $payload;
    }

    /**
     * @param array<string,int> $principles
     */
    private static function linkPrinciples( int $exercise_id, string $codes, array $principles ): void {
        global $wpdb;

        foreach ( self::splitCodes( $codes ) as $code ) {
            $principle_id = $principles[ self::key( $code ) ] ?? 0;
            if ( $principle_id <= 0 ) continue;

            // The unique key on (club_id, exercise_id, principle_id) makes
            // a repeated code in one cell harmless rather than a duplicate.
            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->prefix}tt_exercise_principles
                    (club_id, exercise_id, principle_id, created_at)
                 VALUES (%d, %d, %d, %s)",
                CurrentClub::id(),
                $exercise_id,
                $principle_id,
                current_time( 'mysql' )
            ) );
        }
    }

    /**
     * Semicolon-separated, per the issue. Commas are also accepted
     * because a spreadsheet user will reach for one and the codes
     * themselves never contain either.
     *
     * @return list<string>
     */
    private static function splitCodes( string $raw ): array {
        $parts = preg_split( '/[;,]/', $raw ) ?: [];
        $out   = [];
        foreach ( $parts as $part ) {
            $part = trim( (string) $part );
            if ( $part !== '' ) $out[] = $part;
        }
        return array_values( array_unique( $out ) );
    }

    /** Case- and whitespace-insensitive matching key for a lookup value. */
    private static function key( string $value ): string {
        return strtolower( trim( $value ) );
    }

    /**
     * Category ids keyed by both slug and label, so a club can write
     * either "Passing" or "passing" in the column.
     *
     * @return array<string,int>
     */
    private static function categoriesBySlugAndLabel(): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, slug, label FROM {$wpdb->prefix}tt_exercise_categories WHERE club_id = %d",
            CurrentClub::id()
        ) );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $out[ self::key( (string) $row->slug ) ]  = (int) $row->id;
            $out[ self::key( (string) $row->label ) ] = (int) $row->id;
        }
        return $out;
    }

    /**
     * Principle ids keyed by code, within this club's active methodology.
     *
     * Scoping to one methodology is not optional. #2200 gave
     * `tt_principles` a `methodology_id`, and a cloned set carries the
     * same codes as the set it came from — so `WHERE code = 'AO-01'`
     * across a club with two methodologies matches twice, and whichever
     * row the database happened to return first would decide what the
     * generator later reasons about. Resolving against the install's
     * active set makes the answer the same one the rest of the product
     * gives.
     *
     * @return array<string,int>
     */
    private static function principlesByCode(): array {
        global $wpdb;

        $methodology_id = \TT\Modules\Methodology\ActiveMethodologyResolver::forInstall();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, code
               FROM {$wpdb->prefix}tt_principles
              WHERE club_id = %d
                AND methodology_id = %d",
            CurrentClub::id(),
            $methodology_id
        ) );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $out[ self::key( (string) $row->code ) ] = (int) $row->id;
        }
        return $out;
    }
}
