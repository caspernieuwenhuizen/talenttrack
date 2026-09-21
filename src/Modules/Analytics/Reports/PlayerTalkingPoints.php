<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\PlayerStatus\StatusVerdict;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Pdp\EvidencePacket;
use TT\Shared\Dates\TTDate;

/**
 * PlayerTalkingPoints (#3875, epic #3871) — what a conversation with this
 * player should raise, derived from the evidence packet.
 *
 * Nothing here is authored. Every point is read off state the plugin already
 * holds for the report's window, so it is true without anyone maintaining it
 * and disappears when the state changes. The status point is the same verdict
 * and the same reasons the team monthly report's agenda shows for the player,
 * so the two reports cannot rank one player differently.
 *
 * Wording is evidential, never a verdict: "3 of 11 sessions missed since
 * 1 August", not "attendance problem". These are children, and a printed
 * judgement follows them around.
 *
 * Thresholds read `tt_config` and fall back to the defaults below, so an
 * academy can move them without a release. The minutes signal reads the
 * academy's one minutes target (`MinutesShareQuery::targetPct()`) rather than
 * inventing a second number.
 */
final class PlayerTalkingPoints {

    public const LEVEL_RED   = 'red';
    public const LEVEL_AMBER = 'amber';
    public const LEVEL_INFO  = 'info';

    /** Attendance points dropped against the previous window before it is raised. */
    public const CONFIG_ATTENDANCE_DROP = 'player_report_attendance_drop_pts';
    public const DEFAULT_ATTENDANCE_DROP = 15;

    /** Activities a window needs before its attendance is compared at all. */
    public const CONFIG_MIN_ACTIVITIES = 'player_report_min_activities';
    public const DEFAULT_MIN_ACTIVITIES = 4;

    /** Recorded matches a window needs before playing time is compared. */
    public const CONFIG_MIN_MATCHES = 'player_report_min_matches';
    public const DEFAULT_MIN_MATCHES = 3;

    /** A window shorter than this is not long enough to expect an evaluation in. */
    public const CONFIG_EVAL_WINDOW_DAYS = 'player_report_eval_window_days';
    public const DEFAULT_EVAL_WINDOW_DAYS = 42;

    /** Journey events worth naming out loud in a conversation. */
    public const TRANSITION_EVENTS = [ 'age_group_promoted', 'position_changed', 'team_changed' ];

    /**
     * @param array<string,mixed> $packet `EvidencePacket::forPlayer()`.
     * @return list<array{key:string, level:string, text:string, evidence:string}>
     */
    public static function derive( array $packet, int $team_id, string $from, string $to ): array {
        $player_id = (int) ( $packet['player_id'] ?? 0 );
        $points    = [];

        foreach ( [
            self::status( (array) ( $packet['status'] ?? [] ) ),
            self::attendance( $player_id, (array) ( $packet['attendance'] ?? [] ), $from, $to ),
            self::minutes( $player_id, $team_id, $from, $to ),
            self::notEvaluated( (array) ( $packet['evaluations'] ?? [] ), $from, $to ),
            self::testsDown( (array) ( $packet['tests'] ?? [] ) ),
        ] as $point ) {
            if ( $point !== null ) $points[] = $point;
        }

        foreach ( self::goalsPastDue( (array) ( $packet['goals'] ?? [] ), $to ) as $point ) $points[] = $point;
        foreach ( self::returnedFromInjury( (array) ( $packet['injuries'] ?? [] ), $from, $to ) as $point ) $points[] = $point;
        foreach ( self::transitions( (array) ( $packet['recent_journey'] ?? [] ) ) as $point ) $points[] = $point;

        $missing = self::missingInputs( (array) ( $packet['status'] ?? [] ) );
        if ( $missing !== null ) $points[] = $missing;

        // Most urgent first; within a level, the order above — the status
        // point leads, as it does on the team report's agenda.
        $rank = [ self::LEVEL_RED => 0, self::LEVEL_AMBER => 1, self::LEVEL_INFO => 2 ];
        $keyed = [];
        foreach ( $points as $i => $point ) $keyed[] = [ $rank[ $point['level'] ] ?? 3, $i, $point ];
        usort( $keyed, static fn( array $a, array $b ): int => [ $a[0], $a[1] ] <=> [ $b[0], $b[1] ] );

        return array_map( static fn( array $k ): array => $k[2], $keyed );
    }

