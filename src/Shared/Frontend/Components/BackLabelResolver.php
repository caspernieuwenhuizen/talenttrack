<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * BackLabelResolver — turns a back-link URL into a human-readable
 * "← Back to <X>" label. Defensive: any failure path falls back to
 * a list-level label or the generic "← Back".
 *
 * Resolution order for a URL like `?tt_view=players&id=42`:
 *   1. Read `tt_view` + `id` from the URL's query string.
 *   2. When id > 0: look up the entity name (player, team, …) and
 *      return "← Back to <name>".
 *   3. When id = 0 or lookup misses: return the list-level label
 *      "← Back to Players".
 *   4. When no `tt_view`: "← Back to Dashboard".
 */
final class BackLabelResolver {

    public static function labelFor( string $url ): string {
        $parsed = wp_parse_url( $url );
        $query  = is_array( $parsed ) ? (string) ( $parsed['query'] ?? '' ) : '';
        if ( $query === '' ) {
            return __( 'Back to Dashboard', 'talenttrack' );
        }
        parse_str( $query, $params );
        $tt_view = isset( $params['tt_view'] ) ? (string) $params['tt_view'] : '';
        $id      = isset( $params['id'] ) ? (int) $params['id'] : 0;
        if ( $tt_view === '' ) {
            return __( 'Back to Dashboard', 'talenttrack' );
        }
        if ( $id > 0 ) {
            $name = self::entityName( $tt_view, $id );
            if ( $name !== '' ) {
                /* translators: %s = entity display name (player, team, activity title, …) */
                return sprintf( __( 'Back to %s', 'talenttrack' ), $name );
            }
        }
        return self::listLabel( $tt_view );
    }

    private static function listLabel( string $tt_view ): string {
        switch ( $tt_view ) {
            case 'players':            return __( 'Back to Players', 'talenttrack' );
            case 'player-accounts':    return __( 'Back to Player accounts', 'talenttrack' );
            case 'parent-accounts':    return __( 'Back to Parent accounts', 'talenttrack' );
            case 'accounts':           return __( 'Back to Accounts & access', 'talenttrack' );
            case 'teams':              return __( 'Back to Teams', 'talenttrack' );
            case 'onboarding-pipeline':       return __( 'Back to Onboarding pipeline', 'talenttrack' );
            case 'prospects-overview':        return __( 'Back to Prospects', 'talenttrack' );
            case 'my-tasks':                  return __( 'Back to My tasks', 'talenttrack' );
            case 'analytics':                 return __( 'Back to Analytics', 'talenttrack' );
            case 'attendance-report-team':    return __( 'Back to Team attendance', 'talenttrack' );
            case 'attendance-report-player':  return __( 'Back to Player attendance', 'talenttrack' );
            case 'minutes-report-team':       return __( 'Back to Minutes played', 'talenttrack' );
            case 'match-executions':          return __( 'Back to Match executions', 'talenttrack' );
            case 'match-analysis':            return __( 'Back to Match analysis', 'talenttrack' );
            case 'people':          return __( 'Back to People', 'talenttrack' );
            case 'activities':      return __( 'Back to Activities', 'talenttrack' );
            case 'goals':           return __( 'Back to Goals', 'talenttrack' );
            case 'pdp':             return __( 'Back to PDPs', 'talenttrack' );
            case 'evaluations':     return __( 'Back to Evaluations', 'talenttrack' );
            case 'trial_cases':     return __( 'Back to Trial cases', 'talenttrack' );
            case 'reports':         return __( 'Back to Reports', 'talenttrack' );
            case 'roles':           return __( 'Back to Roles & rights', 'talenttrack' );
            case 'configuration':   return __( 'Back to Configuration', 'talenttrack' );
            case 'audit-log':       return __( 'Back to Audit log', 'talenttrack' );
            case 'messages':        return __( 'Back to Message log', 'talenttrack' );
            case 'my-messages':     return __( 'Back to My messages', 'talenttrack' );
            case 'mygoals':         return __( 'Back to My goals', 'talenttrack' );
            case 'myactivities':    return __( 'Back to My activities', 'talenttrack' );
            case 'mysettings':      return __( 'Back to My settings', 'talenttrack' );
            case 'team_blueprint':  return __( 'Back to Team blueprint', 'talenttrack' );
            case 'pdp_planning':    return __( 'Back to PDP planning', 'talenttrack' );
            case 'team_planner':    return __( 'Back to Team planner', 'talenttrack' );
            case 'measurement-tests':     return __( 'Back to Manage tests', 'talenttrack' );
            case 'measurements-entry':    return __( 'Back to Record measurements', 'talenttrack' );
            case 'measurements-coverage': return __( 'Back to Testing coverage', 'talenttrack' );
        }
        return __( 'Back', 'talenttrack' );
    }

    /**
     * #3156 — may the viewer see this child?
     *
     * The id reaching `entityName()` was parsed out of a caller-supplied
     * `tt_back` URL. It was club-scoped but not viewer-scoped, so a crafted
     * link resolved any child's name into a Back label — one name per
     * request rather than an enumerable list, which is why it rode along
     * with the URL-parameter sweep rather than getting its own issue.
     *
     * The same gate the player's own detail view uses, so the label cannot
     * name a record the viewer would be refused on arrival.
     */
    private static function viewerMaySeePlayer( int $player_id ): bool {
        return $player_id > 0 && \TT\Infrastructure\Security\AuthorizationService::canViewPlayer(
            get_current_user_id(),
            $player_id
        );
    }

