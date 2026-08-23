<?php
namespace TT\Modules\Media;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MediaEntityType (#2590, epic #2589) — what a media item can be attached to.
 *
 * Deliberately a closed set. `tt_media_links.entity_type` is polymorphic,
 * and a polymorphic column with no vocabulary in front of it is how you
 * end up with `player`, `players` and `Player` in the same table. Every
 * write path validates through `isValid()`.
 *
 * Evaluation and PDP are the obvious next entries; they are out of scope
 * for the first pass and are added here when their surfaces are.
 *
 * `course_submission` (#2648) is the first entry whose subject is not a
 * player and has no team behind it — it is a coach's own coursework. That
 * matters in two places, and both had to be taught about it rather than
 * inheriting a default:
 *
 *   - `MediaVisibilityService::resolveLinkGrant()` falls through to the
 *     player branch on an unknown type, which would have checked a
 *     submission id against player scope. It gets an explicit case.
 *   - `MediaAttachmentPolicy` restricts it to documents, so a photo that
 *     might carry a minor never enters a lane that sits outside the
 *     player-consent model.
 */
final class MediaEntityType {

    public const PLAYER            = 'player';
    public const TEAM              = 'team';
    public const ACTIVITY          = 'activity';
    public const COURSE_SUBMISSION = 'course_submission';

    /** @return list<string> */
    public static function all(): array {
        return [ self::PLAYER, self::TEAM, self::ACTIVITY, self::COURSE_SUBMISSION ];
    }

    public static function isValid( string $type ): bool {
        return in_array( $type, self::all(), true );
    }

    /** Table holding the rows this type points at, without the prefix. */
    public static function tableFor( string $type ): string {
        switch ( $type ) {
            case self::PLAYER:
                return 'tt_players';
            case self::TEAM:
                return 'tt_teams';
            case self::ACTIVITY:
                return 'tt_activities';
            case self::COURSE_SUBMISSION:
                return 'tt_course_submissions';
            default:
                return '';
        }
    }
}
