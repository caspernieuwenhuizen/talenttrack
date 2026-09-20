<?php
namespace TT\Modules\Threads\Subscribers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Threads\Domain\ThreadVisibility;
use TT\Modules\Threads\ThreadMessagesRepository;

/**
 * GoalSystemMessageSubscriber (#0028) — writes is_system=1 messages
 * when a goal is created, when its status changes, and when a material
 * field on it moves.
 *
 * Hooks:
 *   - tt_goal_saved($player_id, $goal_id, $data)        — create
 *   - tt_goal_status_changed($goal_id, $status, $user)  — status
 *   - tt_goal_updated($player_id, $goal_id, $diff)      — #3781, the
 *     material fields (title, target date, progress) a save changed,
 *     as `field => ['from' => …, 'to' => …]`.
 *
 * One message per save, listing everything that changed. The goal edit
 * form autosaves (CLAUDE.md § 6 model A), so a coach dragging a
 * progress slider would otherwise leave a run of entries behind them:
 * consecutive saves by the same person on the same goal inside
 * COALESCE_WINDOW_SECONDS amend the entry they already wrote rather
 * than appending another. A value moved and moved back within that
 * window takes the entry with it.
 */
final class GoalSystemMessageSubscriber {

    /**
     * How long a field-change entry stays open for amendment. Long
     * enough to cover a coach working through one goal's form, short
     * enough that tomorrow's edit is plainly its own event.
     */
    public const COALESCE_WINDOW_SECONDS = 600;