    /**
     * #3156 — team equivalent. Composed from the two helpers `GET /teams`
     * uses to decide which rows to list, so a team the viewer is not
     * offered in a list cannot be named in a label either.
     */
    private static function viewerMaySeeTeam( int $team_id ): bool {
        if ( $team_id <= 0 ) return false;
        $user_id = get_current_user_id();
        if ( \TT\Infrastructure\Query\QueryHelpers::user_has_global_entity_read( $user_id, 'team' ) ) {
            return true;
        }
        $owned = array_map(
            'intval',
            array_column( \TT\Infrastructure\Query\QueryHelpers::get_teams_for_coach( $user_id, true ), 'id' )
        );
        return in_array( $team_id, $owned, true );
    }

    /**
     * Resolve a `(tt_view, id)` to a display name. Returns empty string
     * when the entity can't be found — or when the viewer may not see it
     * (#3156) — which causes the caller to fall back to a list-level label.
     */
    private static function entityName( string $tt_view, int $id ): string {
        global $wpdb;
        if ( ! $wpdb instanceof \wpdb ) return '';
        $club_id = (int) CurrentClub::id();
        switch ( $tt_view ) {
            case 'players':
                // #3156 — the id comes out of a caller-supplied `tt_back`
                // URL, so it is club-scoped but was not viewer-scoped: a
                // crafted link resolved any child's name into the Back
                // label. Returning '' falls back to the list-level label,
                // which is the same thing an unresolvable id already does.
                if ( ! self::viewerMaySeePlayer( $id ) ) return '';
                $row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT first_name, last_name FROM {$wpdb->prefix}tt_players WHERE id = %d AND club_id = %d",
                    $id, $club_id
                ) );
                if ( $row ) {
                    $name = trim( (string) $row->first_name . ' ' . (string) $row->last_name );
                    return $name !== '' ? $name : '';
                }
                return '';
            case 'teams':
                if ( ! self::viewerMaySeeTeam( $id ) ) return '';
                $name = $wpdb->get_var( $wpdb->prepare(
                    "SELECT name FROM {$wpdb->prefix}tt_teams WHERE id = %d AND club_id = %d",
                    $id, $club_id
                ) );
                return is_string( $name ) ? $name : '';
            case 'activities':
                $title = $wpdb->get_var( $wpdb->prepare(
                    "SELECT title FROM {$wpdb->prefix}tt_activities WHERE id = %d AND club_id = %d",
                    $id, $club_id
                ) );
                return is_string( $title ) && $title !== '' ? $title : '';
            case 'goals':
                $title = $wpdb->get_var( $wpdb->prepare(
                    "SELECT title FROM {$wpdb->prefix}tt_goals WHERE id = %d AND club_id = %d",
                    $id, $club_id
                ) );
                return is_string( $title ) && $title !== '' ? $title : '';
            case 'pdp':
                $row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT pf.player_id, pl.first_name, pl.last_name
                       FROM {$wpdb->prefix}tt_pdp_files pf
                       JOIN {$wpdb->prefix}tt_players pl ON pl.id = pf.player_id
                       WHERE pf.id = %d AND pf.club_id = %d",
                    $id, $club_id
                ) );
                // #3156 — a PDP label names the child it belongs to, so it
                // follows that child's visibility.
                if ( $row && ! self::viewerMaySeePlayer( (int) $row->player_id ) ) return '';
                if ( $row ) {
                    $name = trim( (string) $row->first_name . ' ' . (string) $row->last_name );
                    if ( $name !== '' ) {
                        /* translators: %s = player display name */
                        return sprintf( __( "%s's PDP", 'talenttrack' ), $name );
                    }
                }
                return '';
            case 'evaluations':
                $row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT ev.player_id, pl.first_name, pl.last_name, ev.eval_date
                       FROM {$wpdb->prefix}tt_evaluations ev
                       JOIN {$wpdb->prefix}tt_players pl ON pl.id = ev.player_id
                       WHERE ev.id = %d AND ev.club_id = %d",
                    $id, $club_id
                ) );
                // #3156 — an evaluation label names the child and the date.
                if ( $row && ! self::viewerMaySeePlayer( (int) $row->player_id ) ) return '';
                if ( $row ) {
                    $name = trim( (string) $row->first_name . ' ' . (string) $row->last_name );
                    if ( $name !== '' ) {
                        /* translators: 1: player name, 2: eval date */
                        return sprintf( __( 'Evaluation: %1$s (%2$s)', 'talenttrack' ), $name, (string) $row->eval_date );
                    }
                }
                return '';
            case 'people':
                $row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT first_name, last_name FROM {$wpdb->prefix}tt_people WHERE id = %d AND club_id = %d",
                    $id, $club_id
                ) );
                if ( $row ) {
                    $name = trim( (string) $row->first_name . ' ' . (string) $row->last_name );
                    return $name !== '' ? $name : '';
                }
                return '';
        }
        return '';
    }
}
