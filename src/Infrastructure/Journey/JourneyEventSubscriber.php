<?php
namespace TT\Infrastructure\Journey;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * JourneyEventSubscriber — subscribes to existing module hooks and emits
 * journey events as a side-effect.
 *
 * Each subscriber is idempotent via the EventEmitter's uk_natural key
 * lookup, so repeated saves of the same source row do not multiply
 * events. The hooks themselves remain owned by their source modules:
 * Evaluations, Goals, PDP, Players, Trials. This class is the only
 * place where journey-specific reactions to those hooks live.
 */
final class JourneyEventSubscriber {

    public static function init(): void {
        add_action( 'tt_evaluation_saved',          [ __CLASS__, 'on_evaluation_saved' ], 10, 2 );
        add_action( 'tt_goal_saved',                [ __CLASS__, 'on_goal_saved' ], 10, 3 );
        add_action( 'tt_pdp_verdict_signed_off',    [ __CLASS__, 'on_pdp_verdict_signed_off' ], 10, 2 );
        add_action( 'tt_player_created',            [ __CLASS__, 'on_player_created' ], 10, 2 );
        add_action( 'tt_player_save_diff',          [ __CLASS__, 'on_player_save_diff' ], 10, 3 );
        add_action( 'tt_trial_started',             [ __CLASS__, 'on_trial_started' ], 10, 2 );
        add_action( 'tt_trial_decision_recorded',   [ __CLASS__, 'on_trial_decision_recorded' ], 10, 4 );
    }

