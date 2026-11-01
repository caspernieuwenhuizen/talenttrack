<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Activities\CoachWeek\WeekContext;

/**
 * #4496 — players on the week's teams with a birthday in the window, as a
 * first name and the day.
 *
 * Only players the viewer may open are listed. The others are not even
 * counted: "1 birthday" would place a hidden child's birthday in this week.
 *
 * The date of birth is read on the server and never returned: an item
 * carries the day of the birthday inside the window, never the year of
 * birth and never an age. A child born on 29 February has their birthday
 * on 28 February in a year without one.
 */
final class BirthdaysBlock extends AttentionBlock {

    public function key(): string { return 'birthdays'; }
    public function label(): string { return __( 'Birthdays', 'talenttrack' ); }
    public function help(): string { return __( 'Players with a birthday this week: first name and day only, never the age.', 'talenttrack' ); }

    protected function field(): string { return 'attention_birthdays'; }

    /** A count would place a hidden child's birthday in this week. */
    protected function countsOthers(): bool { return false; }

    protected function items( WeekContext $week ): array {
        // month-day => the window's date it falls on.
        $window = [];
        $day    = $week->start;
        for ( $i = 0; $i < 31 && $day <= $week->end; $i++ ) {
            $window[ substr( $day, 5 ) ] = $day;
            $day = gmdate( 'Y-m-d', (int) strtotime( $day . ' 12:00:00 UTC +1 day' ) );
        }
        // 29 February falls on 28 February in a year without it.
        if ( isset( $window['02-28'] ) && ! isset( $window['02-29'] ) ) {
            $year = (int) substr( $window['02-28'], 0, 4 );
            if ( ! checkdate( 2, 29, $year ) ) $window['02-29'] = $window['02-28'];
        }

        $out = [];
        foreach ( self::players( $week ) as $pid => $player ) {
            $dob = $player['date_of_birth'];
            if ( preg_match( '/^\d{4}-(\d{2}-\d{2})/', $dob, $m ) !== 1 ) continue;
            if ( ! isset( $window[ $m[1] ] ) ) continue;
            $out[] = [
                'player_id'  => $pid,
                'first_name' => $player['first_name'],
                'date'       => $window[ $m[1] ],
            ];
        }
        usort( $out, static fn( array $a, array $b ): int => [ $a['date'], $a['first_name'] ] <=> [ $b['date'], $b['first_name'] ] );
        return $out;
    }
}
