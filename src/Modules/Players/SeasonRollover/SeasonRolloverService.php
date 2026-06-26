<?php
namespace TT\Modules\Players\SeasonRollover;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\JourneyEventType;
use TT\Infrastructure\Journey\EventEmitter;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Backup\BackupRunner;

/**
 * SeasonRolloverService — bulk cohort promotion at season end (#1381).
 *
 * v1 scope (LOCKED): move a whole squad up an age group and write a dated
 * journey event per player. There is NO season-entity creation or
 * assignment — the rollover is purely a team move + journey event. A
 * released player is LEFT ACTIVE (we do not archive them, so the data
 * retention clock never starts) and gets a dated `released` journey event.
 *
 * This is a bulk operation on existing records, so per CLAUDE.md §3 it is
 * wizard exemption (b) — a dedicated multi-step view drives it, not a
 * WizardInterface wizard.
 *
 * SaaS-readiness (CLAUDE.md §4): all business logic lives here. The
 * frontend view and the REST controller both call into this service, so a
 * future non-WordPress front end gets the same answers. Every read and
 * write is scoped to the active club.
 */
final class SeasonRolloverService {

    public const ACTION_PROMOTE  = 'promote';
    public const ACTION_RELEASE  = 'release';
    public const ACTION_GRADUATE = 'graduate';
    public const ACTION_SKIP     = 'skip';

    public const SOURCE_MODULE      = 'SeasonRollover';
    public const SOURCE_ENTITY_TYPE = 'season_rollover';

    /**
     * Build a dry-run list of the per-player changes a rollover would make,
     * without mutating anything. Drives the Review step + the REST `plan`
     * endpoint.
     *
     * @param array<int,int>    $mapping    source_team_id => target_team_id (0 = no promotion)
     * @param array<int,string> $selections player_id => action constant
     * @param string            $effective_date 'Y-m-d'
     * @return array{
     *   effective_date:string,
     *   changes:list<array{
     *     player_id:int, player_name:string,
     *     from_team_id:int, from_team_name:string,
     *     to_team_id:int, to_team_name:string,
     *     action:string, event_type:string, event_label:string
     *   }>,
     *   counts:array{moved:int, released:int, graduated:int, skipped:int}
     * }
     */
    public function plan( array $mapping, array $selections, string $effective_date ): array {
        $effective_date = $this->normaliseDate( $effective_date );

        $team_names = $this->teamNames();
        $changes    = [];
        $counts     = [ 'moved' => 0, 'released' => 0, 'graduated' => 0, 'skipped' => 0 ];

        foreach ( $this->resolvePlayers( $mapping, $selections ) as $row ) {
            $player        = $row['player'];
            $source_team_id = $row['source_team_id'];
            $target_team_id = $row['target_team_id'];
            $action        = $row['action'];

            $player_id   = (int) $player->id;
            $player_name = QueryHelpers::player_display_name( $player );
            $from_name   = $team_names[ $source_team_id ] ?? '';

            $to_team_id   = 0;
            $to_team_name = '';
            $event_type   = '';

            switch ( $action ) {
                case self::ACTION_PROMOTE:
                    if ( $target_team_id <= 0 || ! isset( $team_names[ $target_team_id ] ) ) {
                        // A promote with no valid target degrades to skip.
                        $action = self::ACTION_SKIP;
                        $counts['skipped']++;
                        break;
                    }
                    $to_team_id   = $target_team_id;
                    $to_team_name = $team_names[ $target_team_id ];
                    $event_type   = JourneyEventType::AGE_GROUP_PROMOTED;
                    $counts['moved']++;
                    break;
                case self::ACTION_RELEASE:
                    $event_type = JourneyEventType::RELEASED;
                    $counts['released']++;
                    break;
                case self::ACTION_GRADUATE:
                    $event_type = JourneyEventType::GRADUATED;
                    $counts['graduated']++;
                    break;
                default:
                    $action = self::ACTION_SKIP;
                    $counts['skipped']++;
                    break;
            }

            $changes[] = [
                'player_id'      => $player_id,
                'player_name'    => $player_name,
                'from_team_id'   => $source_team_id,
                'from_team_name' => $from_name,
                'to_team_id'     => $to_team_id,
                'to_team_name'   => $to_team_name,
                'action'         => $action,
                'event_type'     => $event_type,
                'event_label'    => self::actionLabel( $action ),
            ];
        }

        return [
            'effective_date' => $effective_date,
            'changes'        => $changes,
            'counts'         => $counts,
        ];
    }

