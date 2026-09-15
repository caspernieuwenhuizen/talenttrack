<?php
namespace TT\Modules\Analytics\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PotentialOverviewQuery;
use TT\Modules\Players\Frontend\PlayerStatusVisibility;
use TT\Modules\Players\PlayerStatusModule;
use TT\Modules\Players\Services\PotentialRecorder;
use TT\Modules\Players\Services\PotentialTrajectory;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendAppChrome;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendPotentialOverviewView (#3412) — a squad's potential, in one
 * place, editable.
 *
 * #3385 asked whether a head of development could see every player in an
 * age group with their current potential band, sorted. The answer was no:
 * the band appeared nowhere that showed more than one player, and the one
 * cross-player surface reading potential — the traffic-light dot — folds
 * it into a composite that can be neither sorted nor filtered by.
 *
 * This is that surface. It also absorbs #3386 (a bulk potential capture
 * grid), which resolved into this issue: the screen that shows a squad's
 * potential is the natural place to edit it, and building a second one
 * would mean two surfaces disagreeing about what a band means.
 *
 * ## The decisions this implements (#3412, 2026-09-15)
 *
 *  - **An Analytics report**, because it is read periodically for review
 *    rather than daily. That crosses a line — Analytics held only counts
 *    and durations before — which is why it is gated below rather than
 *    simply added.
 *  - **Scope is selectable**: one team, or every team sharing an age-group
 *    label. "The U15s" is a squad to one academy and a cohort to another.
 *  - **Visibility inherits `PlayerStatusVisibility`**, via
 *    `squadVisibleTo()` — see that method for why the squad case is the
 *    dot's rule narrowed rather than a second rule.
 *  - **Trajectory is shown**: a band that moved from Foundation to
 *    Semi-pro is a different conversation from one that never moved.
 *  - **Players with nothing recorded are rows.** 39 of 64 active players
 *    on the demo install have no band, and a list that hid them would tell
 *    a head of development the opposite of the truth.
 *
 * ## Save model — B, explicit Save with a real Cancel (CLAUDE.md §6)
 *
 * Deliberately not autosave. This is a grid: a coach works across a squad
 * and a half-finished commit is worse than a lost one, so it gets one
 * commit point and a Cancel that means cancel — the same model the
 * attendance, minutes and ratings grids use. It also needs no JavaScript,
 * which is what keeps it keyboard-reachable and usable on the connection
 * a coach actually has at a pitch.
 *
 * All data comes from `PotentialOverviewQuery` and every write goes
 * through `PotentialRecorder` — the same path the per-player capture
 * popover uses via REST. Nothing here decides anything (CLAUDE.md §4).
 */
final class FrontendPotentialOverviewView extends FrontendViewBase {

    public const SLUG = 'potential-overview';

    private const NONCE_ACTION = 'tt_potential_overview_save';
    private const NONCE_FIELD  = 'tt_po_nonce';

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-potential-overview',
            TT_PLUGIN_URL . 'assets/css/frontend-potential-overview.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );

        if ( ! current_user_can( 'tt_view_analytics' ) || ! current_user_can( 'tt_view_player_status' ) ) {
            self::denied( __( 'You do not have permission to view this report.', 'talenttrack' ) );
            return;
        }

        // The squad gate, not the per-player one. A potential band is a
        // staff judgement about a minor; a ranked list of them across a
        // squad is more exposing than one colour about one child, and a
        // player or parent reaches nothing here whatever the dot toggle says.
        if ( ! PlayerStatusVisibility::squadVisibleTo( $user_id ) ) {
            self::denied( __( 'Potential is staff-only. Your own potential is on your player file.', 'talenttrack' ) );
            return;
        }

        if ( ! \TT\Core\FeatureRegistry::isEnabled( 'analytics_potential_overview' ) ) {
            self::denied( __( 'This report has been switched off for your academy.', 'talenttrack' ) );
            return;
        }

        $query  = new PotentialOverviewQuery();
        $filter = self::resolveFilters( $user_id, $query );

