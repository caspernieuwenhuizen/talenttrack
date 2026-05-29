<?php
namespace TT\Modules\Tournaments\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Tournaments\Wizard\WizardAssets;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendTournamentMatchAddView (#975) — post-creation Add-match form.
 *
 * Reachable via `?tt_view=tournament-match&action=new&tournament_id=N`.
 * Renders a flat form that mirrors the wizard's matches-step card
 * (label / opponent / level / formation override / duration / chip
 * editor for substitution windows) plus an "Insert at position N"
 * select unique to this surface.
 *
 * The form POSTs to `admin-post.php?action=tt_tournament_match_add`
 * (mirroring the #940 admin-post form-POST pattern). The server
 * handler validates + inserts the match (matching the REST
 * controller's `create_match()` behaviour) at the requested sequence
 * position, then redirects to the planner detail view.
 *
 * Cap gate: `tt_edit_tournaments` (admin-only in v1).
 */
final class FrontendTournamentMatchAddView extends FrontendViewBase {

    public const ADMIN_POST_ACTION = 'tt_tournament_match_add';

    public static function init(): void {
        add_action( 'admin_post_' . self::ADMIN_POST_ACTION, [ self::class, 'handlePost' ] );
    }

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();
        WizardAssets::enqueue();

        $tournament_id = isset( $_GET['tournament_id'] ) ? absint( $_GET['tournament_id'] ) : 0;
        $tournament    = self::loadTournament( $tournament_id );

        $list_url     = add_query_arg( [ 'tt_view' => 'tournaments' ], remove_query_arg( [ 'action', 'id', 'tournament_id', 'tt_view' ] ) );
        $parent_crumb = [ FrontendBreadcrumbs::viewCrumb( 'tournaments', __( 'Tournaments', 'talenttrack' ) ) ];
        if ( $tournament ) {
            $parent_crumb[] = FrontendBreadcrumbs::viewCrumb( 'tournaments', (string) $tournament->name, [ 'id' => (int) $tournament->id ] );
        }

        if ( ! current_user_can( 'tt_edit_tournaments' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ), $parent_crumb );
            echo '<p class="tt-notice">' . esc_html__( 'Your role cannot add matches to a tournament.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( ! $tournament ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Tournament not found', 'talenttrack' ), $parent_crumb );
            echo '<p class="tt-notice">' . esc_html__( 'That tournament no longer exists.', 'talenttrack' ) . '</p>';
            return;
        }

        FrontendBreadcrumbs::fromDashboard( __( 'Add match', 'talenttrack' ), $parent_crumb );
        self::renderHeader( __( 'Add match', 'talenttrack' ) );

        self::renderForm( $tournament );
    }

    private static function renderForm( object $tournament ): void {
        $levels     = QueryHelpers::get_lookup_names( 'tournament_opponent_level' );
        $formations = QueryHelpers::get_lookup_names( 'tournament_formation' );
        $default_form = (string) ( $tournament->default_formation ?? '' );

        // Existing matches for the "Insert at position N" select.
        global $wpdb; $p = $wpdb->prefix;
        $existing = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, sequence, label, opponent_name FROM {$p}tt_tournament_matches WHERE tournament_id = %d AND club_id = %d ORDER BY sequence ASC",
            (int) $tournament->id, CurrentClub::id()
        ) ) ?: [];
        $existing_count = count( $existing );
        $end_position   = $existing_count + 1;

        // Cancel target: the planner detail. tt_back overrides when
        // the entry URL captured a back-target (per CLAUDE.md §6).
        $resolved_back = BackLink::resolve();
        $cancel_url = $resolved_back !== null
            ? (string) $resolved_back['url']
            : add_query_arg(
                [ 'tt_view' => 'tournaments', 'id' => (int) $tournament->id ],
                remove_query_arg( [ 'action', 'tournament_id' ] )
            );

        $default_form_label = $default_form !== ''
            ? sprintf( __( '— use tournament default (%s) —', 'talenttrack' ), $default_form )
            : __( '— use tournament default —', 'talenttrack' );
        ?>
        <div class="tt-tournament-wizard">
            <p class="ttw-step-desc">
                <?php echo esc_html( sprintf(
                    __( 'Add a new match to %s. The squad and default formation are inherited from the tournament; override per-match below if needed.', 'talenttrack' ),
                    (string) $tournament->name
                ) ); ?>
            </p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ttw-add-match" novalidate>
                <input type="hidden" name="action" value="<?php echo esc_attr( self::ADMIN_POST_ACTION ); ?>">
                <input type="hidden" name="tournament_id" value="<?php echo (int) $tournament->id; ?>">
                <?php wp_nonce_field( self::ADMIN_POST_ACTION . '_' . (int) $tournament->id, 'tt_match_add_nonce' ); ?>

                <div class="ttw-card">
                    <h3 class="ttw-card-title"><?php esc_html_e( 'Match details', 'talenttrack' ); ?></h3>
                    <div class="ttw-field-grid">
                        <div class="ttw-field">
                            <label for="ttw-am-label"><?php esc_html_e( 'Label (optional)', 'talenttrack' ); ?></label>
                            <input type="text" id="ttw-am-label" name="label" placeholder="<?php esc_attr_e( 'e.g. Final, Round 1…', 'talenttrack' ); ?>">
                        </div>
                        <div class="ttw-field">
                            <label for="ttw-am-opp"><?php esc_html_e( 'Opponent', 'talenttrack' ); ?> <span class="ttw-req">*</span></label>
                            <input type="text" id="ttw-am-opp" name="opponent_name" required>
                        </div>
                        <div class="ttw-field">
                            <label for="ttw-am-level"><?php esc_html_e( 'Opponent level', 'talenttrack' ); ?></label>
                            <select id="ttw-am-level" name="opponent_level">
                                <option value=""><?php esc_html_e( '— pick one —', 'talenttrack' ); ?></option>
                                <?php foreach ( $levels as $lv ) : ?>
                                    <option value="<?php echo esc_attr( (string) $lv ); ?>"><?php echo esc_html( (string) $lv ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ttw-field">
                            <label for="ttw-am-form"><?php esc_html_e( 'Formation override', 'talenttrack' ); ?></label>
                            <select id="ttw-am-form" name="formation">
                                <option value=""><?php echo esc_html( $default_form_label ); ?></option>
                                <?php foreach ( $formations as $f ) : ?>
                                    <option value="<?php echo esc_attr( (string) $f ); ?>"><?php echo esc_html( (string) $f ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ttw-field">
                            <label for="ttw-am-dur"><?php esc_html_e( 'Duration (minutes)', 'talenttrack' ); ?> <span class="ttw-req">*</span></label>
                            <input type="number" id="ttw-am-dur" name="duration_min" data-ttw-field="duration_min" min="1" max="240" inputmode="numeric" value="20" required>
                        </div>
                        <div class="ttw-field">
                            <label><?php esc_html_e( 'Substitution windows (minutes from kickoff)', 'talenttrack' ); ?></label>
                            <div class="ttw-chip-editor" data-ttw-chip-editor>
                                <input type="text" placeholder="<?php esc_attr_e( 'Add a minute and press Enter', 'talenttrack' ); ?>" inputmode="numeric" data-max="19">
                                <input type="hidden" name="substitution_windows" value="">
                                <span class="ttw-hint">
                                    <?php echo esc_html__( 'Press Enter or comma to add. Values must be 1–', 'talenttrack' ); ?><span data-ttw-dur-max>19</span>.
                                </span>
                            </div>
                        </div>
                        <div class="ttw-field ttw-field--full">
                            <label for="ttw-am-seq"><?php esc_html_e( 'Position in sequence', 'talenttrack' ); ?></label>
                            <select id="ttw-am-seq" name="insert_at">
                                <option value="<?php echo (int) $end_position; ?>" selected>
                                    <?php echo esc_html( sprintf( __( 'Insert at end (Match %d)', 'talenttrack' ), $end_position ) ); ?>
                                </option>
                                <?php foreach ( $existing as $i => $m ) :
                                    $pos = (int) $m->sequence;
                                    $head = (string) ( $m->label ?? '' );
                                    if ( $head === '' && $m->opponent_name ) $head = sprintf( __( 'vs %s', 'talenttrack' ), (string) $m->opponent_name );
                                    if ( $head === '' ) $head = sprintf( __( 'Match %d', 'talenttrack' ), $pos );
                                    ?>
                                    <option value="<?php echo (int) $pos; ?>">
                                        <?php echo esc_html( sprintf( __( 'Insert before %1$s (becomes Match %2$d)', 'talenttrack' ), $head, $pos ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="ttw-hint"><?php esc_html_e( 'Drag-to-reorder is also available on the planner after creation.', 'talenttrack' ); ?></span>
                        </div>
                    </div>
                </div>

                <?php echo FormSaveButton::render( [
                    'label'      => __( 'Add match', 'talenttrack' ),
                    'cancel_url' => $cancel_url,
                ] ); ?>
            </form>
        </div>
        <?php
    }

    /**
     * admin-post.php handler for new-match POSTs.
     *
     * Validates cap + nonce + payload, then inserts via the same write
     * path the REST controller uses (`TournamentsRestController` is the
     * source of truth for match shape). On success, optionally shifts
     * downstream matches' sequence so the new one lands at the picked
     * position. Redirects to the planner detail view.
     */
    public static function handlePost(): void {
        if ( ! is_user_logged_in() ) wp_die( esc_html__( 'Not logged in.', 'talenttrack' ), 403 );
        if ( ! current_user_can( 'tt_edit_tournaments' ) ) {
            wp_die( esc_html__( 'Your role cannot add matches to a tournament.', 'talenttrack' ), 403 );
        }

        $tournament_id = isset( $_POST['tournament_id'] ) ? absint( $_POST['tournament_id'] ) : 0;
        if ( $tournament_id <= 0 ) wp_die( esc_html__( 'Missing tournament ID.', 'talenttrack' ), 400 );

        $nonce = isset( $_POST['tt_match_add_nonce'] ) ? (string) wp_unslash( $_POST['tt_match_add_nonce'] ) : '';
        if ( ! wp_verify_nonce( $nonce, self::ADMIN_POST_ACTION . '_' . $tournament_id ) ) {
            wp_die( esc_html__( 'Security check failed. Please reload and try again.', 'talenttrack' ), 403 );
        }

        $tournament = self::loadTournament( $tournament_id );
        if ( ! $tournament ) wp_die( esc_html__( 'That tournament no longer exists.', 'talenttrack' ), 404 );

        $label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['label'] ) ) : '';
        $opp   = isset( $_POST['opponent_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['opponent_name'] ) ) : '';
        $level = isset( $_POST['opponent_level'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['opponent_level'] ) ) : '';
        $form  = isset( $_POST['formation'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['formation'] ) ) : '';
        $dur_raw = isset( $_POST['duration_min'] ) ? (int) $_POST['duration_min'] : 0;
        $duration = $dur_raw > 0 ? min( 240, $dur_raw ) : 20;
        $windows_raw = isset( $_POST['substitution_windows'] ) ? (string) wp_unslash( $_POST['substitution_windows'] ) : '';
        $insert_at = isset( $_POST['insert_at'] ) ? max( 1, absint( $_POST['insert_at'] ) ) : 1;

        if ( $label === '' && $opp === '' ) {
            self::redirectBackWithError( $tournament_id, __( 'Either a label or an opponent name is required.', 'talenttrack' ) );
        }

        $windows = [];
        foreach ( preg_split( '/[\s,]+/', $windows_raw ) as $token ) {
            $w = (int) trim( (string) $token );
            if ( $w > 0 && $w < $duration ) $windows[] = $w;
        }
        $windows = array_values( array_unique( $windows ) );
        sort( $windows );

        global $wpdb; $p = $wpdb->prefix;
        $next_seq = 1 + (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(MAX(sequence), 0) FROM {$p}tt_tournament_matches WHERE tournament_id = %d AND club_id = %d",
            $tournament_id, CurrentClub::id()
        ) );
        // Clamp insert_at to [1, next_seq].
        $insert_at = max( 1, min( $insert_at, $next_seq ) );

        // If inserting mid-tournament, shift downstream sequences up by 1.
        if ( $insert_at < $next_seq ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$p}tt_tournament_matches SET sequence = sequence + 1 WHERE tournament_id = %d AND club_id = %d AND sequence >= %d",
                $tournament_id, CurrentClub::id(), $insert_at
            ) );
        }

        $ok = $wpdb->insert( "{$p}tt_tournament_matches", [
            'tournament_id'        => $tournament_id,
            'club_id'              => CurrentClub::id(),
            'sequence'             => $insert_at,
            'label'                => $label !== '' ? $label : null,
            'opponent_name'        => $opp !== '' ? $opp : null,
            'opponent_level'       => $level !== '' ? $level : null,
            'formation'            => $form !== '' ? $form : null,
            'duration_min'         => $duration,
            'substitution_windows' => wp_json_encode( $windows ),
        ] );
        if ( ! $ok ) {
            Logger::error( 'tournament.match.add.db_error', [
                'tournament_id' => $tournament_id,
                'db_error'      => (string) $wpdb->last_error,
            ] );
            self::redirectBackWithError( $tournament_id, __( 'The match could not be created.', 'talenttrack' ) );
        }
        $match_id = (int) $wpdb->insert_id;
        do_action( 'tt_tournament_match_created', $tournament_id, $match_id );

        $detail_url = add_query_arg(
            [ 'tt_view' => 'tournaments', 'id' => $tournament_id ],
            self::dashboardBaseUrl()
        );
        wp_safe_redirect( $detail_url );
        exit;
    }

    private static function redirectBackWithError( int $tournament_id, string $message ): void {
        $back_url = add_query_arg(
            [ 'tt_view' => 'tournament-match', 'action' => 'new', 'tournament_id' => $tournament_id, 'tt_err' => rawurlencode( $message ) ],
            self::dashboardBaseUrl()
        );
        wp_safe_redirect( $back_url );
        exit;
    }

    private static function dashboardBaseUrl(): string {
        // `WizardEntryPoint::dashboardBaseUrl()` runs the full resolver
        // chain (`dashboard_page_id` config → shortcode-page scan →
        // REQUEST_URI → home_url) and is the canonical "where does the
        // dashboard live" helper used by every cross-page redirect in
        // the codebase. Safe to call at admin-post.php time — the
        // resolver doesn't depend on the wizard runtime.
        if ( class_exists( '\\TT\\Shared\\Wizards\\WizardEntryPoint' ) ) {
            return \TT\Shared\Wizards\WizardEntryPoint::dashboardBaseUrl();
        }
        return home_url( '/' );
    }

    private static function loadTournament( int $id ): ?object {
        if ( $id <= 0 ) return null;
        global $wpdb; $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}tt_tournaments WHERE id = %d AND club_id = %d",
            $id, CurrentClub::id()
        ) );
        return $row ?: null;
    }
}