    /**
     * The verdict, when it asks for attention: amber or red, with the reasons
     * the status model gives. "Computed without …" is left to the missing-
     * inputs point, which says the same thing as an academy gap.
     *
     * @param array<string,mixed> $status
     * @return array{key:string, level:string, text:string, evidence:string}|null
     */
    private static function status( array $status ): ?array {
        $color = (string) ( $status['color'] ?? '' );
        if ( $color !== StatusVerdict::COLOR_RED && $color !== StatusVerdict::COLOR_AMBER ) return null;

        $missing = is_array( $status['missing_inputs'] ?? null ) ? $status['missing_inputs'] : [];
        $without = $missing !== []
            ? sprintf(
                /* translators: %s: comma-separated list of missing inputs */
                __( 'Computed without %s.', 'talenttrack' ),
                implode( ', ', array_map( static fn( $k ): string => StatusVerdict::inputLabel( (string) $k ), $missing ) )
            )
            : '';

        $reasons = [];
        foreach ( is_array( $status['reasons'] ?? null ) ? $status['reasons'] : [] as $reason ) {
            $reason = (string) $reason;
            if ( $reason !== '' && $reason !== $without ) $reasons[] = $reason;
        }

        return [
            'key'      => 'status',
            'level'    => $color === StatusVerdict::COLOR_RED ? self::LEVEL_RED : self::LEVEL_AMBER,
            'text'     => $color === StatusVerdict::COLOR_RED
                ? __( 'The status model flags this player as needing action.', 'talenttrack' )
                : __( 'The status model flags this player to watch.', 'talenttrack' ),
            'evidence' => implode( ' ', $reasons ),
        ];
    }

    /**
     * Attendance in the window against the equally long window before it,
     * counted by the packet's own query so the two cannot be counted
     * differently. Neither window says anything below the minimum activities.
     *
     * @param array<string,mixed> $current
     * @return array{key:string, level:string, text:string, evidence:string}|null
     */
    private static function attendance( int $player_id, array $current, string $from, string $to ): ?array {
        $min  = self::config( self::CONFIG_MIN_ACTIVITIES, self::DEFAULT_MIN_ACTIVITIES );
        $drop = self::config( self::CONFIG_ATTENDANCE_DROP, self::DEFAULT_ATTENDANCE_DROP );

        $now_total = (int) ( $current['activities'] ?? 0 );
        $now_rate  = $current['rate'] ?? null;
        if ( $now_total < $min || $now_rate === null ) return null;

        $previous = TeamMonthlyReport::previousWindow( $from, $to );
        $before   = EvidencePacket::attendanceFor( $player_id, $previous['from'], $previous['to'] );
        $was_rate = $before['rate'] ?? null;
        if ( (int) ( $before['activities'] ?? 0 ) < $min || $was_rate === null ) return null;

        if ( (float) $was_rate - (float) $now_rate < $drop ) return null;

        $missed = $now_total - (int) ( $current['present'] ?? 0 );

        return [
            'key'      => 'attendance',
            'level'    => self::LEVEL_AMBER,
            'text'     => sprintf(
                /* translators: 1: activities missed, 2: activities in the window, 3: window start date */
                __( '%1$d of %2$d activities missed since %3$s.', 'talenttrack' ),
                $missed,
                $now_total,
                TTDate::date( $from )
            ),
            'evidence' => sprintf(
                /* translators: 1: attendance percentage now, 2: attendance percentage in the previous window */
                __( 'Attendance %1$d%%, against %2$d%% in the period before.', 'talenttrack' ),
                (int) $now_rate,
                (int) $was_rate
            ),
        ];
    }

