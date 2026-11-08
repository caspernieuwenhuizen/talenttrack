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
            case 'letterhead':
                return __( 'The team or the player, the period and the club colours, the same heading as the team monthly report and the player report.', 'talenttrack' );
            case 'key_figures':
                return __( 'The headline figures of the team monthly report, each against the period before.', 'talenttrack' );
            case 'heading':
            case 'text':
                return __( 'Text you write about this report. A note about a player does not belong here.', 'talenttrack' );
            case 'page_break':
                return __( 'Starts a new page when the report is printed.', 'talenttrack' );
            case 'playing_time':
                return __( 'Minutes from the match line-ups, the same figures as the team monthly report.', 'talenttrack' );
            case 'minutes_per_match':
                return __( 'The player\'s minutes in each match, the same figures as the minutes report.', 'talenttrack' );
            case 'goals_assists':
                return __( 'Goals and assists from the match goal log, counted the way the match reports count them.', 'talenttrack' );
            case 'minutes_share':
                return __( 'Minutes against the minutes the team played, the same figures as the minutes-share report.', 'talenttrack' );
            case 'attendance_per_week':
                return __( 'Attendance taken at trainings and matches, counted the way the attendance reports count it.', 'talenttrack' );
            case 'attendance_per_player':
                return __( 'Attendance taken at trainings and matches for every squad player, the same figures as the team monthly report.', 'talenttrack' );
            case 'attendance_flags':
                return __( 'Players over the attendance flag thresholds set in the configuration, for missed activities or for lateness: the attendance report\'s at-risk list. Not the monthly report\'s line at 70 percent.', 'talenttrack' );
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