        // The save runs before anything renders so the table below shows
        // the rows as they are now, not as they were when the page loaded.
        $notice = self::maybeSave( $user_id, $query, $filter );

        FrontendBreadcrumbs::fromDashboard(
            __( 'Potential overview', 'talenttrack' ),
            [ FrontendBreadcrumbs::viewCrumb( 'reports', __( 'Reports', 'talenttrack' ) ) ]
        );
        self::renderHeader( __( 'Potential across a squad', 'talenttrack' ) );

        echo '<p class="tt-po-intro">' . esc_html__(
            'Every player in the chosen scope with the potential band the academy has recorded for them, how it has moved, and who set it. Players with nothing recorded appear too — they are the point.',
            'talenttrack'
        ) . '</p>';

        if ( $notice !== '' ) {
            echo '<p class="tt-notice tt-notice--ok" role="status">' . esc_html( $notice ) . '</p>';
        }

        self::renderFilters( $user_id, $query, $filter );

        $teams = $query->teamsInScope( $user_id, $filter['scope'], $filter['team_id'], $filter['age_group'] );
        if ( $teams === [] ) {
            echo '<p class="tt-notice">' . esc_html__( 'Pick a team or an age group to see its players.', 'talenttrack' ) . '</p>';
            return;
        }

        $rows = $query->rows(
            $user_id,
            $filter['scope'],
            $filter['team_id'],
            $filter['age_group'],
            $filter['bands'],
            $filter['sort'],
            $filter['dir']
        );

        // The summary counts the unfiltered scope: a band filter is a lens
        // on the squad, not a redefinition of it, and a coverage figure
        // that moved when you ticked a box would be meaningless.
        $all = $filter['bands'] === []
            ? $rows
            : $query->rows( $user_id, $filter['scope'], $filter['team_id'], $filter['age_group'] );

        self::renderSummary( PotentialOverviewQuery::summarise( $all ) );

        if ( $rows === [] ) {
            echo '<p class="tt-notice">' . esc_html__( 'No players match these filters.', 'talenttrack' ) . '</p>';
            return;
        }

