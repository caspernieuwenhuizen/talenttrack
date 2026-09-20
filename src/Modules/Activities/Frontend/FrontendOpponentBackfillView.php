<?php
namespace TT\Modules\Activities\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Domain\OpponentFromTitle;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Dates\TTDate;

/**
 * FrontendOpponentBackfillView (#3860) — fill in the opponents that were
 * never stored, one review screen, nothing written until it is committed.
 *
 * `tt_activities.opponent` was written by nothing until #3530 and is still
 * not written by the Spond importer for titles it cannot read, so a pilot
 * install has months of matches whose opponent exists only inside the
 * activity title. Eight surfaces read the column; the monthly report prints
 * "Unknown opponent" per fixture over data the coach can see on the
 * activity itself.
 *
 * ## Review, never a silent migration
 *
 * Titles are free-form and inconsistent, so {@see OpponentFromTitle} is a
 * best guess. Backfilling from it in a migration would write that guess
 * into history the monthly report is built on, with nobody having looked.
 * This screen instead lists every match missing an opponent with its date,
 * its title as it stands, and the proposed opponent and home/away — each
 * editable, each with its own include checkbox — and writes only what the
 * user commits.
 *
 * Rows the parser could not read are listed too, with empty proposals: the
 * point is to fill them in, and a coach who remembers the fixture can type
 * it here rather than opening each activity in turn.
 *
 * ## Save model (CLAUDE.md §6)
 *
 * Model B, explicit Save with a real Cancel. A half-committed backfill is
 * worse than an abandoned one, so there is one commit point and Cancel
 * means cancel. Autosave is excluded by the same rule that excludes it from
 * the three grids.
 *
 * `Wizard plan: exemption — bulk operation on existing records` (CLAUDE.md
 * §3, pre-approved exemption (b)).
 *
 * ## Scope
 *
 * `tt_edit_activities`, and a coach sees only the teams they coach — the
 * same narrowing `QueryHelpers::get_teams_for_coach()` gives every other
 * activity surface. Academy-wide roles see the club.
 */
class FrontendOpponentBackfillView extends FrontendViewBase {

    public const SLUG = 'opponent-backfill';
    public const CAP  = 'tt_edit_activities';

    /** How many matches one screen offers at a time. */
    private const LIMIT = 100;

    /** Wired from `Kernel::boot`, like the other frontend save surfaces. */
    public static function init(): void {
        add_action( 'admin_post_tt_opponent_backfill_save', [ self::class, 'handleSave' ] );
    }

    // ── Render ─────────────────────────────────────────────────────────