    public static function init(): void {
        add_action( 'tt_goal_saved',          [ self::class, 'onSaved' ], 10, 3 );
        add_action( 'tt_goal_status_changed', [ self::class, 'onStatusChanged' ], 10, 3 );
        add_action( 'tt_goal_updated',        [ self::class, 'onUpdated' ], 10, 3 );
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function onSaved( int $player_id, int $goal_id, array $data ): void {
        // Only fire once per goal — at create time. Subsequent saves go
        // through update endpoints which fire tt_goal_status_changed.
        $existing = self::countMessages( $goal_id );
        if ( $existing > 0 ) return;

        $author = (int) get_current_user_id();
        $title  = (string) ( $data['title'] ?? '' );
        $body   = sprintf(
            /* translators: %s is the goal title */
            __( 'Goal created: %s', 'talenttrack' ),
            $title
        );
        ( new ThreadMessagesRepository() )->insert( [
            'thread_type'    => 'goal',
            'thread_id'      => $goal_id,
            'author_user_id' => $author,
            'body'           => $body,
            'visibility'     => ThreadVisibility::PUBLIC_LEVEL,
            'is_system'      => 1,
        ] );
    }

    public static function onStatusChanged( int $goal_id, string $status, int $user_id ): void {
        $body = sprintf(
            /* translators: %s is the new status */
            __( 'Status changed to: %s', 'talenttrack' ),
            $status
        );
        ( new ThreadMessagesRepository() )->insert( [
            'thread_type'    => 'goal',
            'thread_id'      => $goal_id,
            'author_user_id' => $user_id > 0 ? $user_id : (int) get_current_user_id(),
            'body'           => $body,
            'visibility'     => ThreadVisibility::PUBLIC_LEVEL,
            'is_system'      => 1,
        ] );
    }

    /**
     * #3781 — one entry per save, amended in place while the same author
     * keeps saving the same goal.
     *
     * @param array<string,array{from:mixed,to:mixed}> $diff
     */
    public static function onUpdated( int $player_id, int $goal_id, array $diff ): void {
        $diff = self::normaliseDiff( $diff );
        if ( $diff === [] || $goal_id <= 0 ) return;

        $author = (int) get_current_user_id();
        $repo   = new ThreadMessagesRepository();
        $key    = self::coalesceKey( $goal_id, $author );

        $open    = get_transient( $key );
        $open_id = 0;
        if ( is_array( $open ) && isset( $open['id'] ) && isset( $open['diff'] ) && is_array( $open['diff'] ) ) {
            $open_id = (int) $open['id'];
            // Keep the value the run started from; take the newest target.
            $diff = self::mergeDiff( self::normaliseDiff( $open['diff'] ), $diff );
        }

        if ( $diff === [] ) {
            // Everything this run touched is back where it started.
            if ( $open_id > 0 ) $repo->deleteSystemMessage( $open_id );
            delete_transient( $key );
            return;
        }

        $body = self::renderDiff( $diff );
        if ( $body === '' ) return;

        if ( $open_id > 0 && $repo->updateSystemBody( $open_id, $body ) ) {
            $message_id = $open_id;
        } else {
            $message_id = $repo->insert( [
                'thread_type'    => 'goal',
                'thread_id'      => $goal_id,
                'author_user_id' => $author,
                'body'           => $body,
                'visibility'     => ThreadVisibility::PUBLIC_LEVEL,
                'is_system'      => 1,
            ] );
        }
        if ( $message_id <= 0 ) return;

        set_transient(
            $key,
            [ 'id' => $message_id, 'diff' => $diff ],
            self::COALESCE_WINDOW_SECONDS
        );
    }

    private static function coalesceKey( int $goal_id, int $author ): string {
        return 'tt_goal_change_msg_' . $goal_id . '_' . $author;
    }

    /**
     * Drop anything that isn't a recognised material field with both
     * ends present, and normalise the two ends so `===` is meaningful.
     *
     * @param  array<mixed> $diff
     * @return array<string,array{from:mixed,to:mixed}>
     */
    private static function normaliseDiff( array $diff ): array {
        $out = [];
        foreach ( [ 'title', 'due_date', 'progress_pct' ] as $field ) {
            if ( ! isset( $diff[ $field ] ) || ! is_array( $diff[ $field ] ) ) continue;
            $entry = $diff[ $field ];
            if ( ! array_key_exists( 'from', $entry ) || ! array_key_exists( 'to', $entry ) ) continue;

            if ( $field === 'progress_pct' ) {
                $from = ( $entry['from'] === null || $entry['from'] === '' ) ? null : (int) $entry['from'];
                $to   = ( $entry['to'] === null || $entry['to'] === '' ) ? null : (int) $entry['to'];
            } else {
                $from = is_scalar( $entry['from'] ) ? (string) $entry['from'] : '';
                $to   = is_scalar( $entry['to'] ) ? (string) $entry['to'] : '';
            }
            if ( $from === $to ) continue;
            $out[ $field ] = [ 'from' => $from, 'to' => $to ];
        }
        return $out;
    }

    /**
     * @param  array<string,array{from:mixed,to:mixed}> $open
     * @param  array<string,array{from:mixed,to:mixed}> $fresh
     * @return array<string,array{from:mixed,to:mixed}>
     */
    private static function mergeDiff( array $open, array $fresh ): array {
        $merged = $open;
        foreach ( $fresh as $field => $entry ) {
            $merged[ $field ] = [
                'from' => array_key_exists( $field, $open ) ? $open[ $field ]['from'] : $entry['from'],
                'to'   => $entry['to'],
            ];
        }
        return array_filter(
            $merged,
            static fn( array $e ): bool => $e['from'] !== $e['to']
        );
    }

    /**
     * Terse, one line per field, matching "Goal created: …" and
     * "Status changed to: …".
     *
     * @param array<string,array{from:mixed,to:mixed}> $diff
     */
    private static function renderDiff( array $diff ): string {
        $lines = [];

        $title = $diff['title']['to'] ?? null;
        if ( is_string( $title ) ) {
            $lines[] = sprintf(
                /* translators: %s is the goal's new title */
                __( 'Title changed to: %s', 'talenttrack' ),
                $title
            );
        }

        if ( isset( $diff['due_date'] ) ) {
            $due = $diff['due_date']['to'];
            $due = is_string( $due ) ? $due : '';
            $lines[] = $due === ''
                ? __( 'Target date removed', 'talenttrack' )
                : sprintf(
                    /* translators: %s is the goal's new target date */
                    __( 'Target date changed to: %s', 'talenttrack' ),
                    self::formatDate( $due )
                );
        }

        if ( isset( $diff['progress_pct'] ) ) {
            $pct = $diff['progress_pct']['to'];
            $lines[] = is_int( $pct )
                ? sprintf(
                    /* translators: %s is the goal's new progress, e.g. "60%" */
                    __( 'Progress changed to: %s', 'talenttrack' ),
                    $pct . '%'
                )
                : __( 'Progress removed', 'talenttrack' );
        }

        return implode( '<br />', array_map( 'esc_html', $lines ) );
    }

    /** Render a stored `Y-m-d` in the site's own date format. */
    private static function formatDate( string $date ): string {
        $dt = \DateTimeImmutable::createFromFormat( 'Y-m-d', substr( $date, 0, 10 ), wp_timezone() );
        if ( $dt === false ) return $date;
        // Midday, so a timezone offset can't drag the label onto the
        // day before.
        return wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $dt->setTime( 12, 0 )->getTimestamp() );
    }

    private static function countMessages( int $goal_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_thread_messages';
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE thread_type = %s AND thread_id = %d",
            'goal', $goal_id
        ) );
    }
}