    /**
     * Share of the minutes the team played, against the academy's target.
     * A player the query leaves out because they did not get on played none
     * of what was available, which is exactly the case worth raising.
     *
     * @return array{key:string, level:string, text:string, evidence:string}|null
     */
    private static function minutes( int $player_id, int $team_id, string $from, string $to ): ?array {
        if ( $team_id <= 0 ) return null;

        $counts = ( new MinutesQuery() )->matchCountsForTeam( $team_id, $from, $to );
        if ( (int) $counts['recorded'] < self::config( self::CONFIG_MIN_MATCHES, self::DEFAULT_MIN_MATCHES ) ) return null;

        $available = 0;
        $played    = 0;
        foreach ( ( new MinutesQuery() )->forTeam( $team_id, $from, $to ) as $row ) {
            $available = max( $available, (int) $row['available_minutes'] );
            if ( (int) $row['player_id'] === $player_id ) $played = (int) $row['total_minutes'];
        }
        if ( $available <= 0 ) return null;

        $share  = (int) round( $played / $available * 100 );
        $target = MinutesShareQuery::targetPct();
        if ( $share >= $target ) return null;

        return [
            'key'      => 'minutes',
            'level'    => self::LEVEL_AMBER,
            'text'     => sprintf(
                /* translators: 1: player's share of the minutes, 2: academy target percentage */
                __( 'Played %1$d%% of the available minutes, below the academy target of %2$d%%.', 'talenttrack' ),
                $share,
                $target
            ),
            'evidence' => sprintf(
                /* translators: 1: minutes played, 2: minutes available, 3: matches with minutes recorded */
                __( '%1$d of %2$d minutes over %3$d recorded matches.', 'talenttrack' ),
                $played,
                $available,
                (int) $counts['recorded']
            ),
        ];
    }

    /**
     * No evaluation in a window long enough to expect one. A statement about
     * the academy's record, not about the player.
     *
     * @param array<int|string,mixed> $evaluations
     * @return array{key:string, level:string, text:string, evidence:string}|null
     */
    private static function notEvaluated( array $evaluations, string $from, string $to ): ?array {
        if ( $evaluations !== [] ) return null;

        $days = (int) round( ( (int) strtotime( $to ) - (int) strtotime( $from ) ) / DAY_IN_SECONDS ) + 1;
        if ( $days < self::config( self::CONFIG_EVAL_WINDOW_DAYS, self::DEFAULT_EVAL_WINDOW_DAYS ) ) return null;

        return [
            'key'      => 'not_evaluated',
            'level'    => self::LEVEL_INFO,
            'text'     => sprintf(
                /* translators: %s: window start date */
                __( 'No evaluation recorded since %s.', 'talenttrack' ),
                TTDate::date( $from )
            ),
            'evidence' => __( 'Worth an evaluation before or after this conversation, so the next report has one to read.', 'talenttrack' ),
        ];
    }

    /**
     * A test that moved the wrong way against its previous reading — the
     * direction follows the test, so a slower sprint counts and a heavier
     * weight does not.
     *
     * @param array<int|string,mixed> $tests
     * @return array{key:string, level:string, text:string, evidence:string}|null
     */
    private static function testsDown( array $tests ): ?array {
        $names = [];
        foreach ( $tests as $test ) {
            if ( is_array( $test ) && ( $test['trend'] ?? '' ) === 'down' ) {
                $names[] = (string) ( $test['name'] ?? '' );
            }
        }
        if ( $names === [] ) return null;

        return [
            'key'      => 'tests_down',
            'level'    => self::LEVEL_INFO,
            'text'     => sprintf(
                /* translators: %s: comma-separated test names */
                _n( 'A test moved the wrong way since the reading before: %s.', 'Tests moved the wrong way since the reading before: %s.', count( $names ), 'talenttrack' ),
                implode( ', ', $names )
            ),
            'evidence' => '',
        ];
    }

