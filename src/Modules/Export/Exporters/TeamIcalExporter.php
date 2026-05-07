<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;

/**
 * TeamIcalExporter (#0063 use case 12) — read-only iCal feed of a
 * single team's TalentTrack-owned activities.
 *
 * Notably the inverse direction of #0031 / #0062 (Spond integration):
 * Spond reads activities INTO TT; this exports activities OUT to a
 * coach's phone calendar. Spond-sourced activities aren't included
 * (`tt_activities.source = 'spond'` filtered out) so subscribed
 * coaches don't see the same training twice — once from Spond, once
 * from the TT feed.
 *
 * Activities are date-only in the schema (`tt_activities.session_date`
 * is DATE, no start/end time), so each VEVENT renders as an all-day
 * event on the activity's date. iCal clients display these as banners
 * across the day rather than timed slots — correct semantically.
 *
 * URL: `GET /wp-json/talenttrack/v1/exports/team_ical?entity_id={team}&format=ics`
 *
 * Cap: `tt_view_activities` — same gate as the activities admin
 * surface. Per-coach signed-token URLs (spec Q4 lean) land with the
 * follow-up "subscribe to this calendar" UI; today the route is
 * cookie-authed only, suitable for the operator-grade preview.
 */
final class TeamIcalExporter implements ExporterInterface {

    public function key(): string { return 'team_ical'; }

    public function label(): string { return __( 'Team activity calendar (iCal)', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'ics' ]; }

    public function requiredCap(): string { return 'tt_view_activities'; }

    public function validateFilters( array $raw ): ?array {
        $months_back = isset( $raw['months_back'] ) ? (int) $raw['months_back'] : 1;
        if ( $months_back < 0 || $months_back > 24 ) $months_back = 1;
        $months_ahead = isset( $raw['months_ahead'] ) ? (int) $raw['months_ahead'] : 12;
        if ( $months_ahead < 0 || $months_ahead > 36 ) $months_ahead = 12;
        return [
            'months_back'  => $months_back,
            'months_ahead' => $months_ahead,
        ];
    }

    public function collect( ExportRequest $request ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $team_id = $request->entityId ?? 0;
        if ( $team_id <= 0 ) {
            return [ 'calendar_name' => 'TalentTrack', 'events' => [] ];
        }

        $months_back  = (int) ( $request->filters['months_back']  ?? 1 );
        $months_ahead = (int) ( $request->filters['months_ahead'] ?? 12 );

        $now    = current_time( 'Y-m-d' );
        $from   = ( new \DateTime( $now, wp_timezone() ) )->modify( '-' . $months_back  . ' months' )->format( 'Y-m-d' );
        $to     = ( new \DateTime( $now, wp_timezone() ) )->modify( '+' . $months_ahead . ' months' )->format( 'Y-m-d' );

        $team_name = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$p}tt_teams WHERE id = %d AND club_id = %d",
            $team_id, $request->clubId
        ) );

        // Spond-sourced activities are filtered out so a coach who
        // already syncs Spond doesn't double-up. The
        // `activity_source_key` column landed in migration 0040;
        // default `'manual'` covers existing rows.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, session_date, location, notes
               FROM {$p}tt_activities
              WHERE team_id = %d
                AND club_id = %d
                AND session_date BETWEEN %s AND %s
                AND ( activity_source_key IS NULL OR activity_source_key = 'manual' )
              ORDER BY session_date ASC",
            $team_id, $request->clubId, $from, $to
        ) );
        if ( ! is_array( $rows ) ) $rows = [];

        $site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        if ( $site_host === '' ) $site_host = 'talenttrack.local';

        $events = [];
        foreach ( $rows as $r ) {
            $events[] = [
                'uid'         => 'tt-activity-' . (int) $r->id . '@' . $site_host,
                'starts_at'   => (string) $r->session_date,
                'ends_at'     => (string) $r->session_date,
                'all_day'     => true,
                'summary'     => (string) $r->title,
                'location'    => (string) $r->location,
                'description' => (string) ( $r->notes ?? '' ),
            ];
        }

        return [
            'calendar_name' => $team_name !== ''
                ? sprintf( /* translators: %s: team name */ __( '%s — TalentTrack activities', 'talenttrack' ), $team_name )
                : __( 'TalentTrack activities', 'talenttrack' ),
            'events' => $events,
        ];
    }
}
