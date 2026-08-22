<?php
namespace TT\Modules\Alerts\Definitions;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * PdpNoConversationAlert (#2636, epic #2629) — an open PDP cycle in which
 * no conversation has actually been held.
 *
 * Which player question does this answer? *Where is this player going?* The
 * PDP cycle is the one place the academy commits to sitting down with the
 * player and agreeing that. A cycle with a file, a plan and a set of
 * scheduled conversations — none of which happened — looks complete on
 * every list and has told the player nothing.
 *
 * ## What counts as "logged"
 *
 * A conversation with `conducted_at` set. A scheduled conversation is an
 * intention, not a conversation; counting rows would make the alert
 * unreachable, because `createCycle()` writes the whole cycle's
 * conversations up front. This is the same distinction the PDP coverage
 * report draws (`PdpFilesRepository::coverageForSeason`).
 *
 * ## Only the current season, and only once it is underway
 *
 * Last season's untouched cycle is history, not a gap someone can still
 * close, so the query joins `tt_seasons` on `is_current`. And a cycle only
 * becomes an alert once the delay is real: `alerts_pdp_no_conversation_days`
 * in `tt_config`, defaulting to 45 days after the file was opened. Firing on
 * a file created yesterday would be nagging a coach about work they have not
 * had a chance to do.
 */
final class PdpNoConversationAlert extends AbstractPlayerAlert {

    public const SUBJECT_TYPE = 'pdp_file';

    /** tt_config key: days into the cycle before the alert appears. */
    public const CONFIG_KEY_DAYS = 'alerts_pdp_no_conversation_days';

    private const DEFAULT_DAYS = 45;

    public function key(): string {
        return 'pdp.no_conversation_this_cycle';
    }

    public function module(): string {
        return 'pdp';
    }

    public function label(): string {
        return __( 'No PDP conversation this cycle', 'talenttrack' );
    }

    public function description(): string {
        return __( 'A player\'s PDP file for this season is open but no conversation has actually been held. The file looks complete on every list and the player has been told nothing.', 'talenttrack' );
    }

    /**
     * `tt_edit_pdp` is the flat capability; the per-player half of
     * `PdpAccess::canSeeFile()` is coaching the player's team, which is
     * exactly who this definition resolves as its audience — the file's
     * owner coach and the team's head coach, and nobody else.
     */
    public function capRequired(): string {
        return 'tt_edit_pdp';
    }

    protected function subjectType(): string {
        return self::SUBJECT_TYPE;
    }

    /** Twice the threshold and the cycle is not late, it is not happening. */
    protected function severityFor( object $row ): string {
        $days = $this->threshold( self::CONFIG_KEY_DAYS, self::DEFAULT_DAYS );
        return $this->daysSince( (string) ( $row->cycle_started_at ?? '' ) ) >= ( $days * 2 )
            ? Severity::URGENT
            : Severity::ATTENTION;
    }

    /**
     * The coach who owns the file. The head coach of the player's team is
     * added by the base class.
     *
     * @return list<int>
     */
    protected function extraRecipientsFor( object $row ): array {
        return [ (int) ( $row->owner_coach_id ?? 0 ) ];
    }

    protected function urlFor( object $row ): string {
        return RecordLink::detailUrlFor( 'pdp', $this->subjectIdFor( $row ) );
    }

    protected function titleFor( object $row ): string {
        return sprintf(
            /* translators: %s: player name */
            __( 'No PDP conversation has been held with %s this cycle.', 'talenttrack' ),
            $this->playerName( $row )
        );
    }

    /** @return array<string,mixed> */
    protected function payloadFor( object $row ): array {
        return [
            'season_name'      => (string) ( $row->season_name ?? '' ),
            'cycle_started_at' => (string) ( $row->cycle_started_at ?? '' ),
        ];
    }

    /** @return list<object> */
    protected function rows( AlertContext $context ): array {
        global $wpdb;
        $p    = $wpdb->prefix;
        $days = $this->threshold( self::CONFIG_KEY_DAYS, self::DEFAULT_DAYS );

        // NOT EXISTS rather than a LEFT JOIN + COUNT: it stops at the first
        // conducted conversation instead of aggregating a cycle's worth.
        //
        // `tt_pdp_files` has an archive flag but no recycle-bin columns, so
        // `archived_at` is the whole lifecycle gate here — unlike the player
        // side, which needs both.
        $sql = $wpdb->prepare(
            "SELECT f.id AS subject_id, f.player_id, f.owner_coach_id,
                    f.created_at AS cycle_started_at, s.name AS season_name,
                    p.first_name, p.last_name, p.team_id
               FROM {$p}tt_pdp_files f
         INNER JOIN {$p}tt_seasons s
                 ON s.id = f.season_id AND s.club_id = f.club_id AND s.is_current = 1
         INNER JOIN {$p}tt_players p ON p.id = f.player_id
              WHERE " . QueryHelpers::clubScopeWhere( 'f' ) . "
                AND f.archived_at IS NULL
                AND f.status = 'open'
                AND s.start_date <= CURDATE()
                AND f.created_at < DATE_SUB( NOW(), INTERVAL %d DAY )
                AND NOT EXISTS (
                    SELECT 1 FROM {$p}tt_pdp_conversations c
                     WHERE c.pdp_file_id = f.id
                       AND c.conducted_at IS NOT NULL
                )
                AND " . $this->activePlayerWhere( 'p' )
            . $context->applyScope( self::SUBJECT_TYPE, 'f.id' ) . "
              ORDER BY f.created_at ASC, f.id ASC",
            $days
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql );
        return is_array( $rows ) ? $rows : [];
    }
}
