<?php
namespace TT\Modules\Journey\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Journey\SuspensionRepository;
use TT\Infrastructure\Journey\SuspensionService;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Journey\Wizards\SuspensionConfirmStep;
use TT\Modules\Journey\Wizards\SuspensionDetailsStep;
use TT\Modules\Journey\Wizards\SuspensionPlayerStep;
use TT\Shared\Dates\TTDate;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\EmptyStateCard;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Wizards\WizardEntryPoint;

/**
 * FrontendSuspensionsView (#4103) — slug `suspensions`.
 *
 *   ?tt_view=suspensions                       who is suspended, across the teams you can see
 *   ?tt_view=suspensions&id=N                  one suspension and the matches it covers
 *   ?tt_view=suspensions&action=new&player_id  the flat create form (the wizard's fallback)
 *   ?tt_view=suspensions&action=edit&id=N      the edit form
 *
 * Composition only (CLAUDE.md §4): the rows come from
 * `SuspensionRepository`, the state from `SuspensionService`, and both
 * forms post to the REST routes. Create and edit are an explicit Save
 * with Cancel (save model B): a suspension is a short record whose fields
 * are a known set, and a half-typed one must never reach a family's
 * journey. Creating a record is never autosave.
 */
final class FrontendSuspensionsView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        $title  = __( 'Suspensions', 'talenttrack' );
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view routing.
        $action = isset( $_GET['action'] ) ? sanitize_key( (string) wp_unslash( $_GET['action'] ) ) : '';
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        // phpcs:enable

        self::enqueueAssets();
        SuspensionCards::enqueue();

        if ( ! SuspensionService::isEnabled() ) {
            FrontendBreadcrumbs::fromDashboard( $title );
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'Suspensions are switched off for this academy.', 'talenttrack' ) . '</p>';
            return;
        }

        if ( $action === 'new' ) {
            self::renderCreate( $user_id );
            return;
        }
        if ( $action === 'edit' && $id > 0 ) {
            self::renderEdit( $user_id, $id );
            return;
        }
        if ( $id > 0 ) {
            self::renderDetail( $user_id, $id );
            return;
        }
        self::renderOverview( $user_id, $is_admin );
    }

    private static function listCrumb(): array {
        return [ FrontendBreadcrumbs::viewCrumb( 'suspensions', __( 'Suspensions', 'talenttrack' ) ) ];
    }

    // Overview ---------------------------------------------------------

    private static function renderOverview( int $user_id, bool $is_admin ): void {
        $title = __( 'Suspensions', 'talenttrack' );
        FrontendBreadcrumbs::fromDashboard( $title );

        $see_all = $is_admin || MatrixGate::can( $user_id, 'player_suspensions', MatrixGate::READ, MatrixGate::SCOPE_GLOBAL );
        $teams   = [];
        foreach ( ( $see_all ? QueryHelpers::get_teams() : QueryHelpers::get_teams_for_coach( $user_id ) ) as $team ) {
            $t   = (array) $team;
            $tid = (int) ( $t['id'] ?? 0 );
            if ( $tid <= 0 ) continue;
            if ( ! $see_all && ! MatrixGate::can( $user_id, 'player_suspensions', MatrixGate::READ, MatrixGate::SCOPE_TEAM, $tid ) ) continue;
            $teams[ $tid ] = (string) ( $t['name'] ?? '' );
        }

        $add_url = WizardEntryPoint::urlFor(
            'new-suspension',
            add_query_arg( [ 'tt_view' => 'suspensions', 'action' => 'new' ], RecordLink::dashboardUrl() ) /* tt-xview-ok — same view, its own create action */
        );
        $can_add = $see_all || SuspensionPlayerStep::teamsFor( $user_id ) !== [];
        $actions = $can_add ? self::pageActionsHtml( [ [
            'label'   => __( 'Record suspension', 'talenttrack' ),
            'href'    => $add_url,
            'primary' => true,
            'icon'    => '+',
        ] ] ) : '';
        self::renderHeader( $title, $actions );

        if ( $teams === [] ) {
            echo '<p class="tt-notice">' . esc_html__( 'No teams are available to you. A player and their parents see their own suspensions on the player\'s profile and journey.', 'talenttrack' ) . '</p>';
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
        $team_id = isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0;
        $status  = isset( $_GET['status'] ) ? sanitize_key( (string) wp_unslash( $_GET['status'] ) ) : 'open';
        // phpcs:enable
        if ( $team_id > 0 && ! isset( $teams[ $team_id ] ) ) $team_id = 0;
        if ( ! in_array( $status, [ 'open', 'served', 'all' ], true ) ) $status = 'open';

        self::renderFilters( $teams, $team_id, $status );

        $scope = $team_id > 0 ? [ $team_id ] : ( $see_all ? [] : array_keys( $teams ) );
        $rows  = ( new SuspensionRepository() )->listForTeams( $scope, $status );

        if ( $rows === [] ) {
            if ( $status === 'open' ) {
                EmptyStateCard::render( array_filter( [
                    'headline'  => __( 'Nobody is suspended', 'talenttrack' ),
                    'explainer' => __( 'Recording a suspension marks the player unavailable for exactly the matches it covers, so match prep and the planned squad flag them for you.', 'talenttrack' ),
                    'cta_label' => $can_add ? __( 'Record suspension', 'talenttrack' ) : null,
                    'cta_url'   => $can_add ? $add_url : null,
                ] ) );
                return;
            }
            echo '<p class="tt-notice">' . esc_html__( 'No suspensions match your filters.', 'talenttrack' ) . '</p>';
            return;
        }

        SuspensionCards::render( $rows, $user_id, true );
    }

    /** @param array<int, string> $teams */
    private static function renderFilters( array $teams, int $team_id, string $status ): void {
        echo '<form method="get" class="tt-suspension-filters">';
        echo '<input type="hidden" name="tt_view" value="suspensions" />';

        echo '<div class="tt-field">';
        echo '<label class="tt-field-label" for="tt-suspensions-team">' . esc_html__( 'Team', 'talenttrack' ) . '</label>';
        echo '<select id="tt-suspensions-team" class="tt-input" name="team_id">';
        echo '<option value="0">' . esc_html__( 'All teams', 'talenttrack' ) . '</option>';
        foreach ( $teams as $tid => $name ) {
            echo '<option value="' . esc_attr( (string) $tid ) . '"' . selected( $team_id, $tid, false ) . '>' . esc_html( $name ) . '</option>';
        }
        echo '</select></div>';

        $statuses = [
            'open'   => __( 'Still to serve', 'talenttrack' ),
            'served' => _x( 'Served', 'suspension filter', 'talenttrack' ),
            'all'    => _x( 'All', 'suspension filter', 'talenttrack' ),
        ];
        echo '<div class="tt-field">';
        echo '<label class="tt-field-label" for="tt-suspensions-status">' . esc_html__( 'Status', 'talenttrack' ) . '</label>';
        echo '<select id="tt-suspensions-status" class="tt-input" name="status">';
        foreach ( $statuses as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '"' . selected( $status, $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></div>';

        echo '<button type="submit" class="tt-btn tt-btn-secondary">' . esc_html__( 'Show', 'talenttrack' ) . '</button>';
        echo '</form>';
    }

    // Detail -----------------------------------------------------------

    private static function renderDetail( int $user_id, int $id ): void {
        $row = ( new SuspensionRepository() )->find( $id );
        $player_id = $row !== null ? (int) $row['player_id'] : 0;

        if ( $row === null || ! AuthorizationService::canAccessSuspensions( $user_id, $player_id, 'read' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Suspension', 'talenttrack' ), self::listCrumb() );
            self::renderHeader( __( 'Suspension', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'That suspension was not found.', 'talenttrack' ) . '</p>';
            return;
        }

        $player = QueryHelpers::get_player( $player_id );
        $name   = $player ? QueryHelpers::player_display_name( $player ) : '';
        $title  = $name !== ''
            /* translators: %s: player name */
            ? sprintf( __( 'Suspension — %s', 'talenttrack' ), $name )
            : __( 'Suspension', 'talenttrack' );

        FrontendBreadcrumbs::fromDashboard( $title, self::listCrumb() );
        self::renderHeader( $title );

        SuspensionCards::render( [ $row ], $user_id, false );

        $status   = SuspensionService::status( $row );
        $sequence = SuspensionService::fixtureSequence( (int) ( $row['team_id'] ?? 0 ), (string) ( $row['started_on'] ?? '' ) );
        $covered  = [];
        foreach ( $sequence as $fixture ) {
            if ( count( $covered ) >= $status['of'] ) break;
            if ( $status['served_on'] !== '' && $fixture['date'] > $status['served_on'] ) break;
            $covered[] = $fixture;
        }

        echo '<h2 class="tt-player-card__title">' . esc_html__( 'Matches it covers', 'talenttrack' ) . '</h2>';
        if ( $covered === [] ) {
            echo '<p class="tt-field-hint">' . esc_html__( 'No matches are on the calendar from that day yet. The suspension will cover the next ones that are added.', 'talenttrack' ) . '</p>';
            return;
        }
        echo '<ol class="tt-suspension-matches-list">';
        foreach ( $covered as $fixture ) {
            $label = SuspensionConfirmStep::fixtureLabel( $fixture['id'], $fixture['date'] );
            if ( $fixture['status'] === 'completed' ) {
                $label .= ' · ' . __( 'played', 'talenttrack' );
            }
            echo '<li>' . esc_html( $label ) . '</li>';
        }
        echo '</ol>';
        if ( count( $covered ) < $status['of'] ) {
            echo '<p class="tt-field-hint">' . esc_html__( 'The rest will be the next matches added to the calendar.', 'talenttrack' ) . '</p>';
        }
    }

    // Forms ------------------------------------------------------------

    private static function renderCreate( int $user_id ): void {
        $title = __( 'Record suspension', 'talenttrack' );
        FrontendBreadcrumbs::fromDashboard( $title, self::listCrumb() );
        self::renderHeader( $title );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
        $player_id = isset( $_GET['player_id'] ) ? absint( $_GET['player_id'] ) : 0;

        if ( $player_id <= 0 ) {
            self::renderPlayerPicker( $user_id );
            return;
        }
        if ( ! AuthorizationService::canAccessSuspensions( $user_id, $player_id, 'change' ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'You cannot record a suspension for this player.', 'talenttrack' ) . '</p>';
            return;
        }

        $player = QueryHelpers::get_player( $player_id );
        $name   = $player ? QueryHelpers::player_display_name( $player ) : '';

        $profile = add_query_arg( [ 'tt_view' => 'players', 'id' => $player_id, 'tab' => 'suspensions' ], RecordLink::dashboardUrl() ); /* tt-xview-ok — the form only renders for someone who may record for this player */
        $list    = add_query_arg( [ 'tt_view' => 'suspensions' ], RecordLink::dashboardUrl() ); /* tt-xview-ok — same view */
        $back    = BackLink::resolve();

        echo '<form class="tt-ajax-form tt-suspension-form" data-rest-path="' . esc_attr( 'players/' . $player_id . '/suspensions' ) . '" data-rest-method="POST" data-redirect-after-save-url="' . esc_url( $back !== null ? $back['url'] : $profile ) . '">';
        if ( $name !== '' ) {
            /* translators: %s: player name */
            echo '<p class="tt-field-hint">' . esc_html( sprintf( __( 'Player: %s', 'talenttrack' ), $name ) ) . '</p>';
        }
        SuspensionDetailsStep::renderFields( [
            'reason_lookup_id' => 0,
            'match_count'      => 1,
            'started_on'       => current_time( 'Y-m-d' ),
            'notes'            => '',
        ] );
        // CLAUDE.md §6 — create mode cancels to the list, `tt_back` first.
        echo FormSaveButton::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — the component escapes
            'label'      => __( 'Record suspension', 'talenttrack' ),
            'cancel_url' => $list,
        ] );
        echo '<div class="tt-form-msg"></div>';
        echo '</form>';
    }

    /** A small GET form: the flat form needs its player before it can post. */
    private static function renderPlayerPicker( int $user_id ): void {
        $teams = SuspensionPlayerStep::teamsFor( $user_id );
        if ( $teams === [] ) {
            echo '<p class="tt-notice">' . esc_html__( 'There are no teams you can record a suspension for. Ask an administrator to assign you to a team.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<form method="get" class="tt-suspension-form">';
        echo '<input type="hidden" name="tt_view" value="suspensions" />';
        echo '<input type="hidden" name="action" value="new" />';
        $back = BackLink::currentValue();
        if ( $back !== '' ) {
            echo '<input type="hidden" name="tt_back" value="' . esc_attr( $back ) . '" />';
        }
        echo '<div class="tt-field">';
        echo '<label class="tt-field-label" for="tt-suspension-pick-player">' . esc_html__( 'Which player is suspended?', 'talenttrack' ) . '</label>';
        echo '<select id="tt-suspension-pick-player" class="tt-input" name="player_id" required>';
        echo '<option value="">' . esc_html__( '— Pick a player —', 'talenttrack' ) . '</option>';
        foreach ( $teams as $tid => $team_name ) {
            $players = QueryHelpers::get_players( $tid );
            if ( $players === [] ) continue;
            echo '<optgroup label="' . esc_attr( $team_name ) . '">';
            foreach ( $players as $pl ) {
                $pid = (int) ( ( (array) $pl )['id'] ?? 0 );
                echo '<option value="' . esc_attr( (string) $pid ) . '">' . esc_html( QueryHelpers::player_display_name( $pl ) ) . '</option>';
            }
            echo '</optgroup>';
        }
        echo '</select></div>';
        echo FormSaveButton::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — the component escapes
            'label'      => __( 'Continue', 'talenttrack' ),
            'cancel_url' => add_query_arg( [ 'tt_view' => 'suspensions' ], RecordLink::dashboardUrl() ), /* tt-xview-ok — same view */
        ] );
        echo '</form>';
    }

    private static function renderEdit( int $user_id, int $id ): void {
        $title = __( 'Edit suspension', 'talenttrack' );
        $row   = ( new SuspensionRepository() )->find( $id );
        $player_id = $row !== null ? (int) $row['player_id'] : 0;

        FrontendBreadcrumbs::fromDashboard( $title, self::listCrumb() );
        self::renderHeader( $title );

        if ( $row === null || ! AuthorizationService::canAccessSuspensions( $user_id, $player_id, 'change' ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'You cannot edit this suspension.', 'talenttrack' ) . '</p>';
            return;
        }

        $detail = add_query_arg( [ 'tt_view' => 'suspensions', 'id' => $id ], RecordLink::dashboardUrl() ); /* tt-xview-ok — same view */
        $back   = BackLink::resolve();
        $served = ! empty( $row['served_on'] );

        $player = QueryHelpers::get_player( $player_id );
        $name   = $player ? QueryHelpers::player_display_name( $player ) : '';

        echo '<form class="tt-ajax-form tt-suspension-form" data-rest-path="' . esc_attr( 'suspensions/' . $id ) . '" data-rest-method="PATCH" data-redirect-after-save-url="' . esc_url( $back !== null ? $back['url'] : $detail ) . '">';
        if ( $name !== '' ) {
            /* translators: %s: player name */
            echo '<p class="tt-field-hint">' . esc_html( sprintf( __( 'Player: %s', 'talenttrack' ), $name ) ) . '</p>';
        }
        if ( $served ) {
            /* translators: %s: a date */
            echo '<p class="tt-field-hint">' . esc_html( sprintf( __( 'Served on %s.', 'talenttrack' ), TTDate::date( (string) $row['served_on'] ) ) ) . '</p>';
        }
        SuspensionDetailsStep::renderFields( [
            'reason_lookup_id' => (int) ( $row['reason_lookup_id'] ?? 0 ),
            'match_count'      => (int) ( $row['match_count'] ?? 1 ),
            'started_on'       => (string) ( $row['started_on'] ?? '' ),
            'notes'            => (string) ( $row['notes'] ?? '' ),
        ], $served );
        // CLAUDE.md §6 — edit mode cancels to the record, `tt_back` first.
        echo FormSaveButton::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — the component escapes
            'label'      => __( 'Save suspension', 'talenttrack' ),
            'cancel_url' => $detail,
        ] );
        echo '<div class="tt-form-msg"></div>';
        echo '</form>';
    }
}
