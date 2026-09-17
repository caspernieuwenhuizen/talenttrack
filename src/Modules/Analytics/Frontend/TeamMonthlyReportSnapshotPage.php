<?php
namespace TT\Modules\Analytics\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;
use TT\Modules\Analytics\Reports\TeamReportAccess;
use TT\Modules\Analytics\Reports\TeamReportSnapshotRepository;
use TT\Shared\Dates\TTDate;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * TeamMonthlyReportSnapshotPage (#3517, epic #3513) — a frozen monthly report,
 * and the notes a meeting wrote on it.
 *
 * The live report is a view of current data, which a staff meeting cannot run
 * on: open it on the 3rd, discuss it on the 5th, and a register taken in
 * between has moved the numbers under the discussion. A snapshot stores what
 * was rendered, so what the meeting saw stays reproducible.
 *
 * ## Signed in, always
 *
 * A snapshot has **no share link and no token**. It is reachable only by a
 * signed-in reader holding the capability for that team's reports —
 * `TeamReportAccess::canRead()`, the same rule the live report and the PDF
 * answer to, and a rule that refuses user 0 by construction.
 *
 * This is the deliberate difference from match prep and match analysis, which
 * do carry share links. A monthly report names every player in a squad and
 * carries their attendance, their test readings and who needs a conversation —
 * the densest collection of information about minors this product produces. A
 * URL that works for whoever holds it is the wrong shape for that at any
 * expiry. Staff who do not log in get the PDF, handed over by someone who does.
 *
 * **The team is read from the snapshot, never from the URL.** Checking the
 * requested `team_id` would let a reader who may see team A open a snapshot of
 * team B by naming A in the query string.
 *
 * ## Notes
 *
 * One per section, editable after the snapshot is taken while the data is not:
 * staff draft before the meeting and record what was decided during it.
 * Explicit Save with a real Cancel (CLAUDE.md §6 model B) — a half-written note
 * on the meeting's record is worse than a lost one, and there is an obvious
 * commit point.
 */
final class TeamMonthlyReportSnapshotPage {

    public const ACTION_CREATE = 'tt_mr_snapshot_create';
    public const ACTION_NOTE   = 'tt_mr_snapshot_note';

    /**
     * Handle a snapshot POST, then redirect. Runs before any output.
     *
     * Redirect-after-post so a reload does not take a second snapshot or
     * rewrite a note, and so the URL a reader shares is the snapshot rather
     * than the form that made it.
     */
    public static function handlePost(): void {
        if ( ! is_user_logged_in() ) return;

        $action = isset( $_POST['tt_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['tt_action'] ) ) : '';
        if ( $action === self::ACTION_CREATE ) { self::handleCreate(); return; }
        if ( $action === self::ACTION_NOTE )   { self::handleNote(); }
    }

    private static function handleCreate(): void {
        check_admin_referer( self::ACTION_CREATE );

        $team_id = isset( $_POST['team_id'] ) ? absint( $_POST['team_id'] ) : 0;
        $user_id = get_current_user_id();
        if ( ! TeamReportAccess::canRead( $user_id, $team_id ) ) {
            wp_die( esc_html__( 'You do not have access to this team.', 'talenttrack' ), '', [ 'response' => 403 ] );
        }

        $composition = \TT\Modules\Analytics\Reports\TeamMonthlyReportComposition::normalise( [
            'team_id' => $team_id,
            'from'    => isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['from'] ) ) : '',
            'to'      => isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['to'] ) ) : '',
            'layout'  => isset( $_POST['layout'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['layout'] ) ) : '',
            'blocks'  => isset( $_POST['blocks'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['blocks'] ) ) : '',
            'options' => isset( $_POST['options'] ) ? wp_unslash( (string) $_POST['options'] ) : '', // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized -- normalised.
        ] );

        $window = \TT\Modules\Analytics\Reports\TeamMonthlyReportComposition::window( $composition, gmdate( 'Y-m-d' ) );
        $report = ( new \TT\Modules\Analytics\Reports\TeamMonthlyReport() )->forTeam(
            $team_id,
            $window['from'],
            $window['to'],
            $composition['blocks'],
            $user_id,
            $composition['options']
        );

        $title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['title'] ) ) : '';
        if ( $title === '' ) {
            $team  = QueryHelpers::get_team( $team_id );
            $title = sprintf(
                /* translators: 1: team name, 2: period start, 3: period end */
                _x( '%1$s, %2$s – %3$s', 'default name for a report snapshot', 'talenttrack' ),
                (string) ( $team->name ?? '' ),
                TTDate::date( $window['from'] ),
                TTDate::date( $window['to'] )
            );
        }

        $uuid = ( new TeamReportSnapshotRepository() )->create( $team_id, $composition, $report, $title, $user_id );
        if ( $uuid === '' ) {
            wp_die( esc_html__( 'The snapshot could not be saved.', 'talenttrack' ), '', [ 'response' => 500 ] );
        }

        wp_safe_redirect( self::url( $uuid ) );
        exit;
    }

    private static function handleNote(): void {
        check_admin_referer( self::ACTION_NOTE );

        $uuid    = isset( $_POST['snapshot'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['snapshot'] ) ) : '';
        $repo    = new TeamReportSnapshotRepository();
        $row     = $repo->find( $uuid );
        $user_id = get_current_user_id();

        // The team comes from the snapshot, never from the request.
        if ( $row === null || ! TeamReportAccess::canRead( $user_id, (int) $row->team_id ) ) {
            wp_die( esc_html__( 'You do not have access to this snapshot.', 'talenttrack' ), '', [ 'response' => 403 ] );
        }

        $section = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( (string) $_POST['section'] ) ) : '';
        $body    = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['note'] ) ) : '';

        $repo->putNote( $uuid, $section, $body, $user_id );

        wp_safe_redirect( self::url( $uuid ) . '#tt-mr-note-' . rawurlencode( $section ) );
        exit;
    }

    /** The snapshot's stable URL. Signed-in readers only; it carries no token. */
    public static function url( string $uuid ): string {
        return add_query_arg(
            [
                'tt_view'  => 'standard-report', /* tt-xview-ok */ // the report's own view
                'slug'     => TeamMonthlyReportPage::SLUG,
                'snapshot' => $uuid,
            ],
            RecordLink::dashboardUrl()
        );
    }

    /** The uuid on the URL, or '' when this is not a snapshot request. */
    public static function requested(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
        return isset( $_GET['snapshot'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['snapshot'] ) ) : '';
    }

    /**
     * Render one snapshot. Returns false when the reader may not see it, so
     * the caller can fall through to its own empty state rather than this
     * deciding what a refusal looks like.
     */
    public static function render( string $uuid ): bool {
        $repo = new TeamReportSnapshotRepository();
        $row  = $repo->find( $uuid );

        // One check, on the snapshot's own team. A signed-out reader is user 0,
        // which canRead() refuses outright.
        if ( $row === null || ! TeamReportAccess::canRead( get_current_user_id(), (int) $row->team_id ) ) {
            return false;
        }

        TeamMonthlyReportPage::enqueuePublic();

        $report = TeamReportSnapshotRepository::reportOf( $row );
        $notes  = TeamReportSnapshotRepository::notesOf( $row );
        $team   = QueryHelpers::get_team( (int) $row->team_id );

        self::renderHeader( $row, $report );

        echo '<div class="tt-mr" data-tt-monthly-report>';
        TeamMonthlyReportPage::renderBlocks(
            $report,
            $team,
            [ 'from' => $report['from'], 'to' => $report['to'], 'period' => '' ],
            $notes,
            $uuid
        );
        echo '</div>';

        return true;
    }

    /**
     * @param object{uuid:string, title:string, created_by:int, created_at:string} $row
     * @param array<string,mixed>                                                  $report
     */
    private static function renderHeader( object $row, array $report ): void {
        $author = (int) ( $row->created_by ?? 0 );
        $name   = $author > 0 ? get_the_author_meta( 'display_name', $author ) : '';

        echo '<div class="tt-mr-snapshot-bar">';
        echo '<p class="tt-mr-snapshot-bar__t"><strong>' . esc_html( (string) ( $row->title ?? '' ) ) . '</strong></p>';
        echo '<p class="tt-mr-muted">' . esc_html( sprintf(
            /* translators: 1: who took the snapshot, 2: when */
            __( 'Frozen by %1$s on %2$s. The numbers do not change; notes can still be edited.', 'talenttrack' ),
            $name !== '' ? $name : __( 'a staff member', 'talenttrack' ),
            TTDate::date( substr( (string) ( $row->created_at ?? '' ), 0, 10 ) )
        ) ) . '</p>';
        echo '<p class="tt-mr-muted">' . esc_html__( 'This snapshot is only readable by signed-in staff who can see this team. It has no shareable link — send the PDF instead.', 'talenttrack' ) . '</p>';
        echo '<p><a class="tt-btn tt-btn-secondary" href="' . esc_url( self::pdfUrl( (string) ( $row->uuid ?? '' ) ) ) . '">'
            . esc_html__( 'Download PDF', 'talenttrack' ) . '</a></p>';
        echo '</div>';
    }

    /** The frozen report on paper, notes included, so print and screen agree. */
    private static function pdfUrl( string $uuid ): string {
        return add_query_arg(
            [ 'format' => 'pdf', 'snapshot' => $uuid, '_wpnonce' => wp_create_nonce( 'wp_rest' ) ],
            rest_url( 'talenttrack/v1/exports/team_monthly_report_pdf' )
        );
    }

    /**
     * The note on one section: what it says, and the form to change it.
     *
     * Explicit Save with a real Cancel (CLAUDE.md §6). Cancel is a link back to
     * the snapshot rather than a reset, so abandoning a half-written note
     * leaves the stored one alone.
     *
     * @param array<string,array{body:string, author:int, updated_at:string}> $notes
     */
    public static function renderNote( string $section, array $notes, string $uuid ): void {
        if ( $uuid === '' || ! TeamMonthlyReportBlock::isValid( $section ) ) return;

        $note = $notes[ $section ] ?? null;
        $id   = 'tt-mr-note-' . $section;

        echo '<div class="tt-mr-note" id="' . esc_attr( $id ) . '">';

        if ( $note !== null ) {
            echo '<p class="tt-mr-note__body">' . nl2br( esc_html( $note['body'] ) ) . '</p>';
            $author = (int) $note['author'];
            $name   = $author > 0 ? get_the_author_meta( 'display_name', $author ) : '';
            echo '<p class="tt-mr-muted tt-mr-note__by">' . esc_html( sprintf(
                /* translators: 1: who wrote the note, 2: when */
                __( '%1$s, %2$s', 'talenttrack' ),
                $name !== '' ? $name : __( 'a staff member', 'talenttrack' ),
                TTDate::date( substr( $note['updated_at'], 0, 10 ) )
            ) ) . '</p>';
        }

        $field = $id . '-field';
        echo '<details class="tt-mr-note__edit">';
        echo '<summary>' . esc_html( $note === null
            ? __( 'Add a note', 'talenttrack' )
            : __( 'Edit this note', 'talenttrack' ) ) . '</summary>';
        echo '<form method="post" class="tt-mr-note__form">';
        wp_nonce_field( self::ACTION_NOTE );
        echo '<input type="hidden" name="tt_action" value="' . esc_attr( self::ACTION_NOTE ) . '">';
        echo '<input type="hidden" name="snapshot" value="' . esc_attr( $uuid ) . '">';
        echo '<input type="hidden" name="section" value="' . esc_attr( $section ) . '">';
        echo '<label class="tt-label" for="' . esc_attr( $field ) . '">' . esc_html__( 'Note for this section', 'talenttrack' ) . '</label>';
        echo '<textarea class="tt-input" id="' . esc_attr( $field ) . '" name="note" rows="4" maxlength="' . esc_attr( (string) TeamReportSnapshotRepository::MAX_NOTE_LENGTH ) . '">'
            . esc_textarea( $note['body'] ?? '' ) . '</textarea>';
        echo '<p class="tt-mr-panel__hint">' . esc_html__( 'Clearing the note and saving removes it.', 'talenttrack' ) . '</p>';
        echo '<div class="tt-form-actions">';
        echo '<a class="tt-btn tt-btn-secondary" href="' . esc_url( self::url( $uuid ) ) . '">' . esc_html__( 'Cancel', 'talenttrack' ) . '</a>';
        echo '<button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Save note', 'talenttrack' ) . '</button>';
        echo '</div>';
        echo '</form>';
        echo '</details>';
        echo '</div>';
    }

    /**
     * The snapshots taken for a team, for the live report to link to.
     *
     * @param array{from:string,to:string,period:string} $window
     * @param list<string>                               $blocks
     * @param array<string,array<string,mixed>>          $options
     */
    public static function renderTakeAndList( int $team_id, array $window, string $layout, array $blocks, array $options ): void {
        if ( ! TeamReportAccess::canRead( get_current_user_id(), $team_id ) ) return;

        echo '<div class="tt-mr-snapshots">';

        echo '<form method="post" class="tt-mr-snapshots__take">';
        wp_nonce_field( self::ACTION_CREATE );
        echo '<input type="hidden" name="tt_action" value="' . esc_attr( self::ACTION_CREATE ) . '">';
        echo '<input type="hidden" name="team_id" value="' . esc_attr( (string) $team_id ) . '">';
        echo '<input type="hidden" name="from" value="' . esc_attr( $window['from'] ) . '">';
        echo '<input type="hidden" name="to" value="' . esc_attr( $window['to'] ) . '">';
        echo '<input type="hidden" name="layout" value="' . esc_attr( $layout ) . '">';
        echo '<input type="hidden" name="blocks" value="' . esc_attr( implode( ',', $blocks ) ) . '">';
        if ( $options !== [] ) {
            echo '<input type="hidden" name="options" value="' . esc_attr( (string) wp_json_encode( $options ) ) . '">';
        }
        echo '<button type="submit" class="tt-btn tt-btn-secondary">' . esc_html__( 'Save snapshot for the meeting', 'talenttrack' ) . '</button>';
        echo '<span class="tt-mr-panel__hint">' . esc_html__( 'Freezes these numbers so the meeting can be reproduced afterwards, and lets you add notes per section.', 'talenttrack' ) . '</span>';
        echo '</form>';

        $rows = ( new TeamReportSnapshotRepository() )->listForTeam( $team_id, 10 );
        if ( $rows !== [] ) {
            echo '<ul class="tt-mr-snapshots__list">';
            foreach ( $rows as $row ) {
                echo '<li><a href="' . esc_url( self::url( (string) $row->uuid ) ) . '">'
                    . esc_html( (string) ( $row->title ?? '' ) ) . '</a> '
                    . '<span class="tt-mr-muted">' . esc_html( TTDate::date( substr( (string) ( $row->created_at ?? '' ), 0, 10 ) ) ) . '</span></li>';
            }
            echo '</ul>';
        }

        echo '</div>';
    }
}
