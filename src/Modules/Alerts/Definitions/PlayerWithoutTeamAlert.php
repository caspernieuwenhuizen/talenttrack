<?php
namespace TT\Modules\Alerts\Definitions;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * PlayerWithoutTeamAlert (#2636, epic #2629) — an active player who belongs
 * to no team.
 *
 * Which player question does this answer? *Where is this player now?* —
 * and the honest answer is that the system does not know. A player with no
 * team is invisible almost everywhere: no attendance, no minutes, no
 * evaluation-coverage row, no head coach receiving any of the other alerts
 * in this catalogue about them. This is the definition that stops that
 * silence being permanent.
 *
 * It also explains a gap in the rest of the wave. Several definitions here
 * require `team_id > 0`, because their recipient is the team's head coach
 * and there is nobody to tell otherwise. Those players are not being
 * ignored; they are this definition's subject.
 *
 * ## It is player data, and it is gated as player data
 *
 * The occurrence names a minor and says something about their record, so
 * `tt_manage_players` gates it and `player_id` rides along. "Data quality"
 * describes what the condition is about, not how carefully it should be
 * handled.
 *
 * A grace period (`alerts_player_without_team_grace_days`, 7 by default)
 * keeps a player who was added this morning out of it — assigning the squad
 * is often the next step in the same sitting.
 */
final class PlayerWithoutTeamAlert extends AbstractDataQualityAlert {

    public const SUBJECT_TYPE = 'player';

    /** tt_config key: days after the player was added before the alert appears. */
    public const CONFIG_KEY_GRACE_DAYS = 'alerts_player_without_team_grace_days';

    private const DEFAULT_GRACE_DAYS = 7;

    public function key(): string {
        return 'dataquality.player_without_team';
    }

    public function label(): string {
        return __( 'Player has no team', 'talenttrack' );
    }

    public function description(): string {
        return __( 'An active player belongs to no team. They have no attendance, no minutes, no coverage row and no head coach — the system cannot say where they are.', 'talenttrack' );
    }

    public function capRequired(): string {
        return 'tt_manage_players';
    }

    public function subjectType(): string {
        return self::SUBJECT_TYPE;
    }

    protected function titleFor( object $row ): string {
        $name = trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) );
        if ( $name === '' ) $name = __( 'A player', 'talenttrack' );

        return sprintf(
            /* translators: %s: player name */
            __( '%s is active but belongs to no team.', 'talenttrack' ),
            $name
        );
    }

    protected function urlFor( object $row ): string {
        return RecordLink::detailUrlFor( 'players', (int) ( $row->subject_id ?? 0 ) );
    }

    /** @return list<object> */
    protected function rows( AlertContext $context ): array {
        global $wpdb;
        $p     = $wpdb->prefix;
        $grace = $this->threshold( self::CONFIG_KEY_GRACE_DAYS, self::DEFAULT_GRACE_DAYS );

        // `team_id` is `DEFAULT 0`, and removal writes 0 rather than NULL
        // (`TeamsRestController`), but imports have been known to leave NULL
        // — both mean the same thing, so both are matched.
        //
        // The grace is measured from `date_joined` when it is set and from
        // the row's creation otherwise, so a player imported with an old
        // joining date is not given a week's amnesty they do not need.
        $sql = $wpdb->prepare(
            "SELECT p.id AS subject_id, p.id AS player_id, p.first_name, p.last_name
               FROM {$p}tt_players p
              WHERE " . QueryHelpers::clubScopeWhere( 'p' ) . "
                AND p.status = 'active'
                AND p.archived_at IS NULL
                AND p.trashed_at IS NULL
                AND ( p.team_id IS NULL OR p.team_id = 0 )
                AND COALESCE( p.date_joined, DATE(p.created_at) )
                    < DATE_SUB( CURDATE(), INTERVAL %d DAY )"
            . $context->applyScope( self::SUBJECT_TYPE, 'p.id' ) . "
              ORDER BY p.id ASC",
            $grace
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql );
        return is_array( $rows ) ? $rows : [];
    }
}
