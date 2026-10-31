<?php
namespace TT\Modules\Prospects\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Prospects\Frontend\FrontendOnboardingPipelineView;
use TT\Modules\Prospects\ProspectScope;
use TT\Modules\Prospects\Repositories\ProspectConsentRequestsRepository;
use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Modules\Prospects\Repositories\ProspectVisitObservationsRepository;
use TT\Modules\Prospects\Repositories\ScoutingVisitsRepository;
use TT\Modules\Prospects\ScoutingVisitsAccess;

/**
 * ProspectRecord (#4257) — one prospect as a viewer may see it: where the
 * prospect stands on the onboarding pipeline and what happens next, the
 * consent requests sent on their behalf, and the scouting visits they were
 * seen at.
 *
 * The record page and `GET prospects/{id}/visits` both read from here, so
 * a non-WordPress front end gets the answers the page shows.
 *
 * Who may open it is the question `GET prospects/{id}` already answers: a
 * prospects read plus `ProspectScope::canSee()`. Anyone else is told the
 * prospect does not exist.
 */
final class ProspectRecord {

    public static function canOpen( int $user_id, int $prospect_id ): bool {
        return $user_id > 0
            && $prospect_id > 0
            && AuthorizationService::userCanOrMatrix( $user_id, 'tt_view_prospects' )
            && ( new ProspectsRepository() )->find( $prospect_id ) !== null
            && ProspectScope::canSee( $user_id, $prospect_id );
    }

    /** May this viewer log a consent request for this prospect? */
    public static function canLogConsent( int $user_id, int $prospect_id ): bool {
        return self::canOpen( $user_id, $prospect_id )
            && AuthorizationService::userCanOrMatrix( $user_id, 'tt_edit_prospects' );
    }

    /**
     * The prospects this user may log a consent request for, newest first,
     * for the wizard's picker when it is opened without a prospect.
     *
     * @return array<int,string> prospect id => name
     */
    public static function consentCandidates( int $user_id, int $limit = 200 ): array {
        if ( $user_id <= 0 || ! AuthorizationService::userCanOrMatrix( $user_id, 'tt_edit_prospects' ) ) return [];
        if ( ! AuthorizationService::userCanOrMatrix( $user_id, 'tt_view_prospects' ) ) return [];

        global $wpdb;
        $scope = ProspectScope::sqlClause( $user_id, 'p' );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — the scope clause is built from int-cast ids.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.id, p.first_name, p.last_name FROM {$wpdb->prefix}tt_prospects p
              WHERE p.club_id = %d AND p.archived_at IS NULL {$scope}
              ORDER BY p.discovered_at DESC, p.id DESC LIMIT %d",
            \TT\Infrastructure\Tenancy\CurrentClub::id(), max( 1, $limit )
        ) );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $name = trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) );
            $out[ (int) $row->id ] = $name !== '' ? $name : '#' . (int) $row->id;
        }
        return $out;
    }

    /**
     * The prospect's column on the onboarding pipeline and, when a task is
     * waiting, the link to it. Null when the prospect is off the board
     * (archived, or joined more than 90 days ago).
     *
     * @return array{key:string,label:string,summary:string,next_url:string}|null
     */
    public static function stage( int $user_id, int $prospect_id ): ?array {
        foreach ( FrontendOnboardingPipelineView::computeStages( $user_id, $prospect_id ) as $column ) {
            foreach ( (array) ( $column['cards'] ?? [] ) as $card ) {
                if ( (int) ( $card['id'] ?? 0 ) !== $prospect_id ) continue;
                $url = (string) ( $card['url'] ?? '' );
                return [
                    'key'      => (string) $column['key'],
                    'label'    => (string) $column['label'],
                    'summary'  => (string) ( $card['context_line'] ?? '' ),
                    // A card with no task to open links back to the board
                    // itself; that is not a next action.
                    'next_url' => strpos( $url, 'task_id=' ) !== false ? $url : '',
                ];
            }
        }
        return null;
    }

    /**
     * The consent requests sent for this prospect, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public static function consentRequests( int $prospect_id ): array {
        if ( ! ProspectConsentRequestsRepository::tableExists() ) return [];
        return array_values( ( new ProspectConsentRequestsRepository() )->forProspect( $prospect_id ) );
    }

    /**
     * The scouting visits the prospect was seen at, earliest first, keeping
     * only the visits this viewer may open: the visit planner is its own
     * surface, and a head coach who reads their age group's prospects does
     * not read a scout's visits.
     *
     * @return list<array{visit_id:int,visit_date:string,event:string,scout_user_id:int,notes:string,is_discovery:bool}>
     */
    public static function visits( int $user_id, int $prospect_id ): array {
        $is_admin = user_can( $user_id, 'tt_edit_settings' );
        if ( ! ScoutingVisitsAccess::allows( $user_id, $is_admin ) ) return [];

        $prospect     = ( new ProspectsRepository() )->find( $prospect_id );
        $discovery_id = $prospect ? (int) ( $prospect->scouting_visit_id ?? 0 ) : 0;
        $manages      = $is_admin || AuthorizationService::userCanOrMatrix( $user_id, 'tt_manage_prospects' );
        $visits       = new ScoutingVisitsRepository();

        $out = [];
        foreach ( ( new ProspectVisitObservationsRepository() )->forProspect( $prospect_id ) as $row ) {
            $visit_id = (int) ( $row->scouting_visit_id ?? 0 );
            $visit    = $visit_id > 0 ? $visits->findLinkable( $visit_id ) : null;
            if ( $visit === null ) continue;

            $scout_id = (int) ( $visit->scout_user_id ?? 0 );
            if ( ! $manages && $scout_id !== $user_id ) continue;

            $event = trim( (string) ( $row->event_description ?? '' ) );
            if ( $event === '' ) $event = trim( (string) ( $row->location ?? '' ) );

            $out[] = [
                'visit_id'      => $visit_id,
                'visit_date'    => (string) ( $row->visit_date ?? '' ),
                'event'         => $event,
                'scout_user_id' => $scout_id,
                'notes'         => trim( (string) ( $row->notes ?? '' ) ),
                'is_discovery'  => $visit_id === $discovery_id,
            ];
        }
        return $out;
    }
}