        self::renderGrid( $rows, $filter );
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array{scope:string,team_id:int,age_group:string,bands:list<string>,sort:string,dir:string} $filter
     */
    private static function renderGrid( array $rows, array $filter ): void {
        $editable = PlayerStatusModule::potentialCaptureAvailable();
        $bands    = PotentialTrajectory::labels();
        $show_team = $filter['scope'] === PotentialOverviewQuery::SCOPE_AGE_GROUP;

        if ( $editable ) {
            echo '<form method="post" class="tt-po-form" action="' . esc_url( self::currentUrl( $filter ) ) . '">';
            wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
        }

        echo '<section class="tt-po-card" aria-labelledby="tt-po-table-title">';
        echo '<h2 id="tt-po-table-title" class="tt-po-card__title">' . esc_html__( 'Players', 'talenttrack' ) . '</h2>';
        echo '<div class="tt-po-scroll">';
        echo '<table class="tt-po-table">';

        echo '<thead><tr>';
        echo '<th scope="col">' . self::sortLink( 'name', __( 'Player', 'talenttrack' ), $filter ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — sortLink escapes internally.
        if ( $show_team ) {
            echo '<th scope="col">' . self::sortLink( 'team', __( 'Team', 'talenttrack' ), $filter ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        // `_x()`: "Potential" alone already carries two different senses in
        // this product's Dutch, and a bare one-word msgid inherits whichever
        // one the translator saw first.
        echo '<th scope="col">' . self::sortLink( 'band', _x( 'Potential', 'squad potential report column header', 'talenttrack' ), $filter ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<th scope="col">' . esc_html__( 'Movement', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . self::sortLink( 'date', __( 'Recorded', 'talenttrack' ), $filter ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            $url = RecordLink::detailUrlForWithBack( 'players', $row['player_id'] );

            echo '<tr class="tt-po-row' . ( $row['recorded'] ? '' : ' tt-po-row--unrecorded' ) . '">';

            echo '<th scope="row" class="tt-po-cell tt-po-cell--player">';
            echo '<a class="tt-record-link" href="' . esc_url( $url ) . '">' . esc_html( $row['player_name'] ) . '</a>';
            echo '</th>';

            if ( $show_team ) {
                echo '<td class="tt-po-cell" data-label="' . esc_attr__( 'Team', 'talenttrack' ) . '">'
                    . esc_html( $row['team_name'] !== '' ? $row['team_name'] : '—' ) . '</td>';
            }

            echo '<td class="tt-po-cell tt-po-cell--band" data-label="' . esc_attr( _x( 'Potential', 'squad potential report column header', 'talenttrack' ) ) . '">';
            echo self::bandCell( $row, $bands, $editable ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — bandCell escapes internally.
            echo '</td>';

            echo '<td class="tt-po-cell tt-po-cell--move" data-label="' . esc_attr__( 'Movement', 'talenttrack' ) . '">';
            echo self::movementCell( $row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — movementCell escapes internally.
            echo '</td>';

            echo '<td class="tt-po-cell tt-po-cell--when" data-label="' . esc_attr__( 'Recorded', 'talenttrack' ) . '">';
            echo self::recordedCell( $row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — recordedCell escapes internally.
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
        echo '</section>';

        if ( $editable ) {
            echo '<p class="tt-po-save-help">' . esc_html__(
                'Changes are saved when you press Save. Leaving a player on “Not recorded” records nothing for them.',
                'talenttrack'
            ) . '</p>';
            // §6 model B: one commit point, and a Cancel that returns the
            // reader to the report as it stands rather than to a half-edited
            // page. `tt_back` overrides the fallback inside the helper.
            echo FormSaveButton::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — the component escapes internally.
                'label'      => __( 'Save bands', 'talenttrack' ),
                'cancel_url' => self::currentUrl( $filter ),
            ] );
            echo '</form>';
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,string> $bands
     */
    private static function bandCell( array $row, array $bands, bool $editable ): string {
        // #3265 — below the age floor the academy is not asked. Saying so
        // is not the same as saying "nobody has got round to it", and
        // rendering a picker there would invite exactly the judgement the
        // floor exists to avoid.
        if ( ! $row['eligible'] ) {
            return '<span class="tt-po-band tt-po-band--na">'
                . esc_html( sprintf(
                    /* translators: %d is the minimum age in years, e.g. 13. */
                    __( 'Not asked below %d', 'talenttrack' ),
                    PlayerStatusModule::POTENTIAL_MIN_AGE
                ) )
                . '</span>';
        }

        if ( ! $editable ) {
            return $row['recorded']
                ? '<span class="tt-po-band">' . esc_html( (string) $row['band_label'] ) . '</span>'
                : '<span class="tt-po-band tt-po-band--none">' . esc_html__( 'Not recorded', 'talenttrack' ) . '</span>';
        }

        $id  = 'tt-po-band-' . (int) $row['player_id'];
        $out = '<label class="tt-screen-reader-text" for="' . esc_attr( $id ) . '">'
            . esc_html( sprintf(
                /* translators: %s is the player's name. */
                __( 'Potential band for %s', 'talenttrack' ),
                (string) $row['player_name']
            ) )
            . '</label>';

        $out .= '<select class="tt-input tt-po-select" id="' . esc_attr( $id ) . '"'
            . ' name="tt_po_band[' . (int) $row['player_id'] . ']">';
        $out .= '<option value="">' . esc_html__( 'Not recorded', 'talenttrack' ) . '</option>';
        foreach ( $bands as $key => $label ) {
            $out .= '<option value="' . esc_attr( (string) $key ) . '"'
                . selected( (string) $row['band'], (string) $key, false ) . '>'
                . esc_html( (string) $label ) . '</option>';
        }
        $out .= '</select>';

        return $out;
    }

    /**
     * Direction plus the band it moved from. An arrow alone would be a
     * colour-free version of the same problem the dot had: it says
     * something changed without saying from what.
     *
     * @param array<string,mixed> $row
     */
    private static function movementCell( array $row ): string {
        if ( ! $row['recorded'] ) {
            return '<span class="tt-po-move tt-po-move--none">—</span>';
        }

        $direction = (string) $row['direction'];
        $previous  = (string) $row['previous_band_label'];

        if ( $direction === PotentialTrajectory::FIRST || $previous === '' ) {
            return '<span class="tt-po-move tt-po-move--first">' . esc_html__( 'First entry', 'talenttrack' ) . '</span>';
        }

        if ( $direction === PotentialTrajectory::SAME ) {
            return '<span class="tt-po-move tt-po-move--same">' . esc_html__( 'Reaffirmed', 'talenttrack' ) . '</span>';
        }

        $up    = $direction === PotentialTrajectory::UP;
        $arrow = $up ? '&uarr;' : '&darr;';
        $text  = $up
            /* translators: %s is the band the player was previously on. */
            ? sprintf( __( 'Raised from %s', 'talenttrack' ), $previous )
            /* translators: %s is the band the player was previously on. */
            : sprintf( __( 'Lowered from %s', 'talenttrack' ), $previous );

        return '<span class="tt-po-move tt-po-move--' . ( $up ? 'up' : 'down' ) . '">'
            . '<span class="tt-po-move__arrow" aria-hidden="true">' . $arrow . '</span>'
            . '<span class="tt-po-move__text">' . esc_html( $text ) . '</span>'
            . '</span>';
    }

    /** @param array<string,mixed> $row */
    private static function recordedCell( array $row ): string {
        if ( ! $row['recorded'] ) {
            return '<span class="tt-po-when tt-po-when--none">' . esc_html__( 'Never', 'talenttrack' ) . '</span>';
        }

        $when   = (string) $row['set_at'];
        $format = (string) get_option( 'date_format' );
        $date   = $when !== '' ? (string) mysql2date( $format, $when ) : '';
        $who    = (string) $row['set_by_name'];

        $out = '<span class="tt-po-when__date">' . esc_html( $date !== '' ? $date : $when ) . '</span>';
        if ( $who !== '' ) {
            $out .= '<span class="tt-po-when__who">' . esc_html( sprintf(
                /* translators: %s is the name of the staff member who set the band. */
                __( 'by %s', 'talenttrack' ),
                $who
            ) ) . '</span>';
        }
        if ( (int) $row['revisions'] > 1 ) {
            $out .= '<span class="tt-po-when__count">' . esc_html( sprintf(
                /* translators: %d is the number of potential entries on record. */
                _n( '%d entry', '%d entries', (int) $row['revisions'], 'talenttrack' ),
                (int) $row['revisions']
            ) ) . '</span>';
        }
        return $out;
    }

    /**
     * @param array{players:int,recorded:int,missing:int,not_asked:int,coverage:?float} $summary
     */
    private static function renderSummary( array $summary ): void {
        $coverage = $summary['coverage'] === null
            ? '—'
            : number_format_i18n( $summary['coverage'], 0 ) . '%';

        echo '<div class="tt-po-kpis">';
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped — kpiTile() escapes internally.
        echo FrontendAppChrome::kpiTile( [ 'label' => __( 'Players', 'talenttrack' ),      'value' => (string) $summary['players'] ] );
        echo FrontendAppChrome::kpiTile( [ 'label' => __( 'Band recorded', 'talenttrack' ), 'value' => (string) $summary['recorded'] ] );
        echo FrontendAppChrome::kpiTile( [
            'label' => __( 'No band yet', 'talenttrack' ),
            'value' => (string) $summary['missing'],
            'flag'  => $summary['missing'] > 0 ? 'red' : 'green',
        ] );
        echo FrontendAppChrome::kpiTile( [ 'label' => __( 'Coverage', 'talenttrack' ), 'value' => $coverage ] );
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div>';

        if ( $summary['not_asked'] > 0 ) {
            echo '<p class="tt-po-footnote">' . esc_html( sprintf(
                /* translators: 1: number of players, 2: minimum age in years. */
                _n(
                    '%1$d player is below %2$d, so the academy is not asked for a band and they are not counted as a gap.',
                    '%1$d players are below %2$d, so the academy is not asked for a band and they are not counted as gaps.',
                    $summary['not_asked'],
                    'talenttrack'
                ),
                $summary['not_asked'],
                PlayerStatusModule::POTENTIAL_MIN_AGE
            ) ) . '</p>';
        }
    }

    /**
     * Scope + band filters, as a plain GET form so the resulting view is a
     * URL somebody can bookmark or send to a colleague.
     *
     * @param array{scope:string,team_id:int,age_group:string,bands:list<string>,sort:string,dir:string} $filter
     */
    private static function renderFilters( int $user_id, PotentialOverviewQuery $query, array $filter ): void {
        $teams      = $query->readableTeams( $user_id );
        $age_groups = $query->ageGroupsFor( $user_id );
        $selected   = $filter['scope'] === PotentialOverviewQuery::SCOPE_AGE_GROUP
            ? 'age:' . $filter['age_group']
            : 'team:' . $filter['team_id'];

        echo '<form method="get" class="tt-po-filters">';
        echo '<input type="hidden" name="tt_view" value="' . esc_attr( self::SLUG ) . '" />';
        echo '<input type="hidden" name="sort" value="' . esc_attr( $filter['sort'] ) . '" />';
        echo '<input type="hidden" name="dir" value="' . esc_attr( $filter['dir'] ) . '" />';

        // One control rather than a scope radio plus two dependent
        // dropdowns: without JavaScript the dependent pair is a puzzle, and
        // "which of these did it use?" is not a question a filter should
        // raise. `scope_value` is the view's shorthand; the explicit
        // `scope` / `team_id` / `age_group` triple the REST route takes is
        // still honoured on the way in and is what every link out carries.
        echo '<label class="tt-po-field" for="tt-po-scope">';
        echo '<span class="tt-po-field__label">' . esc_html__( 'Scope', 'talenttrack' ) . '</span>';
        echo '<select id="tt-po-scope" name="scope_value" class="tt-input">';

        if ( $age_groups !== [] ) {
            echo '<optgroup label="' . esc_attr__( 'Age group', 'talenttrack' ) . '">';
            foreach ( $age_groups as $label ) {
                $value = 'age:' . $label;
                echo '<option value="' . esc_attr( $value ) . '"' . selected( $selected, $value, false ) . '>'
                    . esc_html( $label ) . '</option>';
            }
            echo '</optgroup>';
        }

        echo '<optgroup label="' . esc_attr__( 'Team', 'talenttrack' ) . '">';
        foreach ( $teams as $team ) {
            $value = 'team:' . $team['team_id'];
            echo '<option value="' . esc_attr( $value ) . '"' . selected( $selected, $value, false ) . '>'
                . esc_html( $team['team_name'] ) . '</option>';
        }
        echo '</optgroup>';
        echo '</select>';
        echo '</label>';

        echo '<fieldset class="tt-po-bands">';
        echo '<legend class="tt-po-field__label">' . esc_html__( 'Show bands', 'talenttrack' ) . '</legend>';
        $choices = PotentialTrajectory::labels();
        $choices[ PotentialOverviewQuery::BAND_NONE ] = __( 'Not recorded', 'talenttrack' );
        foreach ( $choices as $key => $label ) {
            $checked = in_array( (string) $key, $filter['bands'], true );
            echo '<label class="tt-po-band-choice">';
            echo '<input type="checkbox" name="bands[]" value="' . esc_attr( (string) $key ) . '"' . checked( $checked, true, false ) . ' />';
            echo '<span>' . esc_html( (string) $label ) . '</span>';
            echo '</label>';
        }
        echo '<p class="tt-po-hint">' . esc_html__( 'Leave every box clear to show the whole squad.', 'talenttrack' ) . '</p>';
        echo '</fieldset>';

        echo '<button type="submit" class="tt-btn tt-btn-primary tt-po-filters__submit">'
            . esc_html__( 'Show', 'talenttrack' ) . '</button>';
        echo '</form>';
    }

    /**
     * Read the request into the filter shape the query takes.
     *
     * Defaults to the caller's first readable team, so the report is
     * useful on arrival instead of asking a question before showing
     * anything.
     *
     * @return array{scope:string,team_id:int,age_group:string,bands:list<string>,sort:string,dir:string}
     */
    private static function resolveFilters( int $user_id, PotentialOverviewQuery $query ): array {
        $scope     = PotentialOverviewQuery::sanitizeScope(
            isset( $_GET['scope'] ) ? sanitize_key( wp_unslash( (string) $_GET['scope'] ) ) : ''
        );
        $team_id   = isset( $_GET['team_id'] ) ? absint( wp_unslash( $_GET['team_id'] ) ) : 0;
        $age_group = isset( $_GET['age_group'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['age_group'] ) ) : '';

        // The single-control shorthand wins when present.
        if ( isset( $_GET['scope_value'] ) ) {
            $raw = sanitize_text_field( wp_unslash( (string) $_GET['scope_value'] ) );
            if ( strpos( $raw, 'age:' ) === 0 ) {
                $scope     = PotentialOverviewQuery::SCOPE_AGE_GROUP;
                $age_group = substr( $raw, 4 );
                $team_id   = 0;
            } elseif ( strpos( $raw, 'team:' ) === 0 ) {
                $scope     = PotentialOverviewQuery::SCOPE_TEAM;
                $team_id   = absint( substr( $raw, 5 ) );
                $age_group = '';
            }
        }

        if ( $scope === PotentialOverviewQuery::SCOPE_TEAM && $team_id <= 0 ) {
            $teams   = $query->readableTeams( $user_id );
            $team_id = $teams === [] ? 0 : $teams[0]['team_id'];
        }

        $bands = [];
        if ( isset( $_GET['bands'] ) && is_array( $_GET['bands'] ) ) {
            $bands = PotentialOverviewQuery::sanitizeBands( array_map(
                'sanitize_key',
                array_map( 'strval', wp_unslash( $_GET['bands'] ) )
            ) );
        }

        return [
            'scope'     => $scope,
            'team_id'   => $team_id,
            'age_group' => $age_group,
            'bands'     => $bands,
            'sort'      => PotentialOverviewQuery::sanitizeSort(
                isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( (string) $_GET['sort'] ) ) : ''
            ),
            'dir'       => PotentialOverviewQuery::sanitizeDir(
                isset( $_GET['dir'] ) ? sanitize_key( wp_unslash( (string) $_GET['dir'] ) ) : ''
            ),
        ];
    }

    /**
     * Commit the submitted bands, or return '' when nothing was submitted.
     *
     * Every write goes through `PotentialRecorder`, and every player id is
     * checked against the rows the caller may actually read — a POST can
     * name any id, and a grid that trusted its own hidden fields would let
     * a coach set a band on a squad they cannot open.
     *
     * @param array{scope:string,team_id:int,age_group:string,bands:list<string>,sort:string,dir:string} $filter
     */
    private static function maybeSave( int $user_id, PotentialOverviewQuery $query, array $filter ): string {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) return '';
        if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) return '';

        $nonce = sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) );
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) return '';
        if ( ! PlayerStatusModule::potentialCaptureAvailable() ) return '';

        $submitted = isset( $_POST['tt_po_band'] ) && is_array( $_POST['tt_po_band'] )
            ? wp_unslash( $_POST['tt_po_band'] )
            : [];
        if ( $submitted === [] ) return '';

        $allowed = [];
        foreach ( $query->rows( $user_id, $filter['scope'], $filter['team_id'], $filter['age_group'] ) as $row ) {
            $allowed[ $row['player_id'] ] = true;
        }

        $recorder = new PotentialRecorder();
        $saved    = 0;
        $skipped  = 0;

        foreach ( (array) $submitted as $player_id => $band ) {
            $player_id = (int) $player_id;
            $band      = sanitize_key( (string) $band );
            if ( $band === '' ) continue;
            if ( ! isset( $allowed[ $player_id ] ) ) { $skipped++; continue; }

            $outcome = $recorder->record( $player_id, $band );
            if ( $outcome['result'] === PotentialRecorder::RECORDED ) { $saved++; continue; }
            if ( $outcome['result'] === PotentialRecorder::UNCHANGED ) continue;
            $skipped++;
        }

        if ( $saved === 0 && $skipped === 0 ) {
            return __( 'Nothing changed.', 'talenttrack' );
        }

        $message = sprintf(
            /* translators: %d is the number of players whose band was recorded. */
            _n( '%d band recorded.', '%d bands recorded.', $saved, 'talenttrack' ),
            $saved
        );

        if ( $skipped > 0 ) {
            $message .= ' ' . sprintf(
                /* translators: %d is the number of players whose band could not be recorded. */
                _n(
                    '%d player was skipped — they are outside this scope or below the age the band is asked at.',
                    '%d players were skipped — they are outside this scope or below the age the band is asked at.',
                    $skipped,
                    'talenttrack'
                ),
                $skipped
            );
        }

        return $message;
    }

    /**
     * A sortable column header. Clicking the active column flips the
     * direction; a plain link so it works without JavaScript and keeps its
     * place in the tab order.
     *
     * @param array{scope:string,team_id:int,age_group:string,bands:list<string>,sort:string,dir:string} $filter
     */
    private static function sortLink( string $key, string $label, array $filter ): string {
        $active = $filter['sort'] === $key;
        $dir    = $active && $filter['dir'] === 'asc' ? 'desc' : 'asc';

        $url = self::currentUrl( array_merge( $filter, [ 'sort' => $key, 'dir' => $dir ] ) );

        $indicator = '';
        if ( $active ) {
            $indicator = '<span class="tt-po-sort__dir" aria-hidden="true">'
                . ( $filter['dir'] === 'asc' ? '&uarr;' : '&darr;' ) . '</span>';
        }

        return '<a class="tt-po-sort' . ( $active ? ' is-active' : '' ) . '" href="' . esc_url( $url ) . '"'
            . ( $active ? ' aria-current="true"' : '' ) . '>'
            . esc_html( $label ) . $indicator . '</a>';
    }

    /**
     * This report's URL with an explicit filter set — the same triple the
     * REST route takes, so a link out of here describes the same query.
     *
     * @param array{scope:string,team_id:int,age_group:string,bands:list<string>,sort:string,dir:string} $filter
     */
    private static function currentUrl( array $filter ): string {
        $args = [
            'tt_view' => self::SLUG,
            'scope'   => $filter['scope'],
            'sort'    => $filter['sort'],
            'dir'     => $filter['dir'],
        ];
        if ( $filter['scope'] === PotentialOverviewQuery::SCOPE_AGE_GROUP ) {
            $args['age_group'] = $filter['age_group'];
        } else {
            $args['team_id'] = $filter['team_id'];
        }
        if ( $filter['bands'] !== [] ) {
            $args['bands'] = $filter['bands'];
        }

        return add_query_arg( $args, RecordLink::dashboardUrl() );
    }

    private static function denied( string $message ): void {
        FrontendBreadcrumbs::fromDashboard(
            __( 'Potential overview', 'talenttrack' ),
            [ FrontendBreadcrumbs::viewCrumb( 'reports', __( 'Reports', 'talenttrack' ) ) ]
        );
        echo '<p class="tt-notice">' . esc_html( $message ) . '</p>';
    }
}
