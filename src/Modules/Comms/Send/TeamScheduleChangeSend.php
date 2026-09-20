<?php
namespace TT\Modules\Comms\Send;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Activities\Repositories\ActivitiesRepository;
use TT\Modules\Activities\Services\ActivityTimeWindow;
use TT\Modules\Comms\Dispatch\CommsDispatcher;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\Recipient\TeamStaffRecipientResolver;
use TT\Modules\Comms\Templates\ScheduleChangeFromSpondTemplate;
use TT\Modules\Comms\Templates\TeamScheduleDigestTemplate;
use TT\Shared\Dates\TTDate;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * TeamScheduleChangeSend (#3811) — the team's staff hear that the calendar
 * moved.
 *
 * `schedule_change_from_spond` shipped with a template, an audience row, a
 * catalogue registration and a user-facing opt-out toggle, and no sender at
 * all: `MessageType` said so in its own docblock. A preferences screen
 * offered a switch for a message nobody could ever receive. This is the
 * missing half, and it is not Spond-specific — an activity moved by hand in
 * the product is the same event to the person who has to tell the parents.
 *
 * TWO SPEEDS, DELIBERATELY
 *
 * A message per edit would punish the role it is meant to help. A coach who
 * fixes six kick-off times in one sitting would send six e-mails to the team
 * manager, who would stop reading them. So changes are batched into one
 * daily digest — {@see \TT\Modules\Comms\Cron\CommsScheduledCron} runs it —
 * and only a change to an activity starting inside {@see self::IMMEDIATE_HOURS}
 * sends on the spot, because at that range a digest tomorrow morning arrives
 * after the session.
 *
 * The two never overlap and nothing falls between them: the digest's window
 * reads each row's own change timestamp and skips rows that were inside the
 * immediate window *when they changed*, which is exactly the set this class
 * already sent.
 *
 * NOT THE FAMILY
 *
 * Parents and players are told about a cancellation by
 * {@see TrainingCancelledSend}, from the same underlying event. This one is
 * addressed to staff, carries no player's name, and says nothing about
 * anyone's availability or health.
 */
final class TeamScheduleChangeSend {

    /**
     * Inside this many hours before kick-off, a change sends immediately.
     *
     * Two days is the point at which "you will read it tomorrow morning"
     * stops being true enough: a Thursday evening session changed on
     * Wednesday afternoon still has a digest before it, one changed on
     * Thursday lunchtime does not.
     */
    public const IMMEDIATE_HOURS = 48;

    /**
     * Columns whose change is worth telling the team's staff about.
     *
     * An edit that only touches `notes` or an internal flag is not a
     * calendar change, and treating every write as one would make the
     * digest noise. `team_id` is here because moving an activity to another
     * team is a change to both teams' calendars.
     *
     * @var list<string>
     */
    private const WATCHED_COLUMNS = [
        'session_date',
        'start_time',
        'end_time',
        'location',
        'title',
        'team_id',
        'opponent',
        'activity_status_key',
    ];

    public static function init(): void {
        add_action( 'tt_activity_saved', [ __CLASS__, 'onSaved' ], 10, 2 );
        add_action( 'tt_activity_cancelled', [ __CLASS__, 'onCancelled' ], 10, 1 );
    }

    /**
     * Action-hook entry point for a create or an edit.
     *
     * @param array<string, mixed> $data Columns as written.
     */
    public static function onSaved( int $activity_id, array $data = [] ): void {
        if ( $activity_id <= 0 ) return;
        if ( ! self::touchesTheCalendar( $data ) ) return;

        self::sendIfImminent( $activity_id, false );
    }

    /** Action-hook entry point for a cancellation. */
    public static function onCancelled( int $activity_id ): void {
        if ( $activity_id <= 0 ) return;

        self::sendIfImminent( $activity_id, true );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function touchesTheCalendar( array $data ): bool {
        foreach ( self::WATCHED_COLUMNS as $column ) {
            if ( array_key_exists( $column, $data ) ) return true;
        }
        return false;
    }

    /**
     * @return CommsResult[] Empty when the activity is far enough out that
     *                       the daily digest will carry it.
     */
    private static function sendIfImminent( int $activity_id, bool $cancelled ): array {
        $activity = ( new ActivitiesRepository() )->findByIdIncludingArchived( $activity_id );
        if ( $activity === null ) return [];

        $team_id = (int) ( $activity->team_id ?? 0 );
        if ( $team_id <= 0 ) return [];

        $starts_at = self::startsAt( $activity );
        if ( $starts_at === null ) return [];
        if ( ! self::isImminent( $starts_at, time() ) ) return [];

        return $cancelled
            ? self::dispatchDigest( $team_id, (string) ( $activity->team_name ?? '' ), [ self::line( $activity, true ) ], (int) ( $activity->club_id ?? 0 ) )
            : self::dispatchSingleChange( $activity, $team_id );
    }

    /**
     * Whether a session starting at `$starts_at` is close enough that a
     * digest tomorrow would arrive too late.
     *
     * A session that has already started is not imminent — it is past, and
     * nobody needs telling that yesterday moved.
     */
    public static function isImminent( int $starts_at, int $now ): bool {
        if ( $starts_at <= $now ) return false;
        return ( $starts_at - $now ) <= self::IMMEDIATE_HOURS * HOUR_IN_SECONDS;
    }

    /**
     * The activity's start as a local timestamp, or null when it has no
     * usable date. A session with no clock time is treated as starting at
     * midnight, which is the earliest it could.
     */
    public static function startsAt( object $activity ): ?int {
        $date = trim( (string) ( $activity->session_date ?? '' ) );
        if ( $date === '' || $date === '0000-00-00' ) return null;

        $clock = ActivityTimeWindow::clock( (string) ( $activity->start_time ?? '' ) );
        if ( $clock === '' ) $clock = '00:00';

        $stamp = strtotime( $date . ' ' . $clock );

        return $stamp === false ? null : $stamp;
    }

    /**
     * One activity, changed inside the window — the shipped
     * `schedule_change_from_spond` copy, which this send finally gives a
     * dispatcher.
     *
     * @return CommsResult[]
     */
    private static function dispatchSingleChange( object $activity, int $team_id ): array {
        $recipients = ( new TeamStaffRecipientResolver() )->forTeam( $team_id );
        if ( $recipients === [] ) return [];

        $stamp = self::startsAt( $activity );

        return CommsDispatcher::dispatchSync(
            ( new ScheduleChangeFromSpondTemplate() )->key(),
            [
                'activity_title' => (string) ( $activity->title ?? '' ),
                // The row's previous values are not recorded anywhere, so
                // the `old_*` tokens stay empty rather than inventing a
                // before-state. The shipped copy does not read them.
                'old_date'       => '',
                'old_time'       => '',
                'old_location'   => '',
                'new_date'       => $stamp !== null ? TTDate::date( $stamp ) : '',
                'new_time'       => ActivityTimeWindow::clock( (string) ( $activity->start_time ?? '' ) ),
                'new_location'   => (string) ( $activity->location ?? '' ),
                'deep_link'      => self::activityLink( (int) ( $activity->id ?? 0 ) ),
            ],
            $recipients,
            [
                'message_type'   => MessageType::SCHEDULE_CHANGE_FROM_SPOND,
                'sender_user_id' => 0,
                'urgent'         => true,
                'subject_type'   => 'activity',
                'subject_id'     => (int) ( $activity->id ?? 0 ),
            ]
        );
    }

    /**
     * The roll-up, and the single-cancellation case.
     *
     * @param list<string> $lines Already-composed, already-escaped-free
     *                            plain-text lines, newest change first.
     * @return CommsResult[]
     */
    public static function dispatchDigest( int $team_id, string $team_name, array $lines, int $club_id = 0 ): array {
        if ( $team_id <= 0 || $lines === [] ) return [];

        $recipients = ( new TeamStaffRecipientResolver() )->forTeam( $team_id );
        if ( $recipients === [] ) return [];

        $options = [
            'message_type'   => MessageType::SCHEDULE_CHANGE_FROM_SPOND,
            'sender_user_id' => 0,
            'subject_type'   => 'team',
            'subject_id'     => $team_id,
        ];
        if ( $club_id > 0 ) $options['club_id'] = $club_id;

        return CommsDispatcher::dispatchSync(
            TeamScheduleDigestTemplate::KEY,
            [
                'team_name'    => $team_name,
                'change_count' => count( $lines ),
                'change_list'  => implode( "\n", $lines ),
                'deep_link'    => self::calendarLink( $team_id ),
            ],
            $recipients,
            $options
        );
    }

    /**
     * One line of the digest: what the activity is now, and whether it is
     * new, moved or off.
     *
     * Deliberately states the current shape rather than a diff. Nothing
     * records what the row said before it changed, and a line that claimed
     * to know would be worse than one that does not.
     */
    public static function line( object $activity, bool $cancelled = false, bool $created = false ): string {
        $stamp = self::startsAt( $activity );
        $when  = $stamp !== null ? TTDate::date( $stamp ) : (string) ( $activity->session_date ?? '' );
        $clock = ActivityTimeWindow::format(
            (string) ( $activity->start_time ?? '' ),
            (string) ( $activity->end_time ?? '' )
        );
        if ( $clock !== '' ) $when .= ' ' . $clock;

        $where = trim( (string) ( $activity->location ?? '' ) );
        $title = trim( (string) ( $activity->title ?? '' ) );
        if ( $title === '' ) $title = __( 'Untitled activity', 'talenttrack' );

        // Punctuation, not copy. Wrapping "%1$s — %2$s" in `__()` would add
        // a msgid that says nothing a translator can act on, and the one
        // already in the catalogue carries somebody else's sense of it.
        $subject = $title . ' — ' . $when;
        if ( $where !== '' ) $subject .= ', ' . $where;

        if ( $cancelled ) {
            /* translators: %s: activity name, date and location */
            return sprintf( __( 'Cancelled: %s', 'talenttrack' ), $subject );
        }
        if ( $created ) {
            /* translators: %s: activity name, date and location */
            return sprintf( __( 'Added: %s', 'talenttrack' ), $subject );
        }

        /* translators: %s: activity name, date and location */
        return sprintf( __( 'Changed: %s', 'talenttrack' ), $subject );
    }

    private static function activityLink( int $activity_id ): string {
        return $activity_id > 0 ? RecordLink::detailUrlFor( 'activities', $activity_id ) : '';
    }

    private static function calendarLink( int $team_id ): string {
        return add_query_arg(
            [ 'tt_view' => 'activities', 'team_id' => $team_id ],
            RecordLink::dashboardUrl()
        );
    }
}