    /**
     * Execute the rollover. Runs a full backup FIRST and aborts the entire
     * run if it fails — no player or team is mutated unless the backup
     * succeeded. Promote updates the team and emits AGE_GROUP_PROMOTED;
     * release emits RELEASED but leaves the player active; graduate emits
     * GRADUATED; skip does nothing.
     *
     * @param array<int,int>    $mapping
     * @param array<int,string> $selections
     * @param string            $effective_date 'Y-m-d'
     * @param string            $reason         free-text rollover reason
     * @return array{
     *   ok:bool, error:string,
     *   moved:int, released:int, graduated:int, skipped:int,
     *   backup_ok:bool, backup_file:string
     * }
     */
    public function execute( array $mapping, array $selections, string $effective_date, string $reason ): array {
        $effective_date = $this->normaliseDate( $effective_date );
        $reason         = trim( $reason );

        $result = [
            'ok'          => false,
            'error'       => '',
            'moved'       => 0,
            'released'    => 0,
            'graduated'   => 0,
            'skipped'     => 0,
            'backup_ok'   => false,
            'backup_file' => '',
        ];

        // SAFETY: back up BEFORE any mutation. A failed backup aborts the
        // whole run — we never start writing without a snapshot to roll
        // back to.
        $backup = BackupRunner::run();
        $result['backup_ok']   = ! empty( $backup['ok'] );
        $result['backup_file'] = (string) ( $backup['filename'] ?? '' );
        if ( empty( $backup['ok'] ) ) {
            $result['error'] = __( 'Backup failed — the rollover was aborted and no records were changed.', 'talenttrack' );
            return $result;
        }

        global $wpdb;
        $players_table = $wpdb->prefix . 'tt_players';
        $event_date    = $effective_date . ' 00:00:00';
        $team_meta     = $this->teamMeta();

        foreach ( $this->resolvePlayers( $mapping, $selections ) as $row ) {
            $player         = $row['player'];
            $source_team_id = $row['source_team_id'];
            $target_team_id = $row['target_team_id'];
            $action         = $row['action'];
            $player_id      = (int) $player->id;

            if ( $action === self::ACTION_PROMOTE ) {
                // Validate the target team belongs to this club before any
                // write. An invalid / cross-club target degrades to skip.
                if ( $target_team_id <= 0 || ! isset( $team_meta[ $target_team_id ] ) ) {
                    $result['skipped']++;
                    continue;
                }
                $from_age = (string) ( $team_meta[ $source_team_id ]['age_group'] ?? '' );
                $to_age   = (string) ( $team_meta[ $target_team_id ]['age_group'] ?? '' );

                $wpdb->update(
                    $players_table,
                    [ 'team_id' => $target_team_id ],
                    [ 'id' => $player_id, 'club_id' => CurrentClub::id() ]
                );

                EventEmitter::emit(
                    $player_id,
                    JourneyEventType::AGE_GROUP_PROMOTED,
                    $event_date,
                    sprintf(
                        /* translators: 1: from age group, 2: to age group */
                        __( 'Age group: %1$s → %2$s', 'talenttrack' ),
                        $from_age !== '' ? $from_age : __( 'unset', 'talenttrack' ),
                        $to_age !== '' ? $to_age : __( 'unset', 'talenttrack' )
                    ),
                    [
                        'from_team_id'   => $source_team_id,
                        'to_team_id'     => $target_team_id,
                        'from_age_group' => $from_age,
                        'to_age_group'   => $to_age,
                    ],
                    self::SOURCE_MODULE,
                    self::SOURCE_ENTITY_TYPE,
                    null,
                    null,
                    null
                );
                $result['moved']++;
            } elseif ( $action === self::ACTION_RELEASE ) {
                // Released players are LEFT ACTIVE by design (#1381): we
                // record the transition without archiving, so the retention
                // clock never starts here.
                EventEmitter::emit(
                    $player_id,
                    JourneyEventType::RELEASED,
                    $event_date,
                    $reason !== ''
                        ? sprintf( /* translators: %s: reason */ __( 'Released — %s', 'talenttrack' ), $reason )
                        : __( 'Released', 'talenttrack' ),
                    [ 'from_team_id' => $source_team_id ],
                    self::SOURCE_MODULE,
                    self::SOURCE_ENTITY_TYPE,
                    null,
                    null,
                    null
                );
                $result['released']++;
            } elseif ( $action === self::ACTION_GRADUATE ) {
                EventEmitter::emit(
                    $player_id,
                    JourneyEventType::GRADUATED,
                    $event_date,
                    $reason !== ''
                        ? sprintf( /* translators: %s: reason */ __( 'Graduated — %s', 'talenttrack' ), $reason )
                        : __( 'Graduated', 'talenttrack' ),
                    [ 'from_team_id' => $source_team_id ],
                    self::SOURCE_MODULE,
                    self::SOURCE_ENTITY_TYPE,
                    null,
                    null,
                    null
                );
                $result['graduated']++;
            } else {
                $result['skipped']++;
            }
        }

        $result['ok'] = true;
        return $result;
    }

