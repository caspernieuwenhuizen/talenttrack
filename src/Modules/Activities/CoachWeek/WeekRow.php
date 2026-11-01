<?php
namespace TT\Modules\Activities\CoachWeek;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\ActivityStatusKey;
use TT\Domain\Vocabularies\Lookups\ActivityTypeKey;

/**
 * The facts about one activity that every block reads: what it is, when it
 * falls against today, and whether it is already done.
 */
final class WeekRow {

    public const WHEN_PAST     = 'past';
    public const WHEN_TODAY    = 'today';
    public const WHEN_UPCOMING = 'upcoming';

    /** The types with no register to fill in, as `ActivityRegisterProgress` reads them. */
    private const NO_REGISTER_TYPES = [ ActivityTypeKey::MEETING, ActivityTypeKey::OTHER ];

    public readonly int $activityId;
    public readonly int $teamId;
    public readonly string $date;
    public readonly string $when;
    public readonly string $type;
    public readonly bool $isMatch;
    /** A match whose status is completed: done whatever day it falls on (#4486). */
    public readonly bool $done;
    /** The type keeps an attendance register. */
    public readonly bool $register;

    /** @param array<string, mixed> $activity the repository row */
    public function __construct( public readonly array $activity, string $today ) {
        $this->activityId = (int) ( $activity['id'] ?? 0 );
        $this->teamId     = (int) ( $activity['team_id'] ?? 0 );
        $this->date       = substr( (string) ( $activity['session_date'] ?? '' ), 0, 10 );
        $this->type       = strtolower( trim( (string) ( $activity['activity_type_key'] ?? '' ) ) );
        $this->isMatch    = ActivityTypeKey::isMatchLike( $this->type );
        $this->when       = $this->date < $today ? self::WHEN_PAST : ( $this->date === $today ? self::WHEN_TODAY : self::WHEN_UPCOMING );
        $this->done       = $this->isMatch && self::isCompleted( $activity );
        $this->register   = ! in_array( $this->type, self::NO_REGISTER_TYPES, true );
    }

    /** Past, or a completed match: the row reads as a record, not as work ahead. */
    public function isDone(): bool {
        return $this->when === self::WHEN_PAST || $this->done;
    }

    /** A match still to play: today or later and not completed. */
    public function isOpenMatch(): bool {
        return $this->isMatch && ! $this->isDone();
    }

    /**
     * The activity's status is completed, compared case-insensitively as
     * `Infrastructure\Query\ActivityLifecycle::completedClause()` does.
     *
     * @param array<string, mixed> $activity
     */
    public static function isCompleted( array $activity ): bool {
        return strtolower( trim( (string) ( $activity['activity_status_key'] ?? '' ) ) ) === ActivityStatusKey::COMPLETED;
    }
}
