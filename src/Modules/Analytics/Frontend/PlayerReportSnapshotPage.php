<?php
namespace TT\Modules\Analytics\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerReportAccess;
use TT\Modules\Analytics\Reports\PlayerReportBlock;
use TT\Modules\Analytics\Reports\PlayerReportSnapshotRepository;
use TT\Modules\Analytics\Reports\PlayerReportSnapshots;
use TT\Shared\Dates\TTDate;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * PlayerReportSnapshotPage (#3890, epic #3871) — a frozen player report, and
 * the notes a conversation wrote on it.
 *
 * The team monthly report's snapshot page, for one player, with the same two
 * rules and for sharper reasons:
 *
 * - **Signed in, always.** No share link and no token. A player report is the
 *   densest record of one child's development the product produces; staff who
 *   do not log in get the PDF from someone who does.
 * - **The player is read from the snapshot, never from the URL.** Checking a
 *   requested `player_id` would let a reader who may see player A open a
 *   snapshot of player B by naming A in the query string.
 *
 * Notes are one per section, editable after the data is frozen, with an
 * explicit Save and a real Cancel (CLAUDE.md §6 model B): a half-written note
 * on the record of a conversation with a child is worse than a lost one.
 */
final class PlayerReportSnapshotPage {

    public const ACTION_CREATE = 'tt_pr_snapshot_create';
    public const ACTION_NOTE   = 'tt_pr_snapshot_note';

    /**
     * Handle a snapshot POST, then redirect. Runs before any output, so a
     * reload neither takes a second snapshot nor rewrites a note.
     */
    public static function handlePost(): void {
        if ( ! is_user_logged_in() ) return;

        $action = isset( $_POST['tt_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['tt_action'] ) ) : '';
        if ( $action === self::ACTION_CREATE ) { self::handleCreate(); return; }
        if ( $action === self::ACTION_NOTE )   { self::handleNote(); }
    }

    private static function handleCreate(): void {
        check_admin_referer( self::ACTION_CREATE );

        $player_id = isset( $_POST['player_id'] ) ? absint( $_POST['player_id'] ) : 0;
        $uuid      = PlayerReportSnapshots::take(
            $player_id,
            [
                'from'   => isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['from'] ) ) : '',
                'to'     => isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['to'] ) ) : '',
                'layout' => isset( $_POST['layout'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['layout'] ) ) : '',
                'blocks' => isset( $_POST['blocks'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['blocks'] ) ) : '',
            ],
            get_current_user_id(),
            isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['title'] ) ) : ''
        );

        if ( $uuid === '' ) {
            wp_die( esc_html__( 'The snapshot could not be saved.', 'talenttrack' ), '', [ 'response' => 403 ] );
        }

        wp_safe_redirect( self::url( $uuid ) );
        exit;
    }

    private static function handleNote(): void {
        check_admin_referer( self::ACTION_NOTE );

        $uuid    = isset( $_POST['snapshot'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['snapshot'] ) ) : '';
        $section = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( (string) $_POST['section'] ) ) : '';
        $body    = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['note'] ) ) : '';

        if ( ! PlayerReportSnapshots::note( $uuid, $section, $body, get_current_user_id() ) ) {
            wp_die( esc_html__( 'You do not have access to this snapshot.', 'talenttrack' ), '', [ 'response' => 403 ] );
        }

        wp_safe_redirect( self::url( $uuid ) . '#tt-pr-note-' . rawurlencode( $section ) );
        exit;
    }

    /** The snapshot's stable URL. Signed-in readers only; it carries no token. */
    public static function url( string $uuid ): string {
        return add_query_arg(
            [
                'tt_view'  => 'standard-report', /* tt-xview-ok */ // the report's own view
                'slug'     => PlayerReportPage::SLUG,
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
     * Render one snapshot. False when the reader may not see it, so the
     * caller decides what a refusal looks like.
     */
    public static function render( string $uuid ): bool {
        $snapshot = PlayerReportSnapshots::read( $uuid, get_current_user_id() );
        if ( $snapshot === null ) return false;

        PlayerReportPage::enqueuePublic();

        $report = $snapshot['report'];
        self::renderHeader( $snapshot );

        echo '<div class="tt-mr tt-pr" data-tt-player-report>';
        PlayerReportPage::renderBlocks(
            $report,
            [ 'from' => (string) $report['from'], 'to' => (string) $report['to'], 'period' => '' ],
            $snapshot['notes'],
            $uuid
        );
        echo '</div>';

        return true;
    }

    /** @param array<string,mixed> $snapshot */
    private static function renderHeader( array $snapshot ): void {
        $author = (int) ( $snapshot['created_by'] ?? 0 );
        $name   = $author > 0 ? (string) get_the_author_meta( 'display_name', $author ) : '';

        echo '<div class="tt-mr-snapshot-bar">';
        echo '<p class="tt-mr-snapshot-bar__t"><strong>' . esc_html( (string) ( $snapshot['title'] ?? '' ) ) . '</strong></p>';
        echo '<p class="tt-mr-muted">' . esc_html( sprintf(
            /* translators: 1: who took the snapshot, 2: when */
            __( 'Frozen by %1$s on %2$s. The numbers do not change; notes can still be edited.', 'talenttrack' ),
            $name !== '' ? $name : __( 'a staff member', 'talenttrack' ),
            TTDate::date( substr( (string) ( $snapshot['created_at'] ?? '' ), 0, 10 ) )
        ) ) . '</p>';
        echo '<p class="tt-mr-muted">' . esc_html__( 'Only signed-in staff who can see this player can open this snapshot. It has no shareable link — send the PDF instead.', 'talenttrack' ) . '</p>';
        echo '<p><a class="tt-btn tt-btn-secondary" href="' . esc_url( self::pdfUrl( (string) ( $snapshot['uuid'] ?? '' ) ) ) . '">'
            . esc_html__( 'Download PDF', 'talenttrack' ) . '</a></p>';
        echo '</div>';
    }

    /** The frozen report on paper, notes included, so print and screen agree. */
    private static function pdfUrl( string $uuid ): string {
        return add_query_arg(
            [ 'format' => 'pdf', 'snapshot' => $uuid, '_wpnonce' => wp_create_nonce( 'wp_rest' ) ],
            rest_url( 'talenttrack/v1/exports/player_report_pdf' )
        );
    }

    /**
     * The note on one section and the form to change it. Cancel is a link
     * back to the snapshot, so abandoning a half-written note leaves the
     * stored one alone.
     *
     * @param array<string,array{body:string, author:int, updated_at:string}> $notes
     */
    public static function renderNote( string $section, array $notes, string $uuid ): void {
        if ( $uuid === '' || ! PlayerReportBlock::isValid( $section ) ) return;

        $note = $notes[ $section ] ?? null;
        $id   = 'tt-pr-note-' . $section;

        echo '<div class="tt-mr-note" id="' . esc_attr( $id ) . '">';
        if ( $note !== null ) {
            echo '<p class="tt-mr-note__body">' . nl2br( esc_html( $note['body'] ) ) . '</p>';
            $author = (int) $note['author'];
            $name   = $author > 0 ? (string) get_the_author_meta( 'display_name', $author ) : '';
            echo '<p class="tt-mr-muted tt-mr-note__by">' . esc_html( sprintf(
                /* translators: 1: who wrote the note, 2: when */
                __( '%1$s, %2$s', 'talenttrack' ),
                $name !== '' ? $name : __( 'a staff member', 'talenttrack' ),
                TTDate::date( substr( $note['updated_at'], 0, 10 ) )
            ) ) . '</p>';
        }

        $field = $id . '-field';
        echo '<details class="tt-mr-note__edit">';
        echo '<summary>' . esc_html( $note === null ? __( 'Add a note', 'talenttrack' ) : __( 'Edit this note', 'talenttrack' ) ) . '</summary>';
        echo '<form method="post" class="tt-mr-note__form">';
        wp_nonce_field( self::ACTION_NOTE );
        echo '<input type="hidden" name="tt_action" value="' . esc_attr( self::ACTION_NOTE ) . '">';
        echo '<input type="hidden" name="snapshot" value="' . esc_attr( $uuid ) . '">';
        echo '<input type="hidden" name="section" value="' . esc_attr( $section ) . '">';
        echo '<label class="tt-label" for="' . esc_attr( $field ) . '">' . esc_html__( 'Note for this section', 'talenttrack' ) . '</label>';
        echo '<textarea class="tt-input" id="' . esc_attr( $field ) . '" name="note" rows="4" maxlength="' . esc_attr( (string) PlayerReportSnapshotRepository::MAX_NOTE_LENGTH ) . '">'
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
     * "Save snapshot" on the live report, and the player's earlier snapshots.
     *
     * @param array{from:string,to:string,period:string} $window
     * @param list<string>                               $blocks
     */
    public static function renderTakeAndList( int $player_id, array $window, string $layout, array $blocks ): void {
        if ( ! PlayerReportAccess::canRead( get_current_user_id(), $player_id ) ) return;

        echo '<div class="tt-mr-snapshots">';

        echo '<form method="post" class="tt-mr-snapshots__take">';
        wp_nonce_field( self::ACTION_CREATE );
        echo '<input type="hidden" name="tt_action" value="' . esc_attr( self::ACTION_CREATE ) . '">';
        echo '<input type="hidden" name="player_id" value="' . esc_attr( (string) $player_id ) . '">';
        echo '<input type="hidden" name="from" value="' . esc_attr( $window['from'] ) . '">';
        echo '<input type="hidden" name="to" value="' . esc_attr( $window['to'] ) . '">';
        echo '<input type="hidden" name="layout" value="' . esc_attr( $layout ) . '">';
        echo '<input type="hidden" name="blocks" value="' . esc_attr( implode( ',', $blocks ) ) . '">';
        echo '<button type="submit" class="tt-btn tt-btn-secondary">' . esc_html__( 'Save snapshot of this conversation', 'talenttrack' ) . '</button>';
        echo '<span class="tt-mr-panel__hint">' . esc_html__( 'Freezes these numbers as a record of what the conversation was based on, and lets you add notes per section.', 'talenttrack' ) . '</span>';
        echo '</form>';

        $rows = ( new PlayerReportSnapshotRepository() )->listForPlayer( $player_id, 10 );
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