    /**
     * @param array<int|string,mixed> $goals
     * @return list<array{key:string, level:string, text:string, evidence:string}>
     */
    private static function goalsPastDue( array $goals, string $to ): array {
        $out = [];
        foreach ( $goals as $goal ) {
            if ( ! is_array( $goal ) || ! empty( $goal['is_closed'] ) ) continue;
            $due = (string) ( $goal['due_date'] ?? '' );
            if ( $due === '' || substr( $due, 0, 10 ) >= $to ) continue;

            $out[] = [
                'key'      => 'goal_past_due',
                'level'    => self::LEVEL_AMBER,
                'text'     => sprintf(
                    /* translators: 1: goal title, 2: its due date */
                    __( 'Goal "%1$s" was due on %2$s and is still open.', 'talenttrack' ),
                    (string) ( $goal['title'] ?? '' ),
                    TTDate::date( $due )
                ),
                'evidence' => '',
            ];
        }
        return $out;
    }

    /**
     * Back from an injury inside the window — a conversation about load.
     *
     * @param array<int|string,mixed> $injuries
     * @return list<array{key:string, level:string, text:string, evidence:string}>
     */
    private static function returnedFromInjury( array $injuries, string $from, string $to ): array {
        $out = [];
        foreach ( $injuries as $injury ) {
            if ( ! is_array( $injury ) ) continue;
            $back = (string) ( $injury['actual_return'] ?? '' );
            if ( $back === '' || $back < $from || $back > $to ) continue;

            $out[] = [
                'key'      => 'returned_from_injury',
                'level'    => self::LEVEL_INFO,
                'text'     => sprintf(
                    /* translators: %s: return-to-play date */
                    __( 'Back from injury on %s — worth talking about load.', 'talenttrack' ),
                    TTDate::date( $back )
                ),
                'evidence' => '',
            ];
        }
        return $out;
    }

    /**
     * A move to another age group, team or position in the window. Named out
     * loud because it changes what the conversation is about.
     *
     * @param array<int|string,mixed> $events
     * @return list<array{key:string, level:string, text:string, evidence:string}>
     */
    private static function transitions( array $events ): array {
        $out = [];
        foreach ( $events as $event ) {
            if ( ! is_object( $event ) ) continue;
            if ( ! in_array( (string) ( $event->event_type ?? '' ), self::TRANSITION_EVENTS, true ) ) continue;

            $out[] = [
                'key'      => 'transition',
                'level'    => self::LEVEL_INFO,
                'text'     => sprintf(
                    /* translators: 1: what changed, e.g. "Moved to U17", 2: the date */
                    __( '%1$s (%2$s).', 'talenttrack' ),
                    (string) ( $event->summary ?? '' ),
                    TTDate::date( substr( (string) ( $event->event_date ?? '' ), 0, 10 ) )
                ),
                'evidence' => '',
            ];
        }
        return $out;
    }

    /**
     * Evidence the status model wanted and the academy has not recorded. In a
     * one-to-one this is often the thing to raise, and it is a statement about
     * the academy's record.
     *
     * @param array<string,mixed> $status
     * @return array{key:string, level:string, text:string, evidence:string}|null
     */
    private static function missingInputs( array $status ): ?array {
        $missing = is_array( $status['missing_inputs'] ?? null ) ? $status['missing_inputs'] : [];
        if ( $missing === [] ) return null;

        return [
            'key'      => 'missing_inputs',
            'level'    => self::LEVEL_INFO,
            'text'     => sprintf(
                /* translators: %s: comma-separated list of missing inputs, e.g. "potential, behaviour" */
                __( 'The academy has no %s on record for this player yet.', 'talenttrack' ),
                implode( ', ', array_map( static fn( $k ): string => StatusVerdict::inputLabel( (string) $k ), $missing ) )
            ),
            'evidence' => __( 'The status above was worked out without it.', 'talenttrack' ),
        ];
    }

    private static function config( string $key, int $default ): int {
        $raw = QueryHelpers::get_config( $key, '' );
        return $raw === '' || ! is_numeric( $raw ) ? $default : max( 0, (int) $raw );
    }
}