    /**
     * Resolve the selected players into validated rows. Only active players
     * who genuinely belong to the claimed source team (in this club) survive;
     * everything else is dropped so a tampered POST can't move a player
     * outside their team or club.
     *
     * @param array<int,int>    $mapping
     * @param array<int,string> $selections
     * @return list<array{player:\stdClass, source_team_id:int, target_team_id:int, action:string}>
     */
    private function resolvePlayers( array $mapping, array $selections ): array {
        $rows = [];
        foreach ( $mapping as $source_team_id => $target_team_id ) {
            $source_team_id = (int) $source_team_id;
            $target_team_id = (int) $target_team_id;
            if ( $source_team_id <= 0 ) continue;

            // Validate the source team belongs to this club.
            if ( QueryHelpers::get_team( $source_team_id ) === null ) continue;

            foreach ( $this->activePlayersOnTeam( $source_team_id ) as $player ) {
                $player_id = (int) $player->id;
                if ( ! isset( $selections[ $player_id ] ) ) continue;

                $action = self::normaliseAction( (string) $selections[ $player_id ] );
                if ( $action === self::ACTION_SKIP ) continue;

                $rows[] = [
                    'player'         => $player,
                    'source_team_id' => $source_team_id,
                    'target_team_id' => $target_team_id,
                    'action'         => $action,
                ];
            }
        }
        return $rows;
    }

    /**
     * Active players on a team, club-scoped. Each row is a tt_players row.
     *
     * @return list<\stdClass>
     */
    private function activePlayersOnTeam( int $team_id ): array {
        global $wpdb;
        /** @var list<\stdClass> $rows */
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_players
              WHERE team_id = %d AND status = 'active' AND club_id = %d
              ORDER BY last_name ASC, first_name ASC",
            $team_id, CurrentClub::id()
        ) );
        return $rows;
    }

    /**
     * Map of team_id => team name (club-scoped, non-archived).
     *
     * @return array<int,string>
     */
    private function teamNames(): array {
        $out = [];
        foreach ( QueryHelpers::get_teams() as $team ) {
            /** @var \stdClass $team */
            if ( ! empty( $team->archived_at ) ) continue;
            $out[ (int) $team->id ] = (string) $team->name;
        }
        return $out;
    }

    /**
     * Map of team_id => {name, age_group} for the active club (incl.
     * archived, so an age-group label still resolves for a from-team that
     * has since been archived).
     *
     * @return array<int,array{name:string, age_group:string}>
     */
    private function teamMeta(): array {
        $out = [];
        foreach ( QueryHelpers::get_teams() as $team ) {
            /** @var \stdClass $team */
            $out[ (int) $team->id ] = [
                'name'      => (string) $team->name,
                'age_group' => (string) ( $team->age_group ?? '' ),
            ];
        }
        return $out;
    }

    private function normaliseDate( string $date ): string {
        $date = trim( $date );
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            $ts = strtotime( $date );
            if ( $ts !== false ) return gmdate( 'Y-m-d', $ts );
        }
        return current_time( 'Y-m-d' );
    }

    public static function normaliseAction( string $action ): string {
        $action = strtolower( trim( $action ) );
        if ( in_array( $action, [
            self::ACTION_PROMOTE,
            self::ACTION_RELEASE,
            self::ACTION_GRADUATE,
            self::ACTION_SKIP,
        ], true ) ) {
            return $action;
        }
        return self::ACTION_SKIP;
    }

    public static function actionLabel( string $action ): string {
        switch ( self::normaliseAction( $action ) ) {
            case self::ACTION_PROMOTE:  return __( 'Promote', 'talenttrack' );
            case self::ACTION_RELEASE:  return __( 'Release', 'talenttrack' );
            case self::ACTION_GRADUATE: return __( 'Graduate', 'talenttrack' );
            default:                    return __( 'Skip', 'talenttrack' );
        }
    }
}
