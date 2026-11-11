<?php
namespace TT\Modules\ReportStudio\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\PlayerReportSnapshotRepository;
use TT\Modules\Analytics\Reports\ReportFilters;
use TT\Modules\ReportStudio\Domain\BlockRegistry;
use TT\Modules\Export\ExportGate;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\UpgradePanel;
use TT\Modules\ReportStudio\Domain\ReportAccess;
use TT\Modules\ReportStudio\Domain\ReportReader;
use TT\Modules\ReportStudio\Domain\ReportSnapshots;
use TT\Modules\ReportStudio\Repositories\ReportsRepository;
use TT\Shared\Dates\TTDate;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendReportView (#4591, epic #4564) — `?tt_view=report&id=N`: a
 * published Report Studio report, read only, for the people it is published
 * to. Reached from "Shared with me" on the Reports launcher.
 *
 * It shows the published version, never a draft. Whether the reader may
 * open it is `ReportAccess::canRead()` (a recipient holds one of its persona
 * keys and reads its subject); each block is checked again by the data
 * route, and a block the reader may not see says so instead of showing
 * figures. The page is a shell that `frontend-report-viewer.js` fills from
 * REST: since #5021 with the document the PDF prints, drawn on the server
 * (`GET /reports/{id}/document`).
 *
 * `&snapshot=<uuid>` opens a frozen copy instead (#5021), by the rules of the
 * copy's own subject.
 *
 * @phpstan-import-type Report from ReportsRepository
 */
final class FrontendReportView extends FrontendViewBase {

    public const SLUG = 'report';

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_view_reports' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Your role does not have access to reports.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( ! LicenseGate::allows( 'report_studio' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Report', 'talenttrack' ), [ self::reportsCrumb() ] );
            echo UpgradePanel::render( 'report_studio' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- UpgradePanel returns escaped HTML
            return;
        }

        // #5021 — a frozen copy answers to its own subject, not to the
        // report, so it opens after the report changed or was archived. This
        // is a staff page: a family opens a copy shared with them on the
        // Reports tab of the child's file, as it opens a shared player
        // report, and the capability check above refuses them here on purpose.
        $snapshot = isset( $_GET['snapshot'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['snapshot'] ) ) : '';
        if ( $snapshot !== '' ) {
            self::renderSnapshot( $snapshot, $user_id );
            return;
        }

        $id     = isset( $_GET['id'] ) ? absint( wp_unslash( (string) $_GET['id'] ) ) : 0;
        $report = $id > 0 ? ( new ReportsRepository() )->find( $id ) : null;
        if ( $report === null || $report['archived_at'] !== null ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Report not found', 'talenttrack' ), [ self::reportsCrumb() ] );
            echo '<p class="tt-notice">' . esc_html__( 'This report does not exist, or it was removed.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( ! ReportAccess::canRead( ReportReader::for( $user_id ), $report ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ), [ self::reportsCrumb() ] );
            echo '<p class="tt-notice">' . esc_html__( 'This report is not shared with you.', 'talenttrack' ) . '</p>';
            return;
        }

        $name = $report['name'] !== '' ? $report['name'] : __( 'Untitled report', 'talenttrack' );
        FrontendBreadcrumbs::fromDashboard( $name, [ self::reportsCrumb() ] );
        if ( $report['published_version_id'] === null ) {
            echo '<p class="tt-notice">' . esc_html__( 'This report has not been published yet.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueue( $id, $report );
        echo '<div class="tt-rv" data-tt-rv>';
        echo '<h1 class="tt-fview-title tt-rv-title">' . esc_html( $name ) . '</h1>';
        echo '<div class="tt-rs-context tt-rv-context">';
        echo '<div class="tt-field" data-tt-rv-subject hidden></div>';
        echo '<fieldset class="tt-rs-radios" data-tt-rv-periods hidden><legend>' . esc_html__( 'Period', 'talenttrack' ) . '</legend></fieldset>';
        echo '</div>';
        echo '<div class="tt-rv-actions">';
        if ( ExportGate::mayExport( $user_id ) ) {
            echo '<a class="tt-btn tt-btn-secondary" data-tt-rv-pdf href="#" hidden>' . esc_html__( 'Download PDF', 'talenttrack' ) . '</a>';
        }
        if ( in_array( $report['scope'], ReportSnapshots::SCOPES, true ) ) {
            // Shown by the script when the document route says this reader
            // may freeze the report for the subject on screen.
            echo '<button type="button" class="tt-btn tt-btn-secondary" data-tt-rv-open-snapshot hidden>' . esc_html__( 'Take snapshot', 'talenttrack' ) . '</button>';
        }
        echo '</div>';
        echo '<p class="tt-rs-notice" data-tt-rv-notice role="status" hidden></p>';
        // #5021 — the document the PDF prints, drawn on the server.
        echo '<div class="tt-rv-document" data-tt-rv-document aria-live="polite"></div>';
        self::renderSnapshotList( $report, $user_id );
        self::renderSnapshotDialog( $report['audience'] === PlayerReportAudience::FAMILY );
        echo '</div>';
    }

    /**
     * #5021 — the report's frozen copies this reader may open, newest first.
     *
     * @param Report $report
     */
    private static function renderSnapshotList( array $report, int $user_id ): void {
        if ( ! in_array( $report['scope'], ReportSnapshots::SCOPES, true ) ) return;
        $rows = ReportSnapshots::listFor( $report, $user_id );

        echo '<section class="tt-rv-snapshots" aria-labelledby="tt-rv-snapshots-title">';
        echo '<h2 id="tt-rv-snapshots-title" class="tt-rv-snapshots-title">' . esc_html__( 'Snapshots', 'talenttrack' ) . '</h2>';
        if ( $rows === [] ) {
            echo '<p class="tt-rs-hint">' . esc_html__( 'No snapshots yet. A snapshot keeps this report as it reads today, for a conversation or for the family.', 'talenttrack' ) . '</p>';
            echo '</section>';
            return;
        }
        echo '<ul class="tt-rv-snapshot-list">';
        foreach ( $rows as $row ) {
            $url = add_query_arg( 'snapshot', $row['uuid'], RecordLink::detailUrlForWithBack( self::SLUG, (int) $report['id'] ) ); /* tt-xview-ok: this view's own frozen copy */
            echo '<li><a class="tt-rv-snapshot-link" href="' . esc_url( $url ) . '">'
                . '<span class="tt-rv-snapshot-name">' . esc_html( $row['title'] ) . '</span>'
                . '<span class="tt-rs-hint">' . esc_html( sprintf(
                    /* translators: 1: period start, 2: period end */
                    __( 'Covers %1$s – %2$s', 'talenttrack' ),
                    TTDate::date( $row['period_from'] ),
                    TTDate::date( $row['period_to'] )
                ) ) . ( $row['audience'] === PlayerReportAudience::FAMILY ? ' · ' . esc_html__( 'Shared with the family', 'talenttrack' ) : '' ) . '</span>'
                . '</a></li>';
        }
        echo '</ul></section>';
    }

    /**
     * #5021 — taking a snapshot is its own control with its own confirm:
     * it makes a record, and for a family report it puts the document in
     * front of the family.
     */
    private static function renderSnapshotDialog( bool $family ): void {
        echo '<dialog class="tt-rs-dialog" data-tt-rv-snapshot aria-labelledby="tt-rv-snapshot-title">';
        echo '<form method="dialog" class="tt-rs-publish-form">';
        echo '<h2 id="tt-rv-snapshot-title">' . esc_html__( 'Take snapshot', 'talenttrack' ) . '</h2>';
        echo '<p class="tt-rs-hint">' . esc_html( $family
            ? __( 'The report is frozen as it reads now, for the subject and period shown, and the player and their parents see it on the player\'s file.', 'talenttrack' )
            : __( 'The report is frozen as it reads now, for the subject and period shown. Staff who can see the subject can open it.', 'talenttrack' ) ) . '</p>';
        echo '<div class="tt-field"><label class="tt-field-label" for="tt-rv-snapshot-name">' . esc_html__( 'Name', 'talenttrack' ) . '</label>';
        echo '<input type="text" id="tt-rv-snapshot-name" class="tt-input" data-tt-rv-snapshot-name maxlength="255" autocomplete="off" /></div>';
        echo '<p class="tt-rs-error" data-tt-rv-snapshot-error role="alert"></p>';
        echo '<div class="tt-form-actions">';
        echo '<button type="submit" class="tt-btn tt-btn-secondary" value="cancel" formnovalidate>' . esc_html__( 'Cancel', 'talenttrack' ) . '</button>';
        echo '<button type="submit" class="tt-btn tt-btn-primary" value="confirm">' . esc_html__( 'Take snapshot', 'talenttrack' ) . '</button>';
        echo '</div>';
        echo '</form>';
        echo '</dialog>';
    }

    /**
     * #5021 — one frozen copy, as this reader may read it: the stored
     * document with its notes, its PDF, and nothing to change.
     */
    private static function renderSnapshot( string $uuid, int $user_id ): void {
        $snapshot = ReportSnapshots::read( $uuid, $user_id );
        if ( $snapshot === null ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ), [ self::reportsCrumb() ] );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to this snapshot.', 'talenttrack' ) . '</p>';
            return;
        }

        FrontendBreadcrumbs::fromDashboard( $snapshot['title'], [ self::reportsCrumb() ] );
        self::enqueueAssets();
        wp_enqueue_style( 'tt-report-studio', TT_PLUGIN_URL . 'assets/css/frontend-report-studio.css', [], TT_VERSION );

        $author = $snapshot['created_by'];
        $name   = $author > 0 ? (string) get_the_author_meta( 'display_name', $author ) : '';
        echo '<div class="tt-rv">';
        echo '<h1 class="tt-fview-title tt-rv-title">' . esc_html( $snapshot['title'] ) . '</h1>';
        echo '<p class="tt-rs-hint">' . esc_html( sprintf(
            /* translators: 1: who took the snapshot, 2: when */
            __( 'Frozen by %1$s on %2$s. The numbers do not change; notes can still be edited.', 'talenttrack' ),
            $name !== '' ? $name : __( 'a staff member', 'talenttrack' ),
            TTDate::date( substr( $snapshot['created_at'], 0, 10 ) )
        ) ) . '</p>';
        if ( ExportGate::mayExport( $user_id ) ) {
            $pdf = add_query_arg(
                [ 'format' => 'pdf', 'snapshot' => $snapshot['uuid'], '_wpnonce' => wp_create_nonce( 'wp_rest' ) ],
                rest_url( 'talenttrack/v1/exports/report_studio_pdf' )
            );
            echo '<div class="tt-rv-actions"><a class="tt-btn tt-btn-secondary" href="' . esc_url( $pdf ) . '">' . esc_html__( 'Download PDF', 'talenttrack' ) . '</a></div>';
        }
        StudioDocumentView::render( $snapshot['document'], $user_id, $snapshot['notes'] );
        if ( $snapshot['can_note'] ) self::renderNoteForms( $snapshot );
        echo '</div>';
    }

    /**
     * #5021 — a note under each block of a staff snapshot, kept with the
     * snapshot's notes and printed under the block. Explicit Save with a real
     * Cancel (CLAUDE.md §6 model B): a half-written note on the record of a
     * conversation is worse than a lost one.
     *
     * @param array{uuid:string, report_id:int, document:array<string,mixed>, notes:array<string,array{body:string, author:int, updated_at:string}>} $snapshot
     */
    private static function renderNoteForms( array $snapshot ): void {
        $blocks = is_array( $snapshot['document']['blocks'] ?? null ) ? $snapshot['document']['blocks'] : [];
        // Cancel goes back where the reader came from, or reopens the
        // snapshot, so a half-written note is dropped.
        $back   = BackLink::resolve();
        $self   = $back !== null
            ? $back['url']
            : add_query_arg( 'snapshot', $snapshot['uuid'], RecordLink::detailUrlForWithBack( self::SLUG, $snapshot['report_id'] ) ); /* tt-xview-ok: this view's own frozen copy */

        wp_enqueue_script( 'tt-report-snapshot', TT_PLUGIN_URL . 'assets/js/frontend-report-snapshot.js', [], TT_VERSION, true );
        wp_localize_script( 'tt-report-snapshot', 'TTReportSnapshot', [
            'rest'  => esc_url_raw( rest_url( 'talenttrack/v1/report-snapshots/' . rawurlencode( $snapshot['uuid'] ) . '/notes/' ) ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'i18n'  => [ 'error' => __( 'The note could not be saved.', 'talenttrack' ) ],
        ] );

        echo '<section class="tt-rv-notes" aria-labelledby="tt-rv-notes-title">';
        echo '<h2 id="tt-rv-notes-title" class="tt-rv-snapshots-title">' . esc_html__( 'Notes', 'talenttrack' ) . '</h2>';
        foreach ( $blocks as $block ) {
            if ( ! is_array( $block ) || ( $block['key'] ?? '' ) === 'page_break' ) continue;
            $id       = sanitize_key( (string) ( $block['id'] ?? '' ) );
            $renderer = BlockRegistry::find( (string) ( $block['key'] ?? '' ) );
            $label    = $renderer !== null ? $renderer->label() : _x( 'Block', 'report studio', 'talenttrack' );
            $note     = $snapshot['notes'][ $id ] ?? null;
            $field    = 'tt-rv-note-' . $id;

            echo '<details class="tt-mr-note__edit">';
            echo '<summary>' . esc_html( $label ) . ' · ' . esc_html( $note === null ? __( 'Add a note', 'talenttrack' ) : __( 'Edit this note', 'talenttrack' ) ) . '</summary>';
            echo '<form class="tt-mr-note__form" data-tt-rv-note data-block="' . esc_attr( $id ) . '">';
            echo '<label class="tt-label" for="' . esc_attr( $field ) . '">' . esc_html__( 'Note for this block', 'talenttrack' ) . '</label>';
            echo '<textarea class="tt-input" id="' . esc_attr( $field ) . '" name="note" rows="4" maxlength="' . esc_attr( (string) PlayerReportSnapshotRepository::MAX_NOTE_LENGTH ) . '">'
                . esc_textarea( $note['body'] ?? '' ) . '</textarea>';
            echo '<p class="tt-mr-panel__hint">' . esc_html__( 'Clearing the note and saving removes it.', 'talenttrack' ) . '</p>';
            echo '<p class="tt-rs-error" data-tt-rv-note-error role="alert"></p>';
            echo '<div class="tt-form-actions">';
            echo '<a class="tt-btn tt-btn-secondary" href="' . esc_url( $self ) . '">' . esc_html__( 'Cancel', 'talenttrack' ) . '</a>';
            echo '<button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Save note', 'talenttrack' ) . '</button>';
            echo '</div>';
            echo '</form>';
            echo '</details>';
        }
        echo '</section>';
    }

    /** @param Report $report */
    private static function enqueue( int $id, array $report ): void {
        self::enqueueAssets();
        wp_enqueue_style( 'tt-report-studio', TT_PLUGIN_URL . 'assets/css/frontend-report-studio.css', [], TT_VERSION );
        StudioDocumentView::enqueue();
        wp_enqueue_script( 'tt-report-viewer', TT_PLUGIN_URL . 'assets/js/frontend-report-viewer.js', [], TT_VERSION, true );

        $periods = [];
        foreach ( ReportFilters::periodLabels() as $key => $label ) {
            if ( $key !== '' ) $periods[] = [ 'key' => (string) $key, 'label' => $label ];
        }
        wp_localize_script( 'tt-report-viewer', 'TTReportViewer', [
            'id'       => $id,
            'rest'     => esc_url_raw( rest_url( 'talenttrack/v1/' ) ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'periods'  => $periods,
            // #5021 — the PDF of what is on screen, for an account that may
            // receive files; the script adds the subject and the period.
            'pdf'      => ExportGate::mayExport( get_current_user_id() )
                ? esc_url_raw( add_query_arg( [ 'format' => 'pdf', 'report_id' => $id, 'source' => 'published', '_wpnonce' => wp_create_nonce( 'wp_rest' ) ], rest_url( 'talenttrack/v1/exports/report_studio_pdf' ) ) )
                : '',
            'snapshotUrl' => esc_url_raw( add_query_arg( 'snapshot', '__uuid__', RecordLink::detailUrlForWithBack( self::SLUG, $id ) ) ), /* tt-xview-ok: this view's own frozen copy */
            'i18n'     => [
                'loadError'    => __( 'The report could not be loaded. Reload the page to try again.', 'talenttrack' ),
                'loading'      => __( 'Loading…', 'talenttrack' ),
                'player'       => __( 'Player', 'talenttrack' ),
                'team'         => __( 'Team', 'talenttrack' ),
                'choose'       => __( 'Choose…', 'talenttrack' ),
                'snapshotTaken' => __( 'The snapshot is saved.', 'talenttrack' ),
            ],
        ] );
    }

    /** @return array{label:string,url:string} */
    private static function reportsCrumb(): array {
        return FrontendBreadcrumbs::viewCrumb( 'reports', __( 'Reports', 'talenttrack' ) );
    }
}
