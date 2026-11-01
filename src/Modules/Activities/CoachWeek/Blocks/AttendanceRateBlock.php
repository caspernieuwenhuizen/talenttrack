<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\ActivityTypeKey;
use TT\Modules\Activities\CoachWeek\AbstractWeekBlock;
use TT\Modules\Activities\CoachWeek\WeekBlockGroup;
use TT\Modules\Activities\CoachWeek\WeekContext;
use TT\Modules\Activities\CoachWeek\WeekRow;
use TT\Modules\Activities\Services\ActivityRegisterProgress;

/**
 * #4493 — on a past training whose register is recorded, how many of the
 * recorded players attended ("12 of 14 present"). Present and Late both
 * count as attended (#4041); the base is the recorded rows. Counted by
 * `ActivityRegisterProgress`, from the week's one register read.
 */
final class AttendanceRateBlock extends AbstractWeekBlock {

    public function key(): string { return 'att_rate'; }

    public function group(): string { return WeekBlockGroup::TRAINING; }

    public function label(): string { return __( 'Attendance after the fact', 'talenttrack' ); }

    public function help(): string { return __( 'How many of the recorded players were there, on a past training.', 'talenttrack' ); }

    /** persona => devices on by default (#4493); every other persona opts in. */
    private const DEFAULTS = [
        'head_coach' => [ 'desktop' ],
    ];

    public function defaultFor( string $persona, string $device ): bool {
        return in_array( $device, self::DEFAULTS[ $persona ] ?? [], true );
    }

    /** No default and no fallback turns it on for a persona the table does not name. */
    public function optInFor( string $persona ): bool {
        return ! isset( self::DEFAULTS[ $persona ] );
    }

    public function rowDefaults(): array { return [ 'attendance_rate' => null ]; }

    public function rowFields( WeekRow $row, WeekContext $week ): array {
        if ( $row->type !== ActivityTypeKey::TRAINING || $row->when !== WeekRow::WHEN_PAST ) return [];
        if ( ! $row->register || ! $week->canSeeTeam( $row->teamId ) ) return [];
        if ( ! $week->recorded( $row->activityId ) ) return [];
        return [ 'attendance_rate' => [
            'present'  => ActivityRegisterProgress::attendedCount( $row->activityId ),
            'recorded' => ActivityRegisterProgress::recordedCount( $row->activityId ),
        ] ];
    }
}