    public static function on_evaluation_saved( int $player_id, int $evaluation_id ): void {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, eval_date, overall_rating FROM {$wpdb->prefix}tt_evaluations WHERE id = %d AND club_id = %d",
            $evaluation_id, CurrentClub::id()
        ) );
        if ( ! $row ) return;
        $eval_date = (string) $row->eval_date;
        if ( strlen( $eval_date ) === 10 ) $eval_date .= ' 00:00:00';

        EventEmitter::emit(
            $player_id,
            'evaluation_completed',
            $eval_date,
            sprintf( __( 'Evaluation on %s', 'talenttrack' ), substr( $eval_date, 0, 10 ) ),
            [
                'evaluation_id' => (int) $row->id,
                'overall'       => isset( $row->overall_rating ) ? (float) $row->overall_rating : 0.0,
            ],
            'Evaluations',
            'evaluation',
            $evaluation_id
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function on_goal_saved( int $player_id, int $goal_id, array $data ): void {
        $title = (string) ( $data['title'] ?? '' );
        EventEmitter::emit(
            $player_id,
            'goal_set',
            current_time( 'mysql' ),
            $title !== '' ? sprintf( __( 'Goal set: %s', 'talenttrack' ), $title ) : __( 'Goal set', 'talenttrack' ),
            [ 'goal_id' => $goal_id ],
            'Goals',
            'goal',
            $goal_id
        );
    }

    public static function on_pdp_verdict_signed_off( int $verdict_id, int $file_id ): void {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT v.signed_off_at, v.decision, f.player_id
               FROM {$wpdb->prefix}tt_pdp_verdicts v
               JOIN {$wpdb->prefix}tt_pdp_files f ON f.id = v.pdp_file_id AND f.club_id = v.club_id
              WHERE v.id = %d AND v.club_id = %d",
            $verdict_id, CurrentClub::id()
        ) );
        if ( ! $row ) return;

        EventEmitter::emit(
            (int) $row->player_id,
            'pdp_verdict_recorded',
            (string) ( $row->signed_off_at ?: current_time( 'mysql' ) ),
            sprintf( __( 'PDP verdict: %s', 'talenttrack' ), (string) $row->decision ),
            [
                'pdp_file_id' => $file_id,
                'decision'    => (string) $row->decision,
            ],
            'Pdp',
            'pdp_verdict',
            $verdict_id
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function on_player_created( int $player_id, array $data ): void {
        $date_joined = isset( $data['date_joined'] ) && $data['date_joined'] !== ''
            ? (string) $data['date_joined'] . ' 00:00:00'
            : current_time( 'mysql' );

        EventEmitter::emit(
            $player_id,
            'joined_academy',
            $date_joined,
            __( 'Joined the academy', 'talenttrack' ),
            [],
            'Players',
            'player',
            $player_id
        );

        // If created with status=trial, the Trials module will fire
        // tt_trial_started separately when the trial case is opened —
        // we don't preempt that here.
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    public static function on_player_save_diff( int $player_id, array $old, array $new ): void {
        // Status transitions.
        $old_status = (string) ( $old['status'] ?? '' );
        $new_status = (string) ( $new['status'] ?? '' );
        if ( $old_status !== '' && $old_status !== $new_status ) {
            self::emitStatusTransition( $player_id, $old_status, $new_status );
        }

        // Team change + age-group promotion.
        $old_team = (int) ( $old['team_id'] ?? 0 );
        $new_team = (int) ( $new['team_id'] ?? 0 );
        if ( $old_team !== $new_team && $new_team > 0 ) {
            self::emitTeamChange( $player_id, $old_team, $new_team );
        }

        // Position change — preferred_positions field.
        $old_pos = (string) ( $old['preferred_positions'] ?? '' );
        $new_pos = (string) ( $new['preferred_positions'] ?? '' );
        if ( $old_pos !== $new_pos && $new_pos !== '' ) {
            $synthetic_id = (int) ( ( $player_id * 1000 ) + ( crc32( $new_pos ) % 1000 ) );
            EventEmitter::emit(
                $player_id,
                'position_changed',
                current_time( 'mysql' ),
                sprintf( __( 'Position: %1$s → %2$s', 'talenttrack' ), $old_pos !== '' ? $old_pos : __( 'unset', 'talenttrack' ), $new_pos ),
                [ 'from' => $old_pos, 'to' => $new_pos ],
                'Players',
                'position_change',
                $synthetic_id
            );
        }
    }

    public static function on_trial_started( int $case_id, int $player_id ): void {
        EventEmitter::emit(
            $player_id,
            'trial_started',
            current_time( 'mysql' ),
            __( 'Trial started', 'talenttrack' ),
            [ 'trial_case_id' => $case_id ],
            'Trials',
            'trial_case',
            $case_id
        );
    }

    public static function on_trial_decision_recorded( int $case_id, int $player_id, string $decision, string $decided_at ): void {
        EventEmitter::emit(
            $player_id,
            'trial_ended',
            $decided_at !== '' ? $decided_at : current_time( 'mysql' ),
            sprintf( __( 'Trial ended: %s', 'talenttrack' ), $decision ),
            [
                'trial_case_id' => $case_id,
                'decision'      => $decision,
                'context'       => 'post_trial',
            ],
            'Trials',
            'trial_case',
            $case_id
        );

        if ( $decision === 'admit' ) {
            EventEmitter::emit(
                $player_id,
                'signed',
                $decided_at !== '' ? $decided_at : current_time( 'mysql' ),
                __( 'Signed after trial', 'talenttrack' ),
                [],
                'Trials',
                'trial_signed',
                $case_id
            );
        } elseif ( $decision === 'deny_final' ) {
            EventEmitter::emit(
                $player_id,
                'released',
                $decided_at !== '' ? $decided_at : current_time( 'mysql' ),
                __( 'Released after trial', 'talenttrack' ),
                [ 'context' => 'post_trial' ],
                'Trials',
                'trial_released',
                $case_id
            );
        }
    }

    private static function emitStatusTransition( int $player_id, string $old, string $new ): void {
        // Map status flips to journey events.
        $synthetic_id = (int) ( ( $player_id * 100 ) + crc32( $new ) % 100 );
        $now = current_time( 'mysql' );

        if ( $new === 'active' && $old === 'trial' ) {
            EventEmitter::emit( $player_id, 'signed', $now, __( 'Signed', 'talenttrack' ), [], 'Players', 'status_signed', $synthetic_id );
        } elseif ( $new === 'released' ) {
            EventEmitter::emit( $player_id, 'released', $now, __( 'Released', 'talenttrack' ), [ 'context' => 'mid_season' ], 'Players', 'status_released', $synthetic_id );
        } elseif ( $new === 'graduated' ) {
            EventEmitter::emit( $player_id, 'graduated', $now, __( 'Graduated', 'talenttrack' ), [], 'Players', 'status_graduated', $synthetic_id );
        }
    }

    private static function emitTeamChange( int $player_id, int $old_team_id, int $new_team_id ): void {
        global $wpdb;
        $synthetic_id = (int) ( ( $player_id * 1000000 ) + ( $new_team_id * 1000 ) + ( $old_team_id ) );

        $teams = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, age_group FROM {$wpdb->prefix}tt_teams WHERE id IN (%d, %d) AND club_id = %d",
            $old_team_id, $new_team_id, CurrentClub::id()
        ) );
        $by_id = [];
        foreach ( (array) $teams as $t ) $by_id[ (int) $t->id ] = $t;

        $old = $by_id[ $old_team_id ] ?? null;
        $new = $by_id[ $new_team_id ] ?? null;
        $old_name      = $old ? (string) $old->name      : '';
        $new_name      = $new ? (string) $new->name      : '';
        $old_age_group = $old ? (string) $old->age_group : '';
        $new_age_group = $new ? (string) $new->age_group : '';

        EventEmitter::emit(
            $player_id,
            'team_changed',
            current_time( 'mysql' ),
            sprintf( __( 'Team: %1$s → %2$s', 'talenttrack' ), $old_name !== '' ? $old_name : __( 'unset', 'talenttrack' ), $new_name ),
            [
                'from_team_id'   => $old_team_id,
                'to_team_id'     => $new_team_id,
                'from_team_name' => $old_name,
                'to_team_name'   => $new_name,
            ],
            'Players',
            'team_change',
            $synthetic_id
        );

        if ( $old_age_group !== $new_age_group && $new_age_group !== '' ) {
            EventEmitter::emit(
                $player_id,
                'age_group_promoted',
                current_time( 'mysql' ),
                sprintf( __( 'Age group: %1$s → %2$s', 'talenttrack' ), $old_age_group !== '' ? $old_age_group : __( 'unset', 'talenttrack' ), $new_age_group ),
                [
                    'from_team_id'   => $old_team_id,
                    'to_team_id'     => $new_team_id,
                    'from_age_group' => $old_age_group,
                    'to_age_group'   => $new_age_group,
                ],
                'Players',
                'age_group_change',
                $synthetic_id + 1
            );
        }
    }
}
