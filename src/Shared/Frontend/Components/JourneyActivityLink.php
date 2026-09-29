<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\JourneyEventType;
use TT\Infrastructure\Journey\JourneyActivityResolver;

/**
 * JourneyActivityLink — the activity a journey entry was about, on screen.
 *
 * The label is `JourneyActivityResolver::label()` ("Match · against X ·
 * date"). Where it points depends on the entry and on the reader:
 *
 *   - a match observation opens the match analysis it came from, when the
 *     reader may open that surface;
 *   - otherwise, and for an evaluation, the activity itself, when the reader
 *     may open activities;
 *   - otherwise the label is plain text. A parent or a player reading the
 *     journey sees which match it was without a link they cannot follow.
 *
 * Both targets are gated through `CrossViewLink`. The PDF prints the label
 * only and does not come through here.
 */
final class JourneyActivityLink {

    /**
     * The label, linked where this reader may follow it. Empty when the entry
     * is about no activity.
     *
     * @param array<string,mixed>|null $activity an entry's `activity`.
     */
    public static function html( string $event_type, ?array $activity ): string {
        if ( $activity === null ) return '';
        $label = JourneyActivityResolver::label( $activity );
        if ( $label === '' ) return '';

        $url = self::url( $event_type, $activity );
        return $url === '' ? esc_html( $label ) : RecordLink::inline( $label, $url );
    }

    /**
     * Where the label points for this reader, or '' when it points nowhere.
     *
     * @param array<string,mixed> $activity
     */
    public static function url( string $event_type, array $activity ): string {
        $id = (int) ( $activity['id'] ?? 0 );
        if ( $id <= 0 ) return '';

        if ( $event_type === JourneyEventType::MATCH_OBSERVED && CrossViewLink::allows( 'match-analysis' ) ) {
            return BackLink::appendTo( add_query_arg(
                [ 'tt_view' => 'match-analysis', 'activity_id' => $id ], /* tt-xview-ok */ // gated by CrossViewLink::allows() above
                RecordLink::dashboardUrl()
            ) );
        }

        if ( CrossViewLink::allows( 'activities' ) ) {
            return RecordLink::detailUrlForWithBack( 'activities', $id ); /* tt-xview-ok */ // gated by CrossViewLink::allows() above
        }

        return '';
    }
}
