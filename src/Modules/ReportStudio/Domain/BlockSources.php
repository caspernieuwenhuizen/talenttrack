<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerReportAudience;

/**
 * BlockSources (#4571) — what a block reads and who it is for, in the plain
 * words the Studio's settings panel shows a builder.
 */
final class BlockSources {

    /** Where the block's figures come from. */
    public static function source( ReportBlock $block ): string {
        switch ( $block->key() ) {
            case 'playing_time':
                return __( 'Minutes from the match line-ups, the same figures as the team monthly report.', 'talenttrack' );
            case 'attendance_per_week':
                return __( 'Attendance taken at trainings and matches, counted the way the attendance reports count it.', 'talenttrack' );
            case 'own_question':
                return __( 'The data and the measure you pick, read the way the analytics explorer reads them.', 'talenttrack' );
        }
        return __( 'Figures TalentTrack already holds.', 'talenttrack' );
    }

    /** Whether a family may see the block, in words. */
    public static function familyVisibility( ReportBlock $block ): string {
        return in_array( PlayerReportAudience::FAMILY, $block->audiences(), true )
            ? __( 'A family may see this block, without other players\' figures.', 'talenttrack' )
            : __( 'Staff only: a family never sees this block.', 'talenttrack' );
    }
}
