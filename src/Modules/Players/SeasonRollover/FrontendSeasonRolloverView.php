<?php
namespace TT\Modules\Players\SeasonRollover;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LabelTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\REST\SeasonRolloverRestController;
use TT\Modules\Pdp\Repositories\SeasonsRepository;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendSeasonRolloverView (#1381) — bulk cohort promotion at season end.
 *
 * Three steps, only the last of which mutates:
 *
 *   1. Map      — pick a target team + effective date + reason per source team.
 *   2. Select   — per-player checklist with a per-player action (promote /
 *                 release / graduate / skip).
 *   3. Review   — read-only table of the exact changes, then a backup-first
 *                 confirm submit to admin-post.php (PRG redirect on success).
 *
 * The intermediate steps render inline within the dashboard view and never
 * mutate. Only the final EXECUTE posts to admin-post.php and PRG-redirects
 * back, so a browser refresh can't re-run the rollover.
 *
 * This is a bulk operation on existing records — wizard exemption (b) per
 * CLAUDE.md §3 — so it's a dedicated multi-step view, not a WizardInterface
 * wizard. All business logic lives in SeasonRolloverService (CLAUDE.md §4).
 */
class FrontendSeasonRolloverView extends FrontendViewBase {

    public const CAP       = 'tt_manage_players';
    public const VIEW_SLUG = 'season-rollover';

    /** Wire the execute handler + REST routes. Called from Kernel::boot. */
    public static function init(): void {
        add_action( 'admin_post_tt_season_rollover_execute', [ self::class, 'handleExecute' ] );
        SeasonRolloverRestController::init();
    }

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( self::CAP ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to run a season rollover.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-season-rollover',
            TT_PLUGIN_URL . 'assets/css/frontend-season-rollover.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );

        FrontendBreadcrumbs::fromDashboard( __( 'Season rollover', 'talenttrack' ) );
        self::renderHeader( __( 'Season rollover', 'talenttrack' ) );

        // PRG result banner after a completed rollover.
        if ( isset( $_GET['tt_msg'] ) && $_GET['tt_msg'] === 'done' ) {
            self::renderDoneBanner();
            self::renderIntro();
            self::renderStepMap( [], '', '' );
            return;
        }

        $step = isset( $_POST['tt_sr_step'] ) ? sanitize_key( (string) wp_unslash( $_POST['tt_sr_step'] ) ) : '';

        // Steps 2 and 3 are reached by posting back to the view (no
        // mutation). Each verifies the step nonce before reading the
        // carried-forward state.
        if ( $step === 'select' && check_admin_referer( 'tt_sr_map', 'tt_sr_nonce' ) ) {
            self::renderStepSelect();
            return;
        }
        if ( $step === 'review' && check_admin_referer( 'tt_sr_select', 'tt_sr_nonce' ) ) {
            self::renderStepReview();
            return;
        }

        self::renderIntro();
        self::renderStepMap( [], '', '' );
    }

    // ─────────────────────────── Step 1: Map ───────────────────────────

    /**
     * @param array<int,int> $mapping  pre-selected source => target (unused on first render)
     */
    private static function renderStepMap( array $mapping, string $effective_date, string $reason ): void {
        $teams = self::teams();
        if ( empty( $teams ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'There are no teams to roll over yet. Create teams first.', 'talenttrack' ) . '</p>';
            return;
        }

        $default_date = $effective_date !== '' ? $effective_date : self::defaultEffectiveDate();

        echo '<form method="post" class="tt-sr-form" action="' . esc_url( self::viewUrl() ) . '">';
        wp_nonce_field( 'tt_sr_map', 'tt_sr_nonce' );
        echo '<input type="hidden" name="tt_sr_step" value="select" />';

        echo '<ol class="tt-sr-steps" aria-label="' . esc_attr__( 'Rollover steps', 'talenttrack' ) . '">';
        self::renderStepBeads( 1 );
        echo '</ol>';

        echo '<fieldset class="tt-sr-fieldset">';
        echo '<legend class="tt-sr-legend">' . esc_html__( 'When does this rollover take effect?', 'talenttrack' ) . '</legend>';
        echo '<label class="tt-sr-field">';
        echo '<span class="tt-sr-field-label">' . esc_html__( 'Effective date', 'talenttrack' ) . '</span>';
        echo '<input type="date" name="tt_sr_effective_date" value="' . esc_attr( $default_date ) . '" required class="tt-sr-input" />';
        echo '</label>';
        echo '<label class="tt-sr-field">';
        echo '<span class="tt-sr-field-label">' . esc_html__( 'Reason (optional)', 'talenttrack' ) . '</span>';
        echo '<input type="text" name="tt_sr_reason" value="' . esc_attr( $reason ) . '" maxlength="200" class="tt-sr-input" placeholder="' . esc_attr__( 'e.g. End of 2025/26 season', 'talenttrack' ) . '" />';
        echo '</label>';
        echo '</fieldset>';

        echo '<table class="tt-sr-table">';
        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__( 'Source team', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Age group', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Active players', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Promote to', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $teams as $team ) {
            $team_id   = (int) $team->id;
            $count     = self::activeCount( $team_id );
            $selected  = $mapping[ $team_id ] ?? 0;
            echo '<tr>';
            echo '<td data-label="' . esc_attr__( 'Source team', 'talenttrack' ) . '">' . esc_html( (string) $team->name ) . '</td>';
            echo '<td data-label="' . esc_attr__( 'Age group', 'talenttrack' ) . '">' . esc_html( (string) ( $team->age_group ?? '' ) ) . '</td>';
            echo '<td data-label="' . esc_attr__( 'Active players', 'talenttrack' ) . '">' . esc_html( (string) $count ) . '</td>';
            echo '<td data-label="' . esc_attr__( 'Promote to', 'talenttrack' ) . '">';
            echo '<select name="tt_sr_target[' . esc_attr( (string) $team_id ) . ']" class="tt-sr-select">';
            echo '<option value="0">' . esc_html__( 'No promotion / stays', 'talenttrack' ) . '</option>';
            foreach ( $teams as $target ) {
                $target_id = (int) $target->id;
                if ( $target_id === $team_id ) continue;
                $label = (string) $target->name;
                if ( ! empty( $target->age_group ) ) {
                    $label .= ' (' . (string) $target->age_group . ')';
                }
                echo '<option value="' . esc_attr( (string) $target_id ) . '"' . selected( $selected, $target_id, false ) . '>' . esc_html( $label ) . '</option>';
            }
            echo '</select>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo FormSaveButton::render( [
            'label'      => __( 'Next: choose players', 'talenttrack' ),
            'cancel_url' => self::cancelUrl(),
        ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component returns escaped HTML.
        echo '</form>';
    }

    // ────────────────────────── Step 2: Select ─────────────────────────

    private static function renderStepSelect(): void {
        $mapping        = self::postedMapping();
        $effective_date = self::postedDate();
        $reason         = self::postedReason();

        // Source teams that actually carry a mapping entry (kept even when
        // target is 0 so the user can still release / graduate a stay team).
        $source_ids = array_keys( $mapping );
        if ( empty( $source_ids ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No source teams were selected. Go back and choose at least one.', 'talenttrack' ) . '</p>';
            self::renderStepMap( $mapping, $effective_date, $reason );
            return;
        }

        echo '<form method="post" class="tt-sr-form" action="' . esc_url( self::viewUrl() ) . '">';
        wp_nonce_field( 'tt_sr_select', 'tt_sr_nonce' );
        echo '<input type="hidden" name="tt_sr_step" value="review" />';
        echo '<input type="hidden" name="tt_sr_effective_date" value="' . esc_attr( $effective_date ) . '" />';
        echo '<input type="hidden" name="tt_sr_reason" value="' . esc_attr( $reason ) . '" />';

        echo '<ol class="tt-sr-steps" aria-label="' . esc_attr__( 'Rollover steps', 'talenttrack' ) . '">';
        self::renderStepBeads( 2 );
        echo '</ol>';

        $team_names = self::teamNames();

        foreach ( $source_ids as $source_id ) {
            $source_id    = (int) $source_id;
            $target_id    = (int) $mapping[ $source_id ];
            $players      = QueryHelpers::get_players( $source_id );
            $default_act  = $target_id > 0 ? SeasonRolloverService::ACTION_PROMOTE : SeasonRolloverService::ACTION_SKIP;
            $source_name  = $team_names[ $source_id ] ?? '';
            $target_name  = $target_id > 0 ? ( $team_names[ $target_id ] ?? '' ) : '';

            echo '<input type="hidden" name="tt_sr_target[' . esc_attr( (string) $source_id ) . ']" value="' . esc_attr( (string) $target_id ) . '" />';

            echo '<section class="tt-sr-team-block">';
            echo '<h2 class="tt-sr-team-title">' . esc_html( $source_name );
            if ( $target_name !== '' ) {
                echo ' <span class="tt-sr-arrow" aria-hidden="true">→</span> <span class="tt-sr-target-name">' . esc_html( $target_name ) . '</span>';
            }
            echo '</h2>';

            if ( empty( $players ) ) {
                echo '<p class="tt-sr-empty">' . esc_html__( 'No active players on this team.', 'talenttrack' ) . '</p>';
                echo '</section>';
                continue;
            }

            echo '<table class="tt-sr-table">';
            echo '<thead><tr>';
            echo '<th scope="col" class="tt-sr-check-col"><span class="tt-screen-reader-text">' . esc_html__( 'Include', 'talenttrack' ) . '</span></th>';
            echo '<th scope="col">' . esc_html__( 'Player', 'talenttrack' ) . '</th>';
            echo '<th scope="col">' . esc_html__( 'Status', 'talenttrack' ) . '</th>';
            echo '<th scope="col">' . esc_html__( 'Action', 'talenttrack' ) . '</th>';
            echo '</tr></thead><tbody>';

            foreach ( $players as $player ) {
                /** @var \stdClass $player */
                $player_id = (int) $player->id;
                $name      = QueryHelpers::player_display_name( $player );
                $status    = LabelTranslator::playerStatus( (string) ( $player->status ?? '' ) );
                $cb_id     = 'tt-sr-inc-' . $player_id;
                echo '<tr>';
                echo '<td data-label="' . esc_attr__( 'Include', 'talenttrack' ) . '" class="tt-sr-check-col">';
                echo '<input type="checkbox" id="' . esc_attr( $cb_id ) . '" name="tt_sr_include[]" value="' . esc_attr( (string) $player_id ) . '" checked class="tt-sr-check" />';
                echo '</td>';
                echo '<td data-label="' . esc_attr__( 'Player', 'talenttrack' ) . '"><label for="' . esc_attr( $cb_id ) . '">' . esc_html( $name ) . '</label></td>';
                echo '<td data-label="' . esc_attr__( 'Status', 'talenttrack' ) . '">' . esc_html( $status ) . '</td>';
                echo '<td data-label="' . esc_attr__( 'Action', 'talenttrack' ) . '">';
                echo '<select name="tt_sr_action[' . esc_attr( (string) $player_id ) . ']" class="tt-sr-select">';
                self::actionOption( SeasonRolloverService::ACTION_PROMOTE, $default_act, $target_id > 0 );
                self::actionOption( SeasonRolloverService::ACTION_RELEASE, $default_act, true );
                self::actionOption( SeasonRolloverService::ACTION_GRADUATE, $default_act, true );
                self::actionOption( SeasonRolloverService::ACTION_SKIP, $default_act, true );
                echo '</select>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</section>';
        }

        echo FormSaveButton::render( [
            'label'      => __( 'Next: review changes', 'talenttrack' ),
            'cancel_url' => self::cancelUrl(),
        ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component returns escaped HTML.
        echo '</form>';
    }

    private static function actionOption( string $value, string $default, bool $enabled ): void {
        if ( ! $enabled ) return;
        echo '<option value="' . esc_attr( $value ) . '"' . selected( $value, $default, false ) . '>'
            . esc_html( SeasonRolloverService::actionLabel( $value ) )
            . '</option>';
    }

    // ────────────────────────── Step 3: Review ─────────────────────────

    private static function renderStepReview(): void {
        $mapping        = self::postedMapping();
        $effective_date = self::postedDate();
        $reason         = self::postedReason();
        $selections     = self::postedSelections();

        $plan = ( new SeasonRolloverService() )->plan( $mapping, $selections, $effective_date );

        echo '<ol class="tt-sr-steps" aria-label="' . esc_attr__( 'Rollover steps', 'talenttrack' ) . '">';
        self::renderStepBeads( 3 );
        echo '</ol>';

        if ( empty( $plan['changes'] ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No players were selected to change. Go back and pick at least one.', 'talenttrack' ) . '</p>';
            self::renderStepMap( $mapping, $effective_date, $reason );
            return;
        }

        $counts = $plan['counts'];
        echo '<p class="tt-sr-summary">';
        printf(
            /* translators: 1: promoted count, 2: released count, 3: graduated count, 4: effective date */
            esc_html__( 'This rollover will promote %1$d, release %2$d and graduate %3$d players, effective %4$s. Released players stay active.', 'talenttrack' ),
            (int) $counts['moved'],
            (int) $counts['released'],
            (int) $counts['graduated'],
            esc_html( $plan['effective_date'] )
        );
        echo '</p>';

        echo '<p class="tt-sr-backup-note">' . esc_html__( 'A full backup runs automatically before any change is made. If the backup fails, the rollover is aborted and nothing is changed.', 'talenttrack' ) . '</p>';

        echo '<table class="tt-sr-table tt-sr-review">';
        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__( 'Player', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'From team', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'To team', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Action', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Journey event', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $plan['changes'] as $change ) {
            echo '<tr>';
            echo '<td data-label="' . esc_attr__( 'Player', 'talenttrack' ) . '">' . esc_html( $change['player_name'] ) . '</td>';
            echo '<td data-label="' . esc_attr__( 'From team', 'talenttrack' ) . '">' . esc_html( $change['from_team_name'] ) . '</td>';
            echo '<td data-label="' . esc_attr__( 'To team', 'talenttrack' ) . '">' . esc_html( $change['to_team_name'] !== '' ? $change['to_team_name'] : '—' ) . '</td>';
            echo '<td data-label="' . esc_attr__( 'Action', 'talenttrack' ) . '">' . esc_html( $change['event_label'] ) . '</td>';
            echo '<td data-label="' . esc_attr__( 'Journey event', 'talenttrack' ) . '">' . esc_html( self::eventTypeLabel( $change['event_type'] ) ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        // The execute form posts to admin-post.php with all data in hidden
        // fields. Cancel + Confirm via FormSaveButton (CLAUDE.md §6).
        echo '<form method="post" class="tt-sr-form tt-sr-confirm" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tt_season_rollover_execute" />';
        wp_nonce_field( 'tt_season_rollover_execute', 'tt_nonce' );
        echo '<input type="hidden" name="tt_sr_effective_date" value="' . esc_attr( $plan['effective_date'] ) . '" />';
        echo '<input type="hidden" name="tt_sr_reason" value="' . esc_attr( $reason ) . '" />';
        foreach ( $mapping as $source_id => $target_id ) {
            echo '<input type="hidden" name="tt_sr_target[' . esc_attr( (string) (int) $source_id ) . ']" value="' . esc_attr( (string) (int) $target_id ) . '" />';
        }
        foreach ( $selections as $player_id => $action ) {
            echo '<input type="hidden" name="tt_sr_action[' . esc_attr( (string) (int) $player_id ) . ']" value="' . esc_attr( $action ) . '" />';
        }

        echo FormSaveButton::render( [
            'label'      => __( 'Confirm rollover', 'talenttrack' ),
            'variant'    => 'danger',
            'cancel_url' => self::cancelUrl(),
        ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component returns escaped HTML.
        echo '</form>';
    }

    // ────────────────────────── EXECUTE handler ────────────────────────

    public static function handleExecute(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'You do not have permission to run a season rollover.', 'talenttrack' ) );
        }
        check_admin_referer( 'tt_season_rollover_execute', 'tt_nonce' );

        $mapping        = self::postedMapping();
        $selections     = self::postedSelections();
        $effective_date = self::postedDate();
        $reason         = self::postedReason();

        $result = ( new SeasonRolloverService() )->execute( $mapping, $selections, $effective_date, $reason );

        // PRG redirect — a refresh of the resulting URL cannot re-run the
        // mutation because all state lives in the POST body, which the
        // redirect discards.
        $args = [
            'tt_view'   => self::VIEW_SLUG,
            'tt_msg'    => empty( $result['ok'] ) ? 'failed' : 'done',
            'moved'     => (int) $result['moved'],
            'released'  => (int) $result['released'],
            'graduated' => (int) $result['graduated'],
            'skipped'   => (int) $result['skipped'],
            'backup'    => ! empty( $result['backup_ok'] ) ? 1 : 0,
        ];
        wp_safe_redirect( add_query_arg( $args, self::dashboardUrl() ) );
        exit;
    }

    // ───────────────────────────── Helpers ─────────────────────────────

    private static function renderDoneBanner(): void {
        $moved     = isset( $_GET['moved'] ) ? absint( $_GET['moved'] ) : 0;
        $released  = isset( $_GET['released'] ) ? absint( $_GET['released'] ) : 0;
        $graduated = isset( $_GET['graduated'] ) ? absint( $_GET['graduated'] ) : 0;
        $skipped   = isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0;

        echo '<div class="tt-flash tt-flash-success tt-sr-flash">';
        echo esc_html( sprintf(
            /* translators: 1: promoted, 2: released, 3: graduated, 4: skipped */
            __( 'Rollover complete: %1$d promoted, %2$d released, %3$d graduated, %4$d skipped. Released players remain active.', 'talenttrack' ),
            $moved, $released, $graduated, $skipped
        ) );
        echo '</div>';
    }

    private static function renderIntro(): void {
        echo '<p class="tt-sr-intro">' . esc_html__( 'Move whole squads up an age group at season end. Each player gets a dated journey event for the change. Released players are recorded but stay active — nothing is deleted or archived.', 'talenttrack' ) . '</p>';
    }

    private static function renderStepBeads( int $active ): void {
        $labels = [
            1 => __( 'Map teams', 'talenttrack' ),
            2 => __( 'Choose players', 'talenttrack' ),
            3 => __( 'Review & confirm', 'talenttrack' ),
        ];
        foreach ( $labels as $n => $label ) {
            $cls = 'tt-sr-step';
            if ( $n === $active ) $cls .= ' is-active';
            elseif ( $n < $active ) $cls .= ' is-done';
            echo '<li class="' . esc_attr( $cls ) . '"><span class="tt-sr-step-num">' . esc_html( (string) $n ) . '</span> <span class="tt-sr-step-label">' . esc_html( $label ) . '</span></li>';
        }
    }

    /** @return list<\stdClass> non-archived club teams */
    private static function teams(): array {
        $out = [];
        foreach ( QueryHelpers::get_teams() as $team ) {
            /** @var \stdClass $team */
            if ( ! empty( $team->archived_at ) ) continue;
            $out[] = $team;
        }
        return $out;
    }

    /** @return array<int,string> team_id => name */
    private static function teamNames(): array {
        $out = [];
        foreach ( QueryHelpers::get_teams() as $team ) {
            /** @var \stdClass $team */
            $out[ (int) $team->id ] = (string) $team->name;
        }
        return $out;
    }

    private static function activeCount( int $team_id ): int {
        return count( QueryHelpers::get_players( $team_id ) );
    }

    private static function defaultEffectiveDate(): string {
        $season = ( new SeasonsRepository() )->current();
        /** @var \stdClass|null $season */
        if ( $season !== null && ! empty( $season->end_date ) ) {
            $ts = strtotime( (string) $season->end_date );
            if ( $ts !== false ) return gmdate( 'Y-m-d', $ts );
        }
        return current_time( 'Y-m-d' );
    }

    private static function eventTypeLabel( string $event_type ): string {
        switch ( $event_type ) {
            case \TT\Domain\Vocabularies\Lookups\JourneyEventType::AGE_GROUP_PROMOTED:
                return __( 'Age group promoted', 'talenttrack' );
            case \TT\Domain\Vocabularies\Lookups\JourneyEventType::RELEASED:
                return __( 'Released', 'talenttrack' );
            case \TT\Domain\Vocabularies\Lookups\JourneyEventType::GRADUATED:
                return __( 'Graduated', 'talenttrack' );
            default:
                return __( 'No change', 'talenttrack' );
        }
    }

    /** @return array<int,int> source_team_id => target_team_id */
    private static function postedMapping(): array {
        $raw = isset( $_POST['tt_sr_target'] ) && is_array( $_POST['tt_sr_target'] )
            ? (array) wp_unslash( $_POST['tt_sr_target'] )
            : [];
        $out = [];
        foreach ( $raw as $source => $target ) {
            $source_id = absint( $source );
            if ( $source_id <= 0 ) continue;
            $out[ $source_id ] = absint( $target );
        }
        return $out;
    }

    /**
     * Resolve the selected actions. A player is only carried when included
     * (checkbox) AND has an action other than skip; included players whose
     * action is missing default to skip.
     *
     * @return array<int,string> player_id => action
     */
    private static function postedSelections(): array {
        // Step 2 -> 3 uses include[] + action[]. Step 3 -> execute carries
        // only action[] (already filtered to included players). Support both.
        $actions = isset( $_POST['tt_sr_action'] ) && is_array( $_POST['tt_sr_action'] )
            ? (array) wp_unslash( $_POST['tt_sr_action'] )
            : [];

        $included = null;
        if ( isset( $_POST['tt_sr_include'] ) && is_array( $_POST['tt_sr_include'] ) ) {
            $included = [];
            foreach ( (array) wp_unslash( $_POST['tt_sr_include'] ) as $pid ) {
                $pid = absint( $pid );
                if ( $pid > 0 ) $included[ $pid ] = true;
            }
        }

        $out = [];
        foreach ( $actions as $player => $action ) {
            $player_id = absint( $player );
            if ( $player_id <= 0 ) continue;
            // When an include[] list is present, only included players count.
            if ( $included !== null && ! isset( $included[ $player_id ] ) ) continue;
            $out[ $player_id ] = SeasonRolloverService::normaliseAction( (string) $action );
        }
        return $out;
    }

    private static function postedDate(): string {
        $raw = isset( $_POST['tt_sr_effective_date'] )
            ? sanitize_text_field( (string) wp_unslash( $_POST['tt_sr_effective_date'] ) )
            : '';
        return $raw;
    }

    private static function postedReason(): string {
        return isset( $_POST['tt_sr_reason'] )
            ? sanitize_text_field( (string) wp_unslash( $_POST['tt_sr_reason'] ) )
            : '';
    }

    private static function viewUrl(): string {
        return add_query_arg( [ 'tt_view' => self::VIEW_SLUG ], self::dashboardUrl() );
    }

    private static function cancelUrl(): string {
        // §6 — tt_back overrides the default cancel target when present.
        $back = \TT\Shared\Frontend\Components\BackLink::resolve();
        if ( $back !== null && $back['url'] !== '' ) {
            return $back['url'];
        }
        // Create-mode cancel target: the entity's list (the players list).
        return add_query_arg( [ 'tt_view' => 'players' ], self::dashboardUrl() );
    }

    private static function dashboardUrl(): string {
        return \TT\Shared\Frontend\Components\RecordLink::dashboardUrl();
    }
}
