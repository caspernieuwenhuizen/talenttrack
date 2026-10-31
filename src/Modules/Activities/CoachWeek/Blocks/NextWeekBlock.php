<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\ActivityTypeKey;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Modules\Activities\CoachWeek\WeekBlockGroup;
use TT\Modules\Activities\CoachWeek\WeekContext;
use TT\Modules\Activities\Repositories\ActivitiesRepository;

/** The first activities of the seven days after the window, below the list. */
final class NextWeekBlock extends CoreWeekBlock {

    public const PEEK = 2;

    public function key(): string { return 'next_week'; }

    public function group(): string { return WeekBlockGroup::SCOPE; }

    public function label(): string { return __( 'Peek at next week', 'talenttrack' ); }

    public function help(): string { return __( 'The first two activities of next week, below the list.', 'talenttrack' ); }

    public function panelFields( WeekContext $week ): array {
        $from = gmdate( 'Y-m-d', (int) strtotime( $week->end . ' 12:00:00 UTC +1 day' ) );
        $to   = gmdate( 'Y-m-d', (int) strtotime( $week->end . ' 12:00:00 UTC +7 days' ) );
        $rows = ( new ActivitiesRepository() )->listForTeamsInWindow( array_keys( $week->teams ), $from, $to );

        $out = [];
        foreach ( array_slice( $rows, 0, self::PEEK ) as $activity ) {
            $a        = (array) $activity;
            $type     = strtolower( trim( (string) ( $a['activity_type_key'] ?? '' ) ) );
            $is_match = ActivityTypeKey::isMatchLike( $type );
            $team_id  = (int) ( $a['team_id'] ?? 0 );
            $out[]    = [
                'activity_id' => (int) ( $a['id'] ?? 0 ),
                'team_id'     => $team_id,
                'team_name'   => $week->teams[ $team_id ] ?? '',
                'date'        => substr( (string) ( $a['session_date'] ?? '' ), 0, 10 ),
                'type_key'    => $type,
                'type_label'  => (string) ( LabelTranslator::activityType( $type ) ?? ucfirst( str_replace( '_', ' ', $type ) ) ),
                'is_match'    => $is_match,
                'title'       => (string) ( $a['title'] ?? '' ),
                'opponent'    => $is_match ? (string) ( $a['opponent'] ?? '' ) : '',
                'start_time'  => preg_match( '/^(\d{2}:\d{2})/', trim( (string) ( $a['start_time'] ?? '' ) ), $m ) === 1 ? $m[1] : '',
            ];
        }
        return [ 'next_week' => $out ];
    }
}
