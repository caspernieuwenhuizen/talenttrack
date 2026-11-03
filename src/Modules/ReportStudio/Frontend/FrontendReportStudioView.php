<?php
namespace TT\Modules\ReportStudio\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\PersonaLabels;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\UpgradePanel;
use TT\Modules\ReportStudio\Domain\ReportAccess;
use TT\Modules\ReportStudio\Domain\ReportDefinition;
use TT\Modules\ReportStudio\Domain\ReportNaming;
use TT\Modules\ReportStudio\Domain\ReportReader;
use TT\Modules\ReportStudio\Domain\ReportStartRules;
use TT\Modules\ReportStudio\Repositories\ReportsRepository;
use TT\Modules\ReportStudio\Wizard\NewReportWizard;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\CrossViewLink;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\Components\SaveState;
use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Wizards\WizardEntryPoint;
use TT\Shared\Wizards\WizardRegistry;

/**
 * FrontendReportStudioView (#4571, epic #4564) — `?tt_view=report-studio`.
 *
 * Without an id: the builder's own reports and the **Add report** button,
 * which opens the start wizard. With `&id=N`: the canvas, where the wizard
 * lands. The canvas is a shell; `frontend-report-studio.js` fills it from
 * the REST routes, so a front end that is not this plugin builds the same
 * report from the same answers.
 *
 * Save model A: the draft autosaves through `TT.Autosave` against
 * `PATCH /reports/{id}`, and publishing is its own confirmed control.
 *
 * The recipients' view of a published report is #4591; this is the
 * builder's, so it opens for someone who may edit the report only.
 */
final class FrontendReportStudioView extends FrontendViewBase {

    public const SLUG = 'report-studio';

