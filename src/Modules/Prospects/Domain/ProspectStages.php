<?php
namespace TT\Modules\Prospects\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Prospects\ProspectScope;
use TT\Modules\Prospects\Repositories\ProspectConsentRequestsRepository;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * ProspectStages (#4447) — the onboarding pipeline as a viewer may see it:
 * every visible prospect placed in one stage column, with the card each
 * column shows.
 *
 * The pipeline board, the prospect record page and `GET prospects/{id}`
 * (through `ProspectRecord::stage()`) all read from here, so every surface
 * answers "where is this prospect" with one rule. The rule for which column
 * a prospect is in is `ProspectStageClassifier`; this class runs the query
 * it classifies and builds the cards.
 */
final class ProspectStages {

    /**
     * Resolve every visible (non-archived) prospect into exactly one
     * stage. Stage rules are mutually exclusive — a prospect is either
     * Joined, Team offer, Trial group, Test training, Invited, or
     * still in the Prospects column (drafted but not yet handed to
     * the HoD).
     *
     * #3160 — the row set is narrowed to the viewer's scope in the WHERE,
     * via `ProspectScope`: a global `prospects` read (scout, Head of
     * Development, Academy Admin) sees the whole funnel; a team-scoped head
     * coach sees their own age groups, anyone promoted into their squads,
     * and their own discoveries. Narrowing in SQL rather than in PHP is
     * deliberate — the KPI counts are derived from this same row set, so a
     * post-filter would leave them club-wide while the columns narrowed.
     *
     * `$only_prospect_id` narrows the same query to one prospect, for the
     * prospect's record page: its stage and next action come from the rule
     * the board uses, without reading the whole funnel.
     *
     * @return array<int, array{key:string,label:string,count:int,cards:array<int, array<string,mixed>>}>
     */
    public static function computeStages( int $user_id, int $only_prospect_id = 0 ): array {
        global $wpdb;
        $club_id = CurrentClub::id();
        $prospects = $wpdb->prefix . 'tt_prospects';
        $tasks     = $wpdb->prefix . 'tt_workflow_tasks';
        $players   = $wpdb->prefix . 'tt_players';

        // Pull every visible prospect with its most-relevant open task
        // (one row per prospect). MAX(CASE WHEN…) collapses parallel
        // open tasks across templates into one column per template so
        // we can decide stage in PHP without a second query.
        // v3.110.84: also LEFT JOIN tt_players to expose player_status
        // so the classifier can distinguish Trial group (player still
        // at status='trial') from Joined (player graduated).
        // #3160 — the viewer's visibility scope, resolved in one place and
        // applied in the WHERE rather than after the fact. Filtering in PHP
        // would leave the KPI counts above the board club-wide while the
        // columns below it narrowed, which is worse than either.
        $where_scope = ProspectScope::sqlClause( $user_id, 'p' );

        // #4268 — "consent pending" from the consent log: an awaiting
        // request and no agreed one. The dashboard's pipeline strip selects
        // the same condition (#4403).
        $awaiting_consent_sql = ProspectConsentRequestsRepository::awaitingConsentSql( 'p' );

        // An int cast, so the clause carries no caller input.
        $only_one = $only_prospect_id > 0 ? 'AND p.id = ' . (int) $only_prospect_id : '';

        $sql = "
            SELECT
                p.id                       AS id,
                p.first_name               AS first_name,
                p.last_name                AS last_name,
                p.date_of_birth            AS date_of_birth,
                p.current_club             AS current_club,
                p.discovered_at            AS discovered_at,
                p.discovered_at_event      AS discovered_at_event,
                p.promoted_to_player_id    AS promoted_to_player_id,
                p.promoted_to_trial_case_id AS promoted_to_trial_case_id,
                p.created_at               AS created_at,
                MAX(pl.status)             AS player_status,
                MAX(CASE WHEN wt.template_key = 'log_prospect'                 AND wt.status IN ('open','in_progress','overdue') THEN wt.id ELSE NULL END) AS open_log,
                MAX(CASE WHEN wt.template_key = 'request_consent'              AND wt.status IN ('open','in_progress','overdue') THEN wt.id ELSE NULL END) AS open_consent,
                MAX( {$awaiting_consent_sql} ) AS awaiting_consent,
                MAX(CASE WHEN wt.template_key = 'invite_to_test_training'      AND wt.status IN ('open','in_progress','overdue') THEN wt.id ELSE NULL END) AS open_invite,
                MAX(CASE WHEN wt.template_key = 'confirm_test_training'        AND wt.status IN ('open','in_progress','overdue') THEN wt.id ELSE NULL END) AS open_confirm,
                MAX(CASE WHEN wt.template_key = 'record_test_training_outcome' AND wt.status IN ('open','in_progress','overdue') THEN wt.id ELSE NULL END) AS open_outcome,
                MAX(CASE WHEN wt.template_key = 'await_team_offer_decision'    AND wt.status IN ('open','in_progress','overdue') THEN wt.id ELSE NULL END) AS open_offer,
                MAX(CASE WHEN wt.template_key = 'invite_to_test_training'      AND wt.status = 'completed' THEN 1 ELSE 0 END) AS done_invite,
                MAX(CASE WHEN wt.template_key = 'confirm_test_training'        AND wt.status = 'completed' THEN 1 ELSE 0 END) AS done_confirm,
                MAX(CASE WHEN wt.template_key = 'record_test_training_outcome' AND wt.status = 'completed' THEN 1 ELSE 0 END) AS done_outcome,
                MIN(CASE WHEN wt.status IN ('open','in_progress','overdue') THEN wt.due_at ELSE NULL END) AS soonest_due_at
            FROM {$prospects} p
            LEFT JOIN {$tasks}   wt ON wt.prospect_id = p.id AND wt.club_id = %d
            LEFT JOIN {$players} pl ON pl.id = p.promoted_to_player_id AND pl.club_id = %d
            WHERE p.club_id = %d
              AND p.archived_at IS NULL
              {$where_scope}
              {$only_one}
            GROUP BY p.id
            ORDER BY p.discovered_at DESC, p.id DESC
        ";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $club_id, $club_id, $club_id ) );
        if ( ! is_array( $rows ) ) $rows = [];

        $today = time();
        $joined_cutoff = $today - 90 * DAY_IN_SECONDS;
        $stale_cutoff  = $today - 30 * DAY_IN_SECONDS;

        $stages_init = [
            'prospects' => [ 'key' => 'prospects', 'label' => __( 'Prospects',     'talenttrack' ), 'cards' => [] ],
            // #3812 — between Prospects and Invited: the academy has asked
            // the child's own club to pass a consent request to the family
            // and is waiting for an answer.
            'consent'   => [ 'key' => 'consent',   'label' => __( 'Consent requested', 'talenttrack' ), 'cards' => [] ],
            'invited'   => [ 'key' => 'invited',   'label' => __( 'Invited',       'talenttrack' ), 'cards' => [] ],
            'test'      => [ 'key' => 'test',      'label' => __( 'Test training', 'talenttrack' ), 'cards' => [] ],
            'trial'     => [ 'key' => 'trial',     'label' => __( 'Trial group',   'talenttrack' ), 'cards' => [] ],
            'offer'     => [ 'key' => 'offer',     'label' => __( 'Team offer',    'talenttrack' ), 'cards' => [] ],
            'joined'    => [ 'key' => 'joined',    'label' => __( 'Joined',        'talenttrack' ), 'cards' => [] ],
        ];

        foreach ( $rows as $row ) {
            $stage = ProspectStageClassifier::classify( $row, $joined_cutoff );
            if ( $stage === null ) continue; // not visible (e.g. promoted >90d ago)
            $stages_init[ $stage ]['cards'][] = self::buildCard( $row, $stage, $stale_cutoff );
        }

        // Reduce to indexed list with counts. Spelled out field by field
        // rather than spreading `$s`: writing into `$stages_init[$stage]`
        // above, where `$stage` is a union of the stage keys, leaves the
        // analyser unable to prove `key` and `label` are still there, and
        // the declared return type stops being provable the moment a
        // seventh stage joins the six.
        $out = [];
        foreach ( $stages_init as $s ) {
            $cards = $s['cards'];
            $out[] = [
                'key'   => (string) ( $s['key'] ?? '' ),
                'label' => (string) ( $s['label'] ?? '' ),
                'count' => count( $cards ),
                'cards' => $cards,
            ];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function buildCard( object $row, string $stage, int $stale_cutoff ): array {
        $first   = (string) ( $row->first_name ?? '' );
        $last    = (string) ( $row->last_name  ?? '' );
        $club    = (string) ( $row->current_club ?? '' );
        $dob     = (string) ( $row->date_of_birth ?? '' );
        $event   = (string) ( $row->discovered_at_event ?? '' );

        $sub_parts = [];
        if ( $club !== '' ) $sub_parts[] = $club;
        if ( $dob !== '' ) {
            // v3.110.99 — show birth year, not derived age. Operator
            // feedback: a scout scans for "the '08 striker" or "the
            // '07 kid", not for age. Year is also stable; age would
            // flip mid-season and confuse the kanban scan.
            $year = self::birthYearFromDob( $dob );
            if ( $year !== '' ) $sub_parts[] = sprintf( /* translators: %s: 4-digit birth year. */ __( 'born %s', 'talenttrack' ), $year );
        }

        $context_line = self::contextLine( $row, $stage );

        $stale = false;
        $due = (string) ( $row->soonest_due_at ?? '' );
        if ( $due !== '' ) {
            $due_ts = strtotime( $due );
            if ( $due_ts !== false && $due_ts < $stale_cutoff ) $stale = true;
        }

        return [
            'id'           => (int) ( $row->id ?? 0 ),
            'name'         => trim( $first . ' ' . $last ),
            'initials'     => self::initials( $first, $last ),
            'sub_label'    => implode( ' · ', $sub_parts ),
            'context_line' => $context_line,
            'stale'        => $stale,
            'joined'       => $stage === 'joined',
            'url'          => self::cardUrl( $row, $stage ),
        ];
    }

    /**
     * Two-letter initials for the card avatar. Falls back to the first
     * two letters of whichever name part exists so a single-name
     * prospect still renders a non-empty avatar.
     */
    private static function initials( string $first, string $last ): string {
        $f = trim( $first );
        $l = trim( $last );
        if ( $f !== '' && $l !== '' ) {
            return strtoupper( mb_substr( $f, 0, 1 ) . mb_substr( $l, 0, 1 ) );
        }
        $one = $f !== '' ? $f : $l;
        return strtoupper( mb_substr( $one, 0, 2 ) );
    }

    private static function contextLine( object $row, string $stage ): string {
        $event = (string) ( $row->discovered_at_event ?? '' );
        switch ( $stage ) {
            case 'prospects':
                // v3.110.81 — with the new "invite-completed = Invited"
                // semantics, the Prospects column now ALSO holds
                // prospects whose invite task is open (email not yet
                // sent). Surface that distinction in the context line
                // so the HoD knows what's blocked on them.
                if ( ! empty( $row->open_invite ) ) {
                    return __( 'Awaiting HoD to send the invite', 'talenttrack' );
                }
                return $event !== ''
                    ? sprintf( /* translators: %s: discovery event / match label. */ __( 'Discovered: %s', 'talenttrack' ), $event )
                    : __( 'Drafted, not yet handed to HoD', 'talenttrack' );
            case 'consent':
                // #3812 — the academy has asked the child's club and is
                // waiting. Nothing else can move until the family answers.
                return __( 'Consent request out, awaiting the family', 'talenttrack' );
            case 'invited':
                // The email has gone out; awaiting parent confirmation.
                return __( 'Invitation sent, awaiting parent', 'talenttrack' );
            case 'test':
                return __( 'Awaiting outcome', 'talenttrack' );
            case 'trial':
                return __( 'In trial group', 'talenttrack' );
            case 'offer':
                return __( 'Team offer pending', 'talenttrack' );
            case 'joined':
                return __( 'Promoted to academy', 'talenttrack' );
            default:
                return '';
        }
    }

    /**
     * Card click target. Routes to whichever surface is most useful for
     * the prospect's current stage — the open task form when there is
     * one, the player profile when joined, or nothing (a static card)
     * when the prospect has no actionable surface yet.
     *
     * #4477 — the URL is the plain target. The views that render a card
     * or the record's next action append `tt_back` (CLAUDE.md §5), so the
     * same prospect gives every consumer the same URL whatever page is
     * being served.
     */
    private static function cardUrl( object $row, string $stage ): string {
        $task_id = 0;
        switch ( $stage ) {
            case 'offer':   $task_id = (int) ( $row->open_offer   ?? 0 ); break;
            case 'test':    $task_id = (int) ( $row->open_outcome ?? 0 ); break;
            case 'invited':
                // After v3.110.81 a prospect lands in Invited because
                // (a) the invite task was completed (no open_invite to
                // link to), OR (b) the confirm task is open. Deep-link
                // to confirm first; fall back to invite for the legacy
                // case where the chain stopped after invite without
                // spawning confirm.
                $task_id = (int) ( $row->open_confirm ?? 0 );
                if ( $task_id === 0 ) $task_id = (int) ( $row->open_invite ?? 0 );
                break;
            case 'prospects':
                $task_id = (int) ( $row->open_log ?? 0 );
                if ( $task_id === 0 ) $task_id = (int) ( $row->open_invite ?? 0 );
                break;
            // #3812 — the open consent request is the card's next action:
            // the scout opens it to record what the club came back with.
            case 'consent':
                $task_id = (int) ( $row->open_consent ?? 0 );
                // #4268 — a prospect held here by the consent log alone has
                // no task to open; their focus view shows the log.
                if ( $task_id === 0 ) {
                    return self::prospectFocusUrl( (int) ( $row->id ?? 0 ) );
                }
                break;
            case 'joined':
                $player_id = (int) ( $row->promoted_to_player_id ?? 0 );
                if ( $player_id > 0 ) {
                    return add_query_arg(
                        [ 'tt_view' => 'players', 'id' => $player_id ],
                        RecordLink::dashboardUrl()
                    );
                }
                return self::prospectFocusUrl( (int) ( $row->id ?? 0 ) );
            case 'trial':
                $case_id = (int) ( $row->promoted_to_trial_case_id ?? 0 );
                if ( $case_id > 0 ) {
                    return add_query_arg(
                        [ 'tt_view' => 'trial-case', 'id' => $case_id ],
                        RecordLink::dashboardUrl()
                    );
                }
                return self::prospectFocusUrl( (int) ( $row->id ?? 0 ) );
        }
        if ( $task_id > 0 ) {
            return add_query_arg(
                [ 'tt_view' => 'my-tasks', 'task_id' => $task_id ],
                RecordLink::dashboardUrl()
            );
        }
        // #1763 — no "next action": fall back to focusing the prospect on
        // the board so a card is never a dead end.
        return self::prospectFocusUrl( (int) ( $row->id ?? 0 ) );
    }

    /**
     * #1763 — URL that focuses a prospect on the pipeline board
     * (`?tt_view=onboarding-pipeline&prospect_id=N`). Used as the card
     * fallback and by dashboard "open prospect" links.
     */
    public static function prospectFocusUrl( int $prospect_id ): string {
        if ( $prospect_id <= 0 ) return '';
        return add_query_arg(
            [ 'tt_view' => 'onboarding-pipeline', 'prospect_id' => $prospect_id ],
            RecordLink::dashboardUrl()
        );
    }

    private static function birthYearFromDob( string $dob ): string {
        $ts = strtotime( $dob );
        if ( $ts === false ) return '';
        $year = (int) date( 'Y', $ts );
        // Guard against obviously-bad inputs (year 0 / pre-1900 / future).
        if ( $year < 1900 || $year > (int) date( 'Y' ) ) return '';
        return (string) $year;
    }
}
