<?php
namespace TT\Modules\Measurements\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository;
use TT\Modules\Measurements\Repositories\MeasurementResultsRepository;
use TT\Modules\Measurements\Repositories\MeasurementSessionsRepository;
use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * FrontendMeasurementEntryView (#1856) — bulk result entry for a team.
 *
 * The staff workflow: "we just ran the bleep test for the U17s — record
 * everyone's value." Pick a team + a test + a date, then enter one value
 * per player and save in one shot. Saving creates a testing session and
 * one result per filled-in player against it. Blank rows write nothing.
 *
 * Slug: `measurements-entry`. Matrix-gated on `measurements` change
 * (staff at team scope; HoD/admin global). Bulk entry is a §3(b)
 * wizard exemption.
 */
final class FrontendMeasurementEntryView extends FrontendViewBase {

    public const NONCE_ACTION = 'tt_measurement_entry';
    public const NONCE_FIELD  = '_tt_measurement_entry_nonce';

    public static function render( int $user_id, bool $is_admin ): void {
        FrontendBreadcrumbs::fromDashboard( __( 'Record measurements', 'talenttrack' ) );

        if ( ! MatrixGate::canAnyScope( $user_id, 'measurements', 'change' ) ) {
            self::renderHeader( __( 'Record measurements', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to record measurements.', 'talenttrack' ) . '</p>';
            return;
        }

        $see_all = $is_admin || MatrixGate::can( $user_id, 'measurements', 'change', 'global' );
        $teams   = $see_all ? self::allTeams() : QueryHelpers::get_teams_for_coach( $user_id );
        if ( empty( $teams ) ) {
            self::renderHeader( __( 'Record measurements', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'No teams are available to you yet.', 'talenttrack' ) . '</p>';
            return;
        }
        $allowed_team_ids = array_map( static fn( $t ) => (int) $t->id, (array) $teams );

        $definitions = ( new MeasurementDefinitionsRepository() )->listActive();
        if ( empty( $definitions ) ) {
            self::renderHeader( __( 'Record measurements', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'No tests have been set up yet. Create a test first.', 'talenttrack' ) . '</p>';
            return;
        }

        $team_id       = isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0;
        $definition_id = isset( $_GET['definition_id'] ) ? absint( $_GET['definition_id'] ) : 0;
        $date          = isset( $_GET['date'] ) ? sanitize_text_field( (string) $_GET['date'] ) : current_time( 'Y-m-d' );

        if ( $team_id > 0 && ! in_array( $team_id, $allowed_team_ids, true ) ) {
            self::renderHeader( __( 'Record measurements', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to this team.', 'talenttrack' ) . '</p>';
            return;
        }

        $flash = '';
        if ( $_SERVER['REQUEST_METHOD'] === 'POST'
             && isset( $_POST[ self::NONCE_FIELD ] )
             && wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION )
             && in_array( $team_id, $allowed_team_ids, true ) ) {
            $flash = self::handlePost( $team_id, $definition_id, $date );
        }

        self::enqueueAssets();
        self::enqueueViewCss();
        self::renderHeader( __( 'Record measurements', 'talenttrack' ) );

        if ( $flash !== '' ) {
            echo '<div class="tt-notice tt-notice-success">' . esc_html( $flash ) . '</div>';
        }

        self::renderPicker( $teams, $definitions, $team_id, $definition_id, $date );

        if ( $team_id <= 0 || $definition_id <= 0 ) {
            return;
        }

        $definition = self::findDefinition( $definitions, $definition_id );
        if ( ! $definition ) return;

        $players = QueryHelpers::get_players( $team_id );
        if ( empty( $players ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No active players on this team yet.', 'talenttrack' ) . '</p>';
            return;
        }

        self::renderRoster( $players, $definition, $team_id, $date );
    }

    /**
     * @param array<int, object> $teams
     * @param array<int, object> $definitions
     */
    private static function renderPicker( array $teams, array $definitions, int $team_id, int $definition_id, string $date ): void {
        $base = RecordLink::dashboardUrl();
        ?>
        <form method="get" class="tt-me-picker">
            <input type="hidden" name="tt_view" value="measurements-entry" />
            <label class="tt-me-field">
                <span class="tt-me-label"><?php esc_html_e( 'Team', 'talenttrack' ); ?></span>
                <select name="team_id" class="tt-input">
                    <option value="0"><?php esc_html_e( '— choose a team —', 'talenttrack' ); ?></option>
                    <?php foreach ( $teams as $t ) : ?>
                        <option value="<?php echo (int) $t->id; ?>"<?php selected( $team_id, (int) $t->id ); ?>>
                            <?php echo esc_html( (string) $t->name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="tt-me-field">
                <span class="tt-me-label"><?php esc_html_e( 'Test', 'talenttrack' ); ?></span>
                <select name="definition_id" class="tt-input">
                    <option value="0"><?php esc_html_e( '— choose a test —', 'talenttrack' ); ?></option>
                    <?php foreach ( $definitions as $d ) : ?>
                        <option value="<?php echo (int) $d->id; ?>"<?php selected( $definition_id, (int) $d->id ); ?>>
                            <?php echo esc_html( (string) ( $d->category_label ?: $d->category_name ) . ' · ' . (string) $d->name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="tt-me-field">
                <span class="tt-me-label"><?php esc_html_e( 'Date', 'talenttrack' ); ?></span>
                <input type="date" name="date" class="tt-input" value="<?php echo esc_attr( $date ); ?>" />
            </label>
            <button type="submit" class="tt-btn tt-btn-secondary tt-me-load">
                <?php esc_html_e( 'Show players', 'talenttrack' ); ?>
            </button>
        </form>
        <?php
    }

    /**
     * @param array<int, object> $players
     */
    private static function renderRoster( array $players, object $definition, int $team_id, string $date ): void {
        $unit  = (string) ( $definition->unit ?? '' );
        $vtype = (string) $definition->value_type;
        $base  = RecordLink::dashboardUrl();
        $cancel_url = add_query_arg( [ 'tt_view' => 'measurements-entry' ], $base );
        ?>
        <form method="post" class="tt-me-form">
            <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
            <p class="tt-me-intro">
                <?php echo esc_html( sprintf(
                    /* translators: 1: test name, 2: date */
                    __( 'Entering "%1$s" for %2$s. Leave a player blank to skip.', 'talenttrack' ),
                    (string) $definition->name,
                    self::formatDate( $date )
                ) ); ?>
            </p>
            <div class="tt-me-roster">
                <?php foreach ( $players as $pl ) :
                    $pid  = (int) $pl->id;
                    $name = QueryHelpers::player_display_name( $pl );
                    $fid  = 'tt-me-val-' . $pid;
                ?>
                    <div class="tt-me-row">
                        <label class="tt-me-row-name" for="<?php echo esc_attr( $fid ); ?>">
                            <?php echo esc_html( $name ); ?>
                        </label>
                        <div class="tt-me-row-input">
                            <?php self::renderValueInput( $fid, $pid, $vtype ); ?>
                            <?php if ( $unit !== '' && $vtype === 'numeric' ) : ?>
                                <span class="tt-me-unit"><?php echo esc_html( $unit ); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="tt-me-footer">
                <a class="tt-btn tt-btn-secondary" href="<?php echo esc_url( $cancel_url ); ?>">
                    <?php esc_html_e( 'Cancel', 'talenttrack' ); ?>
                </a>
                <button type="submit" class="tt-btn tt-btn-primary">
                    <?php esc_html_e( 'Save all', 'talenttrack' ); ?>
                </button>
            </div>
        </form>
        <?php
    }

    private static function renderValueInput( string $fid, int $pid, string $vtype ): void {
        $name = 'value[' . $pid . ']';
        if ( $vtype === 'passfail' ) {
            ?>
            <select id="<?php echo esc_attr( $fid ); ?>" class="tt-input" name="<?php echo esc_attr( $name ); ?>">
                <option value=""><?php esc_html_e( '— skip —', 'talenttrack' ); ?></option>
                <option value="pass"><?php esc_html_e( 'Pass', 'talenttrack' ); ?></option>
                <option value="fail"><?php esc_html_e( 'Fail', 'talenttrack' ); ?></option>
            </select>
            <?php
            return;
        }
        // numeric + scale both take a number; inputmode decimal for mobile keyboards.
        ?>
        <input type="number" step="any" inputmode="decimal"
               id="<?php echo esc_attr( $fid ); ?>" class="tt-input"
               name="<?php echo esc_attr( $name ); ?>"
               placeholder="<?php esc_attr_e( 'value', 'talenttrack' ); ?>" />
        <?php
    }

    /**
     * Create the session + one result per filled player. Returns a flash.
     */
    private static function handlePost( int $team_id, int $definition_id, string $date ): string {
        if ( $definition_id <= 0 ) {
            return __( 'Choose a test before saving.', 'talenttrack' );
        }
        $entries = isset( $_POST['value'] ) && is_array( $_POST['value'] )
            ? wp_unslash( $_POST['value'] )
            : [];

        $values = [];
        foreach ( $entries as $pid => $raw ) {
            $pid = (int) $pid;
            $raw = is_string( $raw ) ? trim( $raw ) : '';
            if ( $pid <= 0 || $raw === '' ) continue;
            $values[ $pid ] = $raw;
        }
        if ( empty( $values ) ) {
            return __( 'No values entered. Fill in at least one player before saving.', 'talenttrack' );
        }

        $definition = ( new MeasurementDefinitionsRepository() )->find( $definition_id );
        $is_numeric = ! $definition || $definition->value_type !== 'passfail';

        $session_pk = ( new MeasurementSessionsRepository() )->create( [
            'definition_id' => $definition_id,
            'team_id'       => $team_id,
            'planned_date'  => $date,
            'status'        => 'completed',
        ] );

        $results = new MeasurementResultsRepository();
        $count   = 0;
        foreach ( $values as $pid => $raw ) {
            $data = [
                'player_id'              => $pid,
                'definition_id'          => $definition_id,
                'measurement_session_id' => $session_pk,
                'recorded_date'          => $date,
            ];
            if ( $is_numeric && is_numeric( $raw ) ) {
                $data['value_numeric'] = (float) $raw;
            } else {
                $data['value_text'] = sanitize_text_field( (string) $raw );
            }
            if ( ( $results->create( $data ) ) > 0 ) {
                $count++;
            }
        }

        return sprintf(
            /* translators: %d: number of measurements recorded */
            _n( '%d measurement recorded.', '%d measurements recorded.', $count, 'talenttrack' ),
            $count
        );
    }

    /**
     * @param array<int, object> $definitions
     */
    private static function findDefinition( array $definitions, int $id ): ?object {
        foreach ( $definitions as $d ) {
            if ( (int) $d->id === $id ) return $d;
        }
        return null;
    }

    /**
     * @return array<int, object>
     */
    private static function allTeams(): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name FROM {$p}tt_teams WHERE club_id = %d AND archived_at IS NULL ORDER BY name ASC",
            CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    private static function formatDate( string $date ): string {
        $ts = strtotime( $date );
        if ( ! $ts ) return $date;
        return date_i18n( (string) get_option( 'date_format', 'Y-m-d' ), $ts );
    }

    private static function enqueueViewCss(): void {
        wp_enqueue_style(
            'tt-frontend-measurements',
            TT_PLUGIN_URL . 'assets/css/frontend-measurements.css',
            [ 'tt-frontend-mobile' ],
            TT_VERSION
        );
    }
}
