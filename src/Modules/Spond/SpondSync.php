<?php
namespace TT\Modules\Spond;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * SpondSync (#0031, rewritten via #0062) — upsert loop for Spond → tt_activities.
 *
 * Fetch + parse + upsert + soft-archive missing UIDs. Spond wins
 * schedule fields (date / title / location); TalentTrack wins
 * activity_type (once a coach changed it), attendance, and evaluations.
 *
 * #0062 swapped the per-team iCal URL for a per-club login + per-team
 * `spond_group_id`. This class kept its public surface so the cron, CLI
 * and REST sync endpoints in `SpondCli` / `SpondModule` did not change.
 *
 * Returns a per-team summary dict; the caller decides whether to log,
 * surface in the team-form notice, or both.
 */
final class SpondSync {

    /**
     * Sync every team that has a non-empty `spond_group_id`.
     *
     * @return array<int,array{team_id:int,status:string,fetched_count:int,created_count:int,updated_count:int,archived_count:int,last_message:string}>
     */
    public static function syncAll(): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_teams
              WHERE spond_group_id IS NOT NULL AND spond_group_id <> ''
                AND club_id = %d
                AND archived_at IS NULL",
            CurrentClub::id()
        ) );
        $out = [];
        foreach ( (array) $rows as $row ) {
            $out[] = self::syncTeam( (int) $row->id );
        }
        return $out;
    }

    /**
     * @return array{team_id:int,status:string,fetched_count:int,created_count:int,updated_count:int,archived_count:int,last_message:string}
     */
    public static function syncTeam( int $team_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $team = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, spond_group_id FROM {$p}tt_teams
              WHERE id = %d AND club_id = %d",
            $team_id, CurrentClub::id()
        ) );
        if ( ! $team ) {
            return self::summary( $team_id, 'failed', 0, 0, 0, 0, __( 'Team not found.', 'talenttrack' ) );
        }

        $group_id = (string) ( $team->spond_group_id ?? '' );
        if ( $group_id === '' ) {
            return self::persistAndReturn( $team_id, self::summary(
                $team_id, 'disabled', 0, 0, 0, 0, __( 'No Spond group selected for this team.', 'talenttrack' )
            ) );
        }

        if ( ! CredentialsManager::hasCredentials() ) {
            return self::persistAndReturn( $team_id, self::summary(
                $team_id, 'disabled', 0, 0, 0, 0, __( 'No Spond credentials configured for the club.', 'talenttrack' )
            ) );
        }

        $fetch = SpondClient::fetchEvents( $group_id );
        if ( ! $fetch['ok'] ) {
            Logger::error( 'spond.fetch.failed', [
                'team_id'    => $team_id,
                'group_id'   => $group_id,
                'error_code' => $fetch['error_code'] ?? '',
                'http_code'  => $fetch['http_code'] ?? 0,
            ] );
            return self::persistAndReturn( $team_id, self::summary(
                $team_id, 'failed', 0, 0, 0, 0, (string) ( $fetch['error_message'] ?? '' )
            ) );
        }

        // v3.110.123 — log the page count so a pilot can see proof
        // of pagination working in the logs. Pre-fix a successful
        // sync reported "OK" but silently dropped events past the
        // first 100; the page count makes a multi-page sync
        // observable. Warning level when the safety cap (20 pages)
        // is hit, otherwise info.
        $pages_drawn = (int) ( $fetch['pages'] ?? 1 );
        $event_count = is_array( $fetch['events'] ?? null ) ? count( $fetch['events'] ) : 0;
        $log_payload = [
            'team_id'    => $team_id,
            'group_id'   => $group_id,
            'pages'      => $pages_drawn,
            'events'     => $event_count,
            'http_code'  => $fetch['http_code'] ?? 200,
        ];
        if ( $pages_drawn >= 20 ) {
            Logger::warning( 'spond.fetch.safety_cap_hit', $log_payload );
        } else {
            Logger::info( 'spond.fetch.ok', $log_payload );
        }

        $events = SpondParser::parse( $fetch['events'] );
        if ( empty( $events ) ) {
            return self::persistAndReturn( $team_id, self::summary(
                $team_id, 'ok', 0, 0, 0, 0, __( 'Spond group contained no upcoming events.', 'talenttrack' )
            ) );
        }

        $created = 0;
        $updated = 0;
        $seen    = [];
        foreach ( $events as $event ) {
            $uid = (string) $event['uid'];
            if ( $uid === '' ) continue;
            $seen[] = $uid;

            $existing = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, activity_type_key FROM {$p}tt_activities
                  WHERE external_id = %s
                    AND activity_source_key = %s
                    AND club_id = %d
                  LIMIT 1",
                $uid, 'spond', CurrentClub::id()
            ) );

            $title    = (string) ( $event['summary'] ?? '' );
            $location = (string) ( $event['location'] ?? '' );
            $notes    = trim( (string) ( $event['description'] ?? '' ) );
            $dtstart  = (string) ( $event['dtstart'] ?? '' );

            // Spond timestamps are UTC; the date + the TIME columns are
            // local wall-clock. Convert through the site timezone (this
            // also fixes a UTC-date off-by-one for late-evening events,
            // which the old `substr( $dtstart, 0, 10 )` could mis-date).
            [ $session_date, $start_time ] = self::localParts( $dtstart );
            [ , $end_time ]                = self::localParts( (string) ( $event['dtend'] ?? '' ) );
            [ , $meet_time ]               = self::localParts( (string) ( $event['meetup'] ?? '' ) );

            if ( $existing ) {
                // Spond wins schedule fields (incl. times); TalentTrack-set
                // type wins (don't overwrite once a coach has changed it),
                // so the time mapping keys off the existing row's type.
                $type_key = (string) ( $existing->activity_type_key ?? '' );
                $update   = [
                    'title'        => $title,
                    'session_date' => $session_date ?: '0000-00-00',
                    'location'     => $location,
                    'notes'        => $notes,
                ] + self::timeColumns( $type_key, $start_time, $end_time, $meet_time );
                $wpdb->update(
                    "{$p}tt_activities",
                    $update + [ 'archived_at' => null ],
                    [ 'id' => (int) $existing->id, 'club_id' => CurrentClub::id() ]
                );
                $updated++;
            } else {
                $type_key = SpondTypeResolver::classify( $title, $notes );
                $wpdb->insert( "{$p}tt_activities", [
                    'club_id'             => CurrentClub::id(),
                    'team_id'             => $team_id,
                    'title'               => $title,
                    'session_date'        => $session_date ?: '0000-00-00',
                    'location'            => $location,
                    'notes'               => $notes,
                    'activity_type_key'   => $type_key,
                    'activity_status_key' => 'planned',
                    'activity_source_key' => 'spond',
                    'external_id'         => $uid,
                    'coach_id'            => 0,
                ] + self::timeColumns( $type_key, $start_time, $end_time, $meet_time ) );
                if ( $wpdb->insert_id ) $created++;
            }
        }

        // Soft-archive Spond-imported rows whose UID is no longer in the feed.
        $archived = 0;
        if ( ! empty( $seen ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $seen ), '%s' ) );
            $params       = array_merge( [ $team_id, CurrentClub::id() ], $seen );
            $archived     = (int) $wpdb->query( $wpdb->prepare(
                "UPDATE {$p}tt_activities
                    SET archived_at = NOW()
                  WHERE team_id = %d
                    AND club_id = %d
                    AND activity_source_key = 'spond'
                    AND archived_at IS NULL
                    AND external_id NOT IN ({$placeholders})",
                ...$params
            ) );
        }

        return self::persistAndReturn( $team_id, self::summary(
            $team_id, 'ok', count( $events ), $created, $updated, $archived,
            sprintf(
                /* translators: 1: created count, 2: updated count, 3: archived count */
                __( 'Synced: %1$d new · %2$d updated · %3$d archived.', 'talenttrack' ),
                $created, $updated, $archived
            )
        ) );
    }

    /** Activity types that carry kickoff + presence times. */
    private const MATCH_TYPES = [ 'game', 'match', 'friendly', 'tournament' ];

    /**
     * Split a UTC `Y-m-d H:i:s` into a local `[ date, time ]` pair using
     * the site timezone. Returns `[ '', '' ]` for empty / unparseable
     * input.
     *
     * @return array{0:string,1:string}
     */
    private static function localParts( string $utc ): array {
        $utc = trim( $utc );
        if ( $utc === '' ) return [ '', '' ];
        try {
            $dt = new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) );
            $dt = $dt->setTimezone( wp_timezone() );
            return [ $dt->format( 'Y-m-d' ), $dt->format( 'H:i:s' ) ];
        } catch ( \Exception $e ) {
            return [ '', '' ];
        }
    }

    /**
     * Build the time columns for an activity row. Every event gets
     * start/end times; match-type events additionally get the kickoff
     * (= start) and presence (= meet-up) times. Empty values become null.
     *
     * @return array<string,string|null>
     */
    private static function timeColumns( string $type_key, string $start_time, string $end_time, string $meet_time ): array {
        $cols = [
            'start_time' => $start_time !== '' ? $start_time : null,
            'end_time'   => $end_time   !== '' ? $end_time   : null,
        ];
        if ( in_array( $type_key, self::MATCH_TYPES, true ) ) {
            $cols['kickoff_time']     = $start_time !== '' ? $start_time : null;
            $cols['time_of_presence'] = $meet_time  !== '' ? $meet_time  : null;
        }
        return $cols;
    }

    /**
     * @param array{team_id:int,status:string,fetched_count:int,created_count:int,updated_count:int,archived_count:int,last_message:string} $summary
     * @return array{team_id:int,status:string,fetched_count:int,created_count:int,updated_count:int,archived_count:int,last_message:string}
     */
    private static function persistAndReturn( int $team_id, array $summary ): array {
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}tt_teams",
            [
                'spond_last_sync_at'      => current_time( 'mysql' ),
                'spond_last_sync_status'  => $summary['status'],
                'spond_last_sync_message' => $summary['last_message'],
            ],
            [ 'id' => $team_id, 'club_id' => CurrentClub::id() ]
        );
        return $summary;
    }

    /**
     * @return array{team_id:int,status:string,fetched_count:int,created_count:int,updated_count:int,archived_count:int,last_message:string}
     */
    private static function summary( int $team_id, string $status, int $fetched, int $created, int $updated, int $archived, string $message ): array {
        return [
            'team_id'         => $team_id,
            'status'          => $status,
            'fetched_count'   => $fetched,
            'created_count'   => $created,
            'updated_count'   => $updated,
            'archived_count'  => $archived,
            'last_message'    => $message,
        ];
    }
}
