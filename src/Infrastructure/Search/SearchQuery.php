<?php
namespace TT\Infrastructure\Search;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SearchQuery (#4671) — what was typed, read once: the folded text tokens,
 * the patterns recognised in it, and the filters that came with it.
 *
 * The patterns:
 *
 *   - `#<n>`          a shirt number (players);
 *   - `O<nn>`/`MO<nn>` an age group (teams and players); `MO` is the girls'
 *                     group only, `O` every group of that age;
 *   - `dd-mm`         a date in the current season (activities);
 *   - a weekday, in Dutch or English: the last and the next such day
 *     (activities).
 *
 * A word that is a pattern is not also a text token, and a kind that does
 * not honour a pattern in the query has nothing to match for it.
 */
final class SearchQuery {

    public const PATTERN_SHIRT     = 'shirt_number';
    public const PATTERN_AGE_GROUP = 'age_group';
    public const PATTERN_DATE      = 'date';
    public const PATTERN_WEEKDAY   = 'weekday';

    /** Weekday names, Dutch and English, to ISO-8601 day numbers (1 = Monday). */
    private const WEEKDAYS = [
        'maandag' => 1, 'dinsdag' => 2, 'woensdag' => 3, 'donderdag' => 4, 'vrijdag' => 5, 'zaterdag' => 6, 'zondag' => 7,
        'monday'  => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7,
    ];

    /** @var list<string> folded text tokens, patterns taken out */
    public array $tokens = [];

    public ?int $shirt = null;

    /** @var array{prefix:string, age:int}|null */
    public ?array $age_group = null;

    /** @var list<string> `Y-m-d` days an activity pattern names */
    public array $dates = [];

    /** @var list<array{pattern:string, value:string, label:string}> */
    public array $understood = [];

    public ?int $team_id = null;
    public ?string $from = null;
    public ?string $to = null;

    /** The length of what was typed, for the per-kind minimum. */
    public int $length = 0;

    public function __construct( public string $raw ) {
        $this->raw    = trim( $raw );
        $this->length = function_exists( 'mb_strlen' ) ? mb_strlen( $this->raw ) : strlen( $this->raw );
        $this->parse();
    }

    /**
     * The patterns in this query, by name.
     *
     * @return list<string>
     */
    public function patterns(): array {
        return array_values( array_unique( array_column( $this->understood, 'pattern' ) ) );
    }

    /** Is there anything to match: a text token or a pattern? */
    public function isEmpty(): bool {
        return $this->tokens === [] && $this->understood === [];
    }

    private function parse(): void {
        $today = new \DateTimeImmutable( current_time( 'Y-m-d' ), wp_timezone() );
        $text  = [];
        foreach ( preg_split( '/\s+/u', $this->raw ) ?: [] as $word ) {
            if ( $word === '' ) continue;

            if ( $this->shirt === null && preg_match( '/^#(\d{1,3})$/', $word, $m ) ) {
                $this->shirt        = (int) $m[1];
                $this->understood[] = [
                    'pattern' => self::PATTERN_SHIRT,
                    'value'   => (string) $this->shirt,
                    /* translators: %d: a shirt number */
                    'label'   => sprintf( __( 'Shirt number %d', 'talenttrack' ), $this->shirt ),
                ];
                continue;
            }

            if ( $this->age_group === null && preg_match( '/^(mo|o)(\d{1,2})$/i', $word, $m ) ) {
                $this->age_group    = [ 'prefix' => strtolower( $m[1] ), 'age' => (int) $m[2] ];
                $this->understood[] = [
                    'pattern' => self::PATTERN_AGE_GROUP,
                    'value'   => strtoupper( $m[1] ) . (int) $m[2],
                    /* translators: %s: an age group such as O14 */
                    'label'   => sprintf( __( 'Age group %s', 'talenttrack' ), strtoupper( $m[1] ) . (int) $m[2] ),
                ];
                continue;
            }

            if ( $this->dates === [] && preg_match( '/^(\d{1,2})-(\d{1,2})$/', $word, $m ) ) {
                $date = self::seasonDate( (int) $m[1], (int) $m[2], $today );
                if ( $date !== null ) {
                    $this->dates        = [ $date ];
                    $this->understood[] = [
                        'pattern' => self::PATTERN_DATE,
                        'value'   => $date,
                        /* translators: %s: a date */
                        'label'   => sprintf( __( 'On %s', 'talenttrack' ), \TT\Shared\Dates\TTDate::date( $date ) ),
                    ];
                    continue;
                }
            }

            $folded = trim( SearchText::fold( $word ) );
            if ( $this->dates === [] && isset( self::WEEKDAYS[ $folded ] ) ) {
                $this->dates        = self::weekdayDates( self::WEEKDAYS[ $folded ], $today );
                $this->understood[] = [
                    'pattern' => self::PATTERN_WEEKDAY,
                    'value'   => implode( ',', $this->dates ),
                    /* translators: 1: the last such day, 2: the next such day */
                    'label'   => sprintf( __( 'Last %1$s and next %2$s', 'talenttrack' ), \TT\Shared\Dates\TTDate::date( $this->dates[0] ), \TT\Shared\Dates\TTDate::date( $this->dates[1] ) ),
                ];
                continue;
            }

            $text[] = $word;
        }
        $this->tokens = SearchText::tokens( implode( ' ', $text ) );
    }

    /**
     * The `Y-m-d` of day/month inside the current season: the academy's
     * current season when it has one, otherwise July to June around today.
     */
    private static function seasonDate( int $day, int $month, \DateTimeImmutable $today ): ?string {
        if ( $month < 1 || $month > 12 || $day < 1 || $day > 31 ) return null;

        $start = '';
        $end   = '';
        if ( class_exists( '\\TT\\Modules\\Pdp\\Repositories\\SeasonsRepository' ) ) {
            $season = (array) ( ( new \TT\Modules\Pdp\Repositories\SeasonsRepository() )->current() ?? [] );
            $start  = (string) ( $season['start_date'] ?? '' );
            $end    = (string) ( $season['end_date'] ?? '' );
        }
        if ( $start === '' || $end === '' ) {
            $year  = (int) $today->format( 'n' ) >= 7 ? (int) $today->format( 'Y' ) : (int) $today->format( 'Y' ) - 1;
            $start = sprintf( '%04d-07-01', $year );
            $end   = sprintf( '%04d-06-30', $year + 1 );
        }

        foreach ( [ (int) substr( $start, 0, 4 ), (int) substr( $end, 0, 4 ) ] as $year ) {
            if ( ! checkdate( $month, $day, $year ) ) continue;
            $candidate = sprintf( '%04d-%02d-%02d', $year, $month, $day );
            if ( $candidate >= substr( $start, 0, 10 ) && $candidate <= substr( $end, 0, 10 ) ) return $candidate;
        }
        return null;
    }

    /**
     * The last such day (today included) and the next one after today.
     *
     * @return list<string>
     */
    private static function weekdayDates( int $iso, \DateTimeImmutable $today ): array {
        $now  = (int) $today->format( 'N' );
        $back = ( $now - $iso + 7 ) % 7;
        $fwd  = ( $iso - $now + 7 ) % 7;
        if ( $fwd === 0 ) $fwd = 7;
        return [
            $today->modify( "-{$back} days" )->format( 'Y-m-d' ),
            $today->modify( "+{$fwd} days" )->format( 'Y-m-d' ),
        ];
    }
}