    public static function render( int $user_id, bool $is_admin ): void {
        $title = __( 'Report Studio', 'talenttrack' );

        if ( ! current_user_can( 'tt_build_reports' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not build reports.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( ! LicenseGate::allows( 'report_studio' ) ) {
            FrontendBreadcrumbs::fromDashboard( $title, [ self::reportsCrumb() ] );
            echo UpgradePanel::render( 'report_studio' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- UpgradePanel returns escaped HTML
            return;
        }

        $id = isset( $_GET['id'] ) ? absint( wp_unslash( (string) $_GET['id'] ) ) : 0;
        if ( $id <= 0 ) {
            self::renderList( $user_id );
            return;
        }

        $report = ( new ReportsRepository() )->find( $id );
        $reader = ReportReader::for( $user_id );
        if ( $report === null ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Report not found', 'talenttrack' ), [ self::reportsCrumb(), self::studioCrumb() ] );
            echo '<p class="tt-notice">' . esc_html__( 'This report does not exist, or it was removed.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( ! ReportAccess::canEdit( $reader, $report ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ), [ self::reportsCrumb(), self::studioCrumb() ] );
            echo '<p class="tt-notice">' . esc_html__( 'You may not change this report.', 'talenttrack' ) . '</p>';
            return;
        }

        // The builder works on their draft's name (#4737).
        $draft      = ( new ReportsRepository() )->draft( $id, $user_id );
        $draft_name = $draft !== null ? $draft['name'] : $report['name'];
        $name       = $draft_name !== '' ? $draft_name : __( 'Untitled report', 'talenttrack' );
        FrontendBreadcrumbs::fromDashboard( $name, [ self::reportsCrumb(), self::studioCrumb() ] );
        self::enqueue( $id, $report['scope'] );
        self::renderCanvas( $draft_name, ReportNaming::changed( $report, $draft_name ) );
    }

    /** The builder's own reports, and the way to start one. */
    private static function renderList( int $user_id ): void {
        FrontendBreadcrumbs::fromDashboard( __( 'Report Studio', 'talenttrack' ), [ self::reportsCrumb() ] );
        self::enqueueStyle();

        $reader = ReportReader::for( $user_id );
        $repo   = new ReportsRepository();
        $list   = $repo->listFor(
            $user_id,
            $reader->personas(),
            [ 'kind' => '', 'scope' => '', 'owner' => 'me', 'archived' => 'active' ],
            ReportAccess::listedSubjects( $reader, [] ),
            false,
            1,
            100
        );

        echo '<div class="tt-rs-list">';
        echo '<header class="tt-rs-list-head">';
        echo '<h1 class="tt-fview-title">' . esc_html__( 'Report Studio', 'talenttrack' ) . '</h1>';
        if ( WizardRegistry::isAvailable( NewReportWizard::SLUG, $user_id ) ) {
            // Started from a player's or a team's page, the wizard begins
            // about that subject; it checks the subject itself.
            $extra = [];
            foreach ( [ 'player_id', 'team_id' ] as $key ) {
                $value = isset( $_GET[ $key ] ) ? absint( wp_unslash( (string) $_GET[ $key ] ) ) : 0;
                if ( $value > 0 ) $extra[ $key ] = $value;
            }
            $url = WizardEntryPoint::urlFor( NewReportWizard::SLUG, '', $extra );
            echo '<a class="tt-btn tt-btn-primary" href="' . esc_url( BackLink::appendTo( $url ) ) . '">' . esc_html__( 'Add report', 'talenttrack' ) . '</a>';
        }
        echo '</header>';

        if ( $list['rows'] === [] ) {
            echo '<p class="tt-notice">' . esc_html__( 'You have not made a report yet.', 'talenttrack' ) . '</p>';
            echo '</div>';
            return;
        }

        $draft_names = $repo->draftNames( array_column( $list['rows'], 'id' ), $user_id );

        echo '<ul class="tt-rs-reports">';
        foreach ( $list['rows'] as $row ) {
            // A report whose subject the builder no longer builds for stays
            // listed, without a link into a refusal. It is listed under the
            // builder's draft name, marked when readers still see another.
            $draft_name = $draft_names[ $row['id'] ] ?? $row['name'];
            $changed    = ReportNaming::changed( $row, $draft_name );
            $name       = $draft_name !== '' ? $draft_name : __( 'Untitled report', 'talenttrack' );
            $meta = ReportStartRules::label( 'kind', $row['kind'] ) . ' · ' . ReportStartRules::label( 'scope', $row['scope'] );
            $open = CrossViewLink::allows( self::SLUG, [ 'gate' => static fn(): bool => ReportAccess::canEdit( $reader, $row ) ] );
            echo '<li class="tt-rs-report">';
            if ( $open ) {
                $url = RecordLink::detailUrlForWithBack( self::SLUG, $row['id'] ); /* tt-xview-ok: gated by CrossViewLink::allows above */
                echo '<a class="tt-rs-report-link" href="' . esc_url( $url ) . '">';
            } else {
                echo '<span class="tt-rs-report-link">';
            }
            echo '<span class="tt-rs-report-name">' . esc_html( $name ) . '</span>';
            if ( $changed ) echo '<span class="tt-rs-name-changed">' . esc_html__( 'Changed since publish', 'talenttrack' ) . '</span>';
            echo '<span class="tt-rs-report-meta">' . esc_html( $meta ) . '</span>';
            echo $open ? '</a>' : '</span>';
            echo '</li>';
        }
        echo '</ul></div>';
    }

    private static function renderCanvas( string $name, bool $name_changed ): void {
        echo '<div class="tt-rs" data-tt-rs>';

        echo '<div class="tt-rs-top">';
        echo '<label class="tt-rs-sr" for="tt-rs-name">' . esc_html__( 'Report name', 'talenttrack' ) . '</label>';
        echo '<input type="text" id="tt-rs-name" class="tt-input tt-rs-name" data-tt-rs-name maxlength="' . esc_attr( (string) ReportStartRules::MAX_NAME ) . '" autocomplete="off" value="' . esc_attr( $name ) . '" aria-describedby="tt-rs-name-changed" />';
        // Readers keep the published name until the next publish (#4737).
        echo '<span class="tt-rs-name-changed" id="tt-rs-name-changed" data-tt-rs-name-changed' . ( $name_changed ? '' : ' hidden' ) . '>' . esc_html__( 'Changed since publish', 'talenttrack' ) . '</span>';
        SaveState::render( 'tt-rs-save-state' );
        echo '<div class="tt-rs-actions">';
        echo '<button type="button" class="tt-btn tt-btn-secondary tt-rs-open-library" data-tt-rs-open-library>' . esc_html__( 'Add block', 'talenttrack' ) . '</button>';
        echo '<button type="button" class="tt-btn tt-btn-secondary" data-tt-rs-open-versions>' . esc_html__( 'Versions', 'talenttrack' ) . '</button>';
        echo '<button type="button" class="tt-btn tt-btn-secondary" disabled aria-describedby="tt-rs-later">' . esc_html__( 'Share and schedule', 'talenttrack' ) . '</button>';
        echo '<button type="button" class="tt-btn tt-btn-secondary" disabled aria-describedby="tt-rs-later">' . esc_html__( 'Make PDF', 'talenttrack' ) . '</button>';
        echo '<button type="button" class="tt-btn tt-btn-primary" data-tt-rs-open-publish>' . esc_html__( 'Publish', 'talenttrack' ) . '</button>';
        echo '</div>';
        echo '<p class="tt-rs-hint" id="tt-rs-later">' . esc_html__( 'Sharing, scheduling and the PDF come in a later release.', 'talenttrack' ) . '</p>';
        echo '</div>';

        echo '<div class="tt-rs-context">';
        echo '<div class="tt-field tt-rs-subject" data-tt-rs-subject></div>';
        echo '<div class="tt-field"><label class="tt-field-label" for="tt-rs-from">' . esc_html__( 'From', 'talenttrack' ) . '</label>';
        echo '<input type="date" id="tt-rs-from" class="tt-input" data-tt-rs-from /></div>';
        echo '<div class="tt-field"><label class="tt-field-label" for="tt-rs-to">' . esc_html__( 'To', 'talenttrack' ) . '</label>';
        echo '<input type="date" id="tt-rs-to" class="tt-input" data-tt-rs-to /></div>';
        echo '<fieldset class="tt-rs-radios" data-tt-rs-audience></fieldset>';
        echo '<fieldset class="tt-rs-radios" data-tt-rs-view><legend>' . esc_html( _x( 'View', 'report studio view switch', 'talenttrack' ) ) . '</legend>';
        foreach ( [ 'screen' => __( 'Screen', 'talenttrack' ), 'a4' => __( 'A4', 'talenttrack' ), 'phone' => __( 'Phone', 'talenttrack' ) ] as $value => $label ) {
            echo '<label class="tt-rs-radio"><input type="radio" name="tt-rs-view" value="' . esc_attr( $value ) . '"' . checked( $value, 'screen', false ) . ' /><span>' . esc_html( $label ) . '</span></label>';
        }
        echo '</fieldset>';
        echo '</div>';

        echo '<p class="tt-rs-notice" data-tt-rs-notice role="status" hidden></p>';
        echo '<p class="tt-rs-sr" data-tt-rs-live aria-live="polite"></p>';

        echo '<div class="tt-rs-body">';
        echo '<div class="tt-rs-main">';
        echo '<div class="tt-rs-meter" data-tt-rs-meter hidden></div>';
        echo '<ol class="tt-rs-canvas" data-tt-rs-canvas data-view="screen" aria-label="' . esc_attr__( 'Report blocks', 'talenttrack' ) . '"></ol>';
        echo '</div>';

        echo '<dialog class="tt-rs-sheet tt-rs-library" data-tt-rs-library aria-labelledby="tt-rs-library-title">';
        echo '<header class="tt-rs-sheet-head"><h2 id="tt-rs-library-title">' . esc_html__( 'Blocks', 'talenttrack' ) . '</h2>';
        echo '<button type="button" class="tt-btn tt-btn-secondary tt-rs-sheet-close" data-tt-rs-close>' . esc_html__( 'Close', 'talenttrack' ) . '</button></header>';
        echo '<label class="tt-field-label" for="tt-rs-search">' . esc_html__( 'Search blocks', 'talenttrack' ) . '</label>';
        echo '<input type="search" id="tt-rs-search" class="tt-input" data-tt-rs-search autocomplete="off" />';
        echo '<ul class="tt-rs-lib" data-tt-rs-library-list></ul>';
        echo '</dialog>';

        echo '<dialog class="tt-rs-sheet tt-rs-settings" data-tt-rs-settings aria-labelledby="tt-rs-settings-title">';
        echo '<header class="tt-rs-sheet-head"><h2 id="tt-rs-settings-title" data-tt-rs-settings-title>' . esc_html__( 'Settings', 'talenttrack' ) . '</h2>';
        echo '<button type="button" class="tt-btn tt-btn-secondary tt-rs-sheet-close" data-tt-rs-close>' . esc_html__( 'Close', 'talenttrack' ) . '</button></header>';
        echo '<div data-tt-rs-settings-body></div>';
        echo '</dialog>';
        echo '</div>';

        echo '<dialog class="tt-rs-dialog" data-tt-rs-versions aria-labelledby="tt-rs-versions-title">';
        echo '<header class="tt-rs-sheet-head"><h2 id="tt-rs-versions-title">' . esc_html__( 'Versions', 'talenttrack' ) . '</h2>';
        echo '<button type="button" class="tt-btn tt-btn-secondary tt-rs-sheet-close" data-tt-rs-close>' . esc_html__( 'Close', 'talenttrack' ) . '</button></header>';
        echo '<p class="tt-rs-hint">' . esc_html__( 'Using a version puts it in your draft. Publish to make it live.', 'talenttrack' ) . '</p>';
        echo '<ul class="tt-rs-versions" data-tt-rs-versions-list></ul>';
        echo '</dialog>';

        self::renderPublishDialog();
        echo '</div>';
    }

    private static function renderPublishDialog(): void {
        echo '<dialog class="tt-rs-dialog" data-tt-rs-publish aria-labelledby="tt-rs-publish-title">';
        echo '<form method="dialog" class="tt-rs-publish-form">';
        echo '<h2 id="tt-rs-publish-title">' . esc_html__( 'Publish this report', 'talenttrack' ) . '</h2>';
        echo '<fieldset class="tt-rs-radios" data-tt-rs-personas hidden><legend>' . esc_html__( 'Publish for', 'talenttrack' ) . '</legend>';
        foreach ( PersonaLabels::forKeys( ReportAccess::PUBLISHABLE_PERSONAS ) as $key => $label ) {
            echo '<label class="tt-rs-radio"><input type="checkbox" value="' . esc_attr( $key ) . '" /><span>' . esc_html( $label ) . '</span></label>';
        }
        echo '</fieldset>';
        echo '<p class="tt-rs-reach" data-tt-rs-reach role="status"></p>';
        echo '<div class="tt-field"><label class="tt-field-label" for="tt-rs-note">' . esc_html__( 'What changed', 'talenttrack' ) . '</label>';
        echo '<textarea id="tt-rs-note" class="tt-input" data-tt-rs-note maxlength="500" rows="3" required></textarea></div>';
        echo '<p class="tt-rs-error" data-tt-rs-publish-error role="alert"></p>';
        echo '<div class="tt-form-actions">';
        echo '<button type="submit" class="tt-btn tt-btn-secondary" value="cancel" formnovalidate>' . esc_html__( 'Cancel', 'talenttrack' ) . '</button>';
        echo '<button type="submit" class="tt-btn tt-btn-primary" value="confirm">' . esc_html__( 'Publish', 'talenttrack' ) . '</button>';
        echo '</div>';
        echo '</form>';
        echo '</dialog>';
    }

    private static function enqueueStyle(): void {
        self::enqueueAssets();
        wp_enqueue_style( 'tt-report-studio', TT_PLUGIN_URL . 'assets/css/frontend-report-studio.css', [], TT_VERSION );
    }

    /** The canvas script, on this view only, with the words it shows. */
    private static function enqueue( int $id, string $scope ): void {
        self::enqueueStyle();
        SaveState::enqueue();
        wp_enqueue_script( 'tt-report-blocks', TT_PLUGIN_URL . 'assets/js/tt-report-blocks.js', [], TT_VERSION, true );
        wp_enqueue_script( 'tt-report-studio', TT_PLUGIN_URL . 'assets/js/frontend-report-studio.js', [ 'tt-autosave', 'tt-report-blocks' ], TT_VERSION, true );
        wp_localize_script( 'tt-report-studio', 'TTReportStudio', [
            'id'         => $id,
            'rest'       => esc_url_raw( rest_url( 'talenttrack/v1/' ) ),
            'nonce'      => wp_create_nonce( 'wp_rest' ),
            'maxBlocks'  => ReportDefinition::MAX_BLOCKS,
            'scopeLabel' => ReportStartRules::label( 'scope', $scope ),
            'columns'    => self::columns(),
            'i18n'       => self::words(),
        ] );
    }

    /**
     * Column headings for the payload keys a block's rows carry.
     *
     * @return array<string,string>
     */
    public static function columns(): array {
        return [
            'week'              => _x( 'Week', 'report studio column', 'talenttrack' ),
            'attendance_pct'    => _x( 'Attendance %', 'report studio column', 'talenttrack' ),
            'present'           => _x( 'Present', 'report studio column', 'talenttrack' ),
            'absent'            => _x( 'Absent', 'report studio column', 'talenttrack' ),
            'name'              => _x( 'Player', 'report studio column', 'talenttrack' ),
            'total_minutes'     => _x( 'Minutes', 'report studio column', 'talenttrack' ),
            'matches'           => _x( 'Matches', 'report studio column', 'talenttrack' ),
            'starts'            => _x( 'Starts', 'report studio column', 'talenttrack' ),
            'available_minutes' => _x( 'Minutes available', 'report studio column', 'talenttrack' ),
            'label'             => _x( 'Group', 'report studio column', 'talenttrack' ),
            'value'             => _x( 'Value', 'report studio column', 'talenttrack' ),
        ];
    }

    /** @return array<string,string> */
    private static function words(): array {
        return [
            'loadError'   => __( 'The report could not be loaded. Reload the page to try again.', 'talenttrack' ),
            'stale'       => __( 'This report was changed in another tab. Loading the newest version…', 'talenttrack' ),
            'reloaded'    => __( 'This report was changed in another tab, so the newest version is loaded.', 'talenttrack' ),
            /* translators: %d: maximum number of blocks */
            'tooMany'     => __( 'A report holds at most %d blocks.', 'talenttrack' ),
            /* translators: %s: block name */
            'added'       => __( '%s added.', 'talenttrack' ),
            /* translators: 1: block name, 2: its new position */
            'moved'       => __( '%1$s moved to place %2$s.', 'talenttrack' ),
            /* translators: %s: block name */
            'removed'     => __( '%s removed.', 'talenttrack' ),
            'block'       => _x( 'Block', 'report studio', 'talenttrack' ),
            /* translators: %s: block name */
            'dragAdd'     => __( 'Drag %s onto the report', 'talenttrack' ),
            'noBlocks'    => __( 'No blocks match your search.', 'talenttrack' ),
            'emptyCanvas' => __( 'Add a block to start the report.', 'talenttrack' ),
            /* translators: %d: page number */
            'page'        => __( 'Page %d', 'talenttrack' ),
            /* translators: 1: block name, 2: its position, 3: how many blocks */
            'blockAt'     => __( '%1$s, block %2$s of %3$s', 'talenttrack' ),
            /* translators: %s: block name */
            'dragMove'    => __( 'Drag to move %s', 'talenttrack' ),
            /* translators: %s: block name */
            'toolbar'     => __( 'Change %s', 'talenttrack' ),
            'up'          => __( 'Move up', 'talenttrack' ),
            'down'        => __( 'Move down', 'talenttrack' ),
            'makeFull'    => __( 'Make full width', 'talenttrack' ),
            'makeHalf'    => __( 'Make half width', 'talenttrack' ),
            'duplicate'   => __( 'Duplicate', 'talenttrack' ),
            'settings'    => __( 'Settings', 'talenttrack' ),
            'remove'      => __( 'Remove', 'talenttrack' ),
            'loading'     => __( 'Loading…', 'talenttrack' ),
            /* translators: 1: first day, 2: last day of the period */
            'nothing'     => __( 'Nothing recorded between %1$s and %2$s.', 'talenttrack' ),
            'truncated'   => __( 'Only the first rows are shown.', 'talenttrack' ),
            'value'       => _x( 'Value', 'report studio column', 'talenttrack' ),
            /* translators: %s: how full the page is, in percent */
            'onePage'     => __( 'One A4 page, %s%% full.', 'talenttrack' ),
            /* translators: 1: number of pages, 2: how full the last page is, in percent */
            'pages'       => __( '%1$s A4 pages; the last is %2$s%% full.', 'talenttrack' ),
            'noOptions'   => __( 'This block has no settings.', 'talenttrack' ),
            'choose'      => __( 'Choose…', 'talenttrack' ),
            'none'        => __( 'None', 'talenttrack' ),
            'whoSees'     => __( 'Who sees it', 'talenttrack' ),
            'source'      => __( 'Where the figures come from', 'talenttrack' ),
            'player'      => __( 'Player', 'talenttrack' ),
            'team'        => __( 'Team', 'talenttrack' ),
            'subject'     => __( 'About', 'talenttrack' ),
            'audience'    => __( 'Read as', 'talenttrack' ),
            'noVersions'  => __( 'This report has not been published yet.', 'talenttrack' ),
            /* translators: 1: version number, 2: date published */
            'version'     => __( 'Version %1$s, %2$s', 'talenttrack' ),
            'useVersion'  => __( 'Use this version', 'talenttrack' ),
            /* translators: %s: version number */
            'versionUsed' => __( 'Version %s is now your draft.', 'talenttrack' ),
            'reachMe'     => __( 'Only you will read this version.', 'talenttrack' ),
            'counting'    => __( 'Counting who it reaches…', 'talenttrack' ),
            /* translators: 1: number of people, 2: the roles */
            'reachOne'    => __( 'This reaches %1$s person: %2$s.', 'talenttrack' ),
            /* translators: 1: number of people, 2: the roles */
            'reachMany'   => __( 'This reaches %1$s people: %2$s.', 'talenttrack' ),
            'noteNeeded'  => __( 'Say what changed in this version.', 'talenttrack' ),
            /* translators: %s: version number */
            'published'   => __( 'Version %s is published.', 'talenttrack' ),
        ];
    }

    /** @return array{label:string,url:string} */
    private static function reportsCrumb(): array {
        return FrontendBreadcrumbs::viewCrumb( 'reports', __( 'Reports', 'talenttrack' ) );
    }

    /** @return array{label:string,url:string} */
    private static function studioCrumb(): array {
        return FrontendBreadcrumbs::viewCrumb( self::SLUG, __( 'Report Studio', 'talenttrack' ) );
    }
}