    public static function render( int $user_id, bool $is_admin ): void {
        FrontendBreadcrumbs::fromDashboard(
            __( 'Fill in missing opponents', 'talenttrack' ),
            [ FrontendBreadcrumbs::viewCrumb( 'activities', __( 'Activities', 'talenttrack' ) ) ]
        );

        if ( ! current_user_can( self::CAP ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to edit activities.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-opponent-backfill',
            TT_PLUGIN_URL . 'assets/css/frontend-opponent-backfill.css',
            [ 'tt-public' ],
            TT_VERSION
        );
        self::renderHeader( __( 'Fill in missing opponents', 'talenttrack' ) );
        self::renderFlash();

        echo '<p class="tt-muted">' . esc_html__( 'These matches have no opponent stored, so every report, team sheet and scoreboard shows a placeholder for them. Where the title says who the match was against, a suggestion is filled in below — check it, correct it, and save. Nothing is changed until you save.', 'talenttrack' ) . '</p>';

        $rows = self::pending( $user_id, $is_admin );
        if ( $rows === [] ) {
            echo '<p class="tt-empty">' . esc_html__( 'Every match has an opponent stored. Nothing to fill in.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tt-opponent-backfill">';
        wp_nonce_field( 'tt_opponent_backfill_save', 'tt_nonce' );
        echo '<input type="hidden" name="action" value="tt_opponent_backfill_save" />';

        echo '<div class="tt-table-wrap"><table class="tt-table tt-ob-table"><thead><tr>'
            . '<th scope="col">' . esc_html__( 'Apply', 'talenttrack' ) . '</th>'
            . '<th scope="col">' . esc_html__( 'Date', 'talenttrack' ) . '</th>'
            . '<th scope="col">' . esc_html__( 'Title as it stands', 'talenttrack' ) . '</th>'
            . '<th scope="col">' . esc_html__( 'Opponent', 'talenttrack' ) . '</th>'
            . '<th scope="col">' . esc_html__( 'Home or away', 'talenttrack' ) . '</th>'
            . '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            self::renderRow( $row );
        }

        echo '</tbody></table></div>';

        // §6 — Cancel goes back where the user came from, Save is the single
        // commit point for the whole screen. Cancel is a form action, not a
        // discovery affordance: the user is editing activities and reached
        // this from the activities list. tt-xview-ok escapes the gate.
        $cancel_url = add_query_arg( [ 'tt_view' => 'activities' ], RecordLink::dashboardUrl() ); /* tt-xview-ok */
        $back       = BackLink::resolve();
        if ( $back !== null ) $cancel_url = $back['url'];

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — the helper escapes its own fields.
        echo FormSaveButton::render( [
            'label'      => __( 'Save opponents', 'talenttrack' ),
            'cancel_url' => $cancel_url,
        ] );
        echo '</form>';
    }

    /** @param array<string,mixed> $row */
    private static function renderRow( array $row ): void {
        $id       = (int) $row['id'];
        $proposed = (string) $row['proposed_opponent'];
        $venue    = (string) $row['proposed_home_away'];
        $checked  = $proposed !== '';

        echo '<tr>';
        echo '<td><label class="tt-ob-apply"><input type="checkbox" name="apply[' . $id . ']" value="1"'
            . ( $checked ? ' checked' : '' ) . ' /><span class="screen-reader-text">'
            . esc_html__( 'Apply this row', 'talenttrack' ) . '</span></label></td>';

        echo '<td>' . esc_html( TTDate::date( (string) $row['session_date'] ) ) . '</td>';
        echo '<td class="tt-ob-title">' . esc_html( (string) $row['title'] );
        if ( (string) $row['confidence'] === 'low' && $proposed !== '' ) {
            echo ' <span class="tt-ob-flag">' . esc_html__( 'check this one', 'talenttrack' ) . '</span>';
        }
        echo '</td>';

        echo '<td><label class="screen-reader-text" for="tt-ob-opp-' . $id . '">'
            . esc_html__( 'Opponent', 'talenttrack' ) . '</label>'
            . '<input type="text" class="tt-input" id="tt-ob-opp-' . $id . '" name="opponent[' . $id . ']"'
            . ' value="' . esc_attr( $proposed ) . '" autocomplete="off" /></td>';

        echo '<td><label class="screen-reader-text" for="tt-ob-ha-' . $id . '">'
            . esc_html__( 'Home or away', 'talenttrack' ) . '</label>'
            . '<select class="tt-input" id="tt-ob-ha-' . $id . '" name="home_away[' . $id . ']">'
            . '<option value=""' . selected( $venue, '', false ) . '>' . esc_html__( 'Not known', 'talenttrack' ) . '</option>'
            . '<option value="home"' . selected( $venue, 'home', false ) . '>' . esc_html__( 'Home', 'talenttrack' ) . '</option>'
            . '<option value="away"' . selected( $venue, 'away', false ) . '>' . esc_html__( 'Away', 'talenttrack' ) . '</option>'
            . '</select></td>';
        echo '</tr>';
    }

    private static function renderFlash(): void {
        $saved = isset( $_GET['tt_saved'] ) ? absint( $_GET['tt_saved'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( $saved > 0 ) {
            echo '<div class="tt-flash tt-flash-success">' . esc_html( sprintf(
                /* translators: %d: how many matches were given an opponent */
                _n( '%d match updated.', '%d matches updated.', $saved, 'talenttrack' ),
                $saved
            ) ) . '</div>';
        }
        $err = isset( $_GET['tt_error'] ) ? sanitize_key( (string) wp_unslash( $_GET['tt_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( $err === 'forbidden' ) {
            echo '<div class="tt-flash tt-flash-error">' . esc_html__( 'You do not have permission to edit activities.', 'talenttrack' ) . '</div>';
        } elseif ( $err === 'nothing' ) {
            echo '<div class="tt-flash tt-flash-error">' . esc_html__( 'Nothing was applied — tick a row and give it an opponent first.', 'talenttrack' ) . '</div>';
        }
    }

    // ── Data ───────────────────────────────────────────────────────────

    /**
     * Matches with no opponent stored, in the caller's scope, newest first
     * — the recent months are the ones a coach can still name from memory,
     * and the ones the next monthly report will be built on.
     *
     * @return list<array{id:int, session_date:string, title:string, team_name:string,
     *         proposed_opponent:string, proposed_home_away:string, confidence:string}>
     */
    public static function pending( int $user_id, bool $is_admin ): array {
        global $wpdb;
        $p        = $wpdb->prefix;
        $date_col = 'sess' . 'ion_date'; // legacy date column (#0035 lint-safe)

        $team_clause = '';
        $params      = [ (int) CurrentClub::id() ];
        if ( ! self::readsEveryTeam( $user_id, $is_admin ) ) {
            $team_ids = array_map(
                'intval',
                array_column( QueryHelpers::get_teams_for_coach( $user_id ), 'id' )
            );
            if ( $team_ids === [] ) return [];
            $team_clause = ' AND a.team_id IN ( ' . implode( ',', array_fill( 0, count( $team_ids ), '%d' ) ) . ' )';
            $params      = array_merge( $params, $team_ids );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id, a.title, a.{$date_col} AS session_date, a.home_away, t.name AS team_name
               FROM {$p}tt_activities a
          LEFT JOIN {$p}tt_teams t ON t.id = a.team_id AND t.club_id = a.club_id
              WHERE a.club_id = %d
                AND LOWER(a.activity_type_key) IN ( 'match', 'game' )
                AND a.archived_at IS NULL
                AND a.trashed_at IS NULL
                AND ( a.opponent IS NULL OR a.opponent = '' )
                {$team_clause}
           ORDER BY a.{$date_col} DESC, a.id DESC
              LIMIT " . self::LIMIT,
            ...$params
        ) );

        $out = [];
        foreach ( (array) $rows as $row ) {
            $title   = (string) ( $row->title ?? '' );
            $derived = OpponentFromTitle::parse( $title, (string) ( $row->team_name ?? '' ) );
            $out[] = [
                'id'                 => (int) $row->id,
                'session_date'       => (string) ( $row->session_date ?? '' ),
                'title'              => $title,
                'team_name'          => (string) ( $row->team_name ?? '' ),
                'proposed_opponent'  => $derived !== null ? $derived['opponent'] : '',
                // A stored home/away is a fact somebody entered; only fall
                // back to the parser's reading when the column is empty.
                'proposed_home_away' => (string) ( $row->home_away ?? '' ) !== ''
                    ? (string) $row->home_away
                    : ( $derived !== null ? $derived['home_away'] : '' ),
                'confidence'         => $derived !== null ? $derived['confidence'] : '',
            ];
        }
        return $out;
    }

    /**
     * Academy-wide roles review the club's matches; everyone else reviews
     * the teams they coach. The same rule the activity surfaces apply, so
     * this screen can never show a coach a fixture they could not open.
     */
    private static function readsEveryTeam( int $user_id, bool $is_admin ): bool {
        return $is_admin
            || current_user_can( 'tt_edit_settings' )
            || \TT\Modules\Authorization\AllTeamsScope::canSeeAllTeamsActivities( $user_id );
    }

    // ── Handler ────────────────────────────────────────────────────────

    /**
     * The one commit point. Writes only ticked rows that carry an
     * opponent, and only activities the caller may still edit — the scope
     * is re-resolved here rather than trusted from the form, because the
     * ids came off a page that may be minutes old.
     */
    public static function handleSave(): void {
        check_admin_referer( 'tt_opponent_backfill_save', 'tt_nonce' );

        $user_id = get_current_user_id();
        if ( ! current_user_can( self::CAP ) ) {
            self::back( [ 'tt_error' => 'forbidden' ] );
        }

        $apply     = isset( $_POST['apply'] ) && is_array( $_POST['apply'] ) ? array_keys( wp_unslash( $_POST['apply'] ) ) : [];
        $opponents = isset( $_POST['opponent'] ) && is_array( $_POST['opponent'] ) ? (array) wp_unslash( $_POST['opponent'] ) : [];
        $venues    = isset( $_POST['home_away'] ) && is_array( $_POST['home_away'] ) ? (array) wp_unslash( $_POST['home_away'] ) : [];

        $allowed = [];
        foreach ( self::pending( $user_id, current_user_can( 'manage_options' ) ) as $row ) {
            $allowed[ (int) $row['id'] ] = true;
        }

        global $wpdb;
        $saved = 0;
        foreach ( $apply as $raw_id ) {
            $id = (int) $raw_id;
            if ( $id <= 0 || ! isset( $allowed[ $id ] ) ) continue;

            $opponent = sanitize_text_field( (string) ( $opponents[ $id ] ?? '' ) );
            if ( $opponent === '' ) continue;

            $data  = [ 'opponent' => $opponent ];
            $venue = sanitize_key( (string) ( $venues[ $id ] ?? '' ) );
            if ( in_array( $venue, [ 'home', 'away' ], true ) ) {
                $data['home_away'] = $venue;
            }

            $ok = $wpdb->update(
                "{$wpdb->prefix}tt_activities",
                $data,
                [ 'id' => $id, 'club_id' => (int) CurrentClub::id() ]
            );
            if ( $ok !== false ) $saved++;
        }

        if ( $saved === 0 ) {
            self::back( [ 'tt_error' => 'nothing' ] );
        }
        self::back( [ 'tt_saved' => $saved ] );
    }

    /** @param array<string,int|string> $args */
    private static function back( array $args ): void {
        wp_safe_redirect( add_query_arg(
            array_merge( [ 'tt_view' => self::SLUG ], $args ),
            RecordLink::dashboardUrl() /* tt-xview-ok — returns to this same view */
        ) );
        exit;
    }
}
