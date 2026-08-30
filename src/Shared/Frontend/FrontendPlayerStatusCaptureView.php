<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\PotentialBand;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Players\Repositories\PlayerBehaviourRatingsRepository;
use TT\Modules\Players\Repositories\PlayerPotentialRepository;
use TT\Modules\Players\Services\PotentialTrajectory;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FormSaveButton;

/**
 * FrontendPlayerStatusCaptureView — single consolidated entry point
 * for recording behaviour + potential against a player (#0063).
 *
 * Replaces the user's "where do I register behaviour / where do I
 * register potential — both confusing" complaint with one screen
 * reachable from the player detail. Two side-by-side forms POST
 * to the existing REST endpoints (`/players/{id}/behaviour-ratings`
 * and `/players/{id}/potential`).
 *
 * Caps:
 *   - Behaviour rating: `tt_rate_player_behaviour`.
 *   - Potential:        `tt_set_player_potential`.
 * Both are fine-grained; a coach without potential-rights can still
 * record behaviour, and the form for the missing capability is
 * suppressed rather than rendered as an error.
 */
final class FrontendPlayerStatusCaptureView extends FrontendViewBase {

    public const NONCE_ACTION = 'tt_player_status_capture';
    public const NONCE_FIELD  = '_tt_status_capture_nonce';

    public static function render( int $user_id, bool $is_admin ): void {
        $player_id = isset( $_GET['player_id'] ) ? absint( $_GET['player_id'] ) : 0;
        $dash      = \TT\Shared\Frontend\Components\RecordLink::dashboardUrl();
        $back_url  = $player_id > 0
            ? add_query_arg( [ 'tt_view' => 'players', 'id' => $player_id ], $dash )
            : add_query_arg( [ 'tt_view' => 'players' ], $dash );

        $player = $player_id > 0 ? QueryHelpers::get_player( $player_id ) : null;

        // v3.92.1 — breadcrumb chain. When player is loaded, chain
        // through Players → [player name]; otherwise just Dashboard.
        if ( $player ) {
            $player_name = QueryHelpers::player_display_name( $player );
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
                __( 'Capture behaviour & potential', 'talenttrack' ),
                [
                    \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'players', __( 'Players', 'talenttrack' ) ),
                    \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'players', $player_name, [ 'id' => $player_id ] ),
                ]
            );
        } else {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Capture behaviour & potential', 'talenttrack' ) );
        }

        if ( ! $player ) {
            self::renderHeader( __( 'Player not found', 'talenttrack' ) );
            return;
        }
        // #2574 / #3243 — both halves are feature-gated now, so the view is
        // reachable while EITHER is available and refuses only when neither
        // is. Without this it would render a heading and nothing else for an
        // academy that switched both off.
        $behaviour_ok = \TT\Modules\Players\PlayerStatusModule::behaviourCaptureAvailable();
        $potential_ok = \TT\Modules\Players\PlayerStatusModule::potentialCaptureAvailable();
        if ( ! $behaviour_ok && ! $potential_ok ) {
            self::renderHeader( __( 'Capture behaviour & potential', 'talenttrack' ) );
            // Deliberately one message for two different causes. Telling a
            // coach which of "your academy does not do this" and "you may
            // not do this" applies would leak the club's configuration to
            // somebody who cannot act on either.
            echo '<p class="tt-notice">' . esc_html__( 'Behaviour and potential ratings are not being recorded here.', 'talenttrack' ) . '</p>';
            return;
        }

        // Handle POST.
        $flash = '';
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST[ self::NONCE_FIELD ] )
             && wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
            $kind = isset( $_POST['kind'] ) ? sanitize_key( (string) $_POST['kind'] ) : '';
            if ( $kind === 'behaviour' && \TT\Modules\Players\PlayerStatusModule::behaviourCaptureAvailable() ) {
                $related_activity = isset( $_POST['related_activity_id'] )
                    ? absint( $_POST['related_activity_id'] )
                    : 0;
                $rating = isset( $_POST['rating'] ) ? (float) $_POST['rating'] : 0.0;
                $notes  = isset( $_POST['notes'] )  ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) ) : '';
                // v3.74.2 — gate against the configured rating scale so
                // clubs that customise min/max/step still validate
                // correctly (was hardcoded 1.0–5.0).
                $rmin = (float) QueryHelpers::get_config( 'rating_min', '5' );
                $rmax = (float) QueryHelpers::get_config( 'rating_max', '10' );
                if ( $rating >= $rmin && $rating <= $rmax ) {
                    ( new PlayerBehaviourRatingsRepository() )->create( [
                        'player_id'           => $player_id,
                        'rating'              => $rating,
                        'notes'               => $notes !== '' ? $notes : null,
                        // v3.74.2 — #15: behaviour ratings can be tied
                        // to a specific completed activity so the
                        // history reads as "during game-X" instead of
                        // a free-floating score.
                        'related_activity_id' => $related_activity > 0 ? $related_activity : null,
                    ] );
                    $flash = __( 'Behaviour rating saved.', 'talenttrack' );
                }
            } elseif ( $kind === 'potential' && \TT\Modules\Players\PlayerStatusModule::potentialCaptureAvailable() ) {
                $band  = isset( $_POST['potential_band'] ) ? sanitize_key( (string) $_POST['potential_band'] ) : '';
                $notes = isset( $_POST['notes'] )          ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) ) : '';
                $valid = PotentialBand::ALL;
                if ( in_array( $band, $valid, true ) ) {
                    ( new PlayerPotentialRepository() )->create( [
                        'player_id'      => $player_id,
                        'potential_band' => $band,
                        'notes'          => $notes !== '' ? $notes : null,
                    ] );
                    $flash = __( 'Potential band saved.', 'talenttrack' );
                }
            }
        }

        self::enqueueAssets();
        self::enqueueViewCss();
        self::renderHeader( sprintf(
            /* translators: %s = player name */
            __( 'Behaviour & potential — %s', 'talenttrack' ),
            QueryHelpers::player_display_name( $player )
        ) );

        if ( $flash !== '' ) {
            echo '<div class="tt-notice tt-notice-success">' . esc_html( $flash ) . '</div>';
        }

        $recent_behaviour = ( new PlayerBehaviourRatingsRepository() )->listForPlayer( $player_id, 5 );
        $latest_potential = ( new PlayerPotentialRepository() )->latestFor( $player_id );

        // Cancel target: the player detail page (these capture forms are
        // reached from a player). tt_back overrides when present (§6).
        $resolved_back = BackLink::resolve();
        $cancel_url    = $resolved_back !== null ? (string) $resolved_back['url'] : $back_url;

        echo '<div class="tt-psc-grid">';

        // Behaviour column
        if ( \TT\Modules\Players\PlayerStatusModule::behaviourCaptureAvailable() ) :
            // v3.74.2 — pull rating-scale settings + the player's recent
            // completed activities so the form matches club config and
            // can tie a rating to "during game X".
            $rmin = (float) QueryHelpers::get_config( 'rating_min', '5' );
            $rmax = (float) QueryHelpers::get_config( 'rating_max', '10' );
            $rstep = (float) QueryHelpers::get_config( 'rating_step', '1' );
            $rstep = $rstep > 0 ? $rstep : 1.0;
            $recent_activities = self::loadRecentActivitiesForPlayer( $player_id, 20 );
            ?>
            <section class="tt-psc-card">
                <h3 class="tt-psc-card__head"><?php esc_html_e( 'Record a behaviour rating', 'talenttrack' ); ?></h3>
                <form method="post">
                    <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                    <input type="hidden" name="kind" value="behaviour" />
                    <p class="tt-psc-field">
                        <label class="tt-field-label tt-field-required" for="tt-bh-rating">
                            <?php
                            printf(
                                /* translators: 1: scale min, 2: scale max */
                                esc_html__( 'Rating (%1$s – %2$s)', 'talenttrack' ),
                                esc_html( (string) $rmin ),
                                esc_html( (string) $rmax )
                            );
                            ?>
                        </label>
                        <select id="tt-bh-rating" name="rating" required class="tt-input">
                            <?php
                            // Step through the configured rating scale.
                            $val = $rmin;
                            while ( $val <= $rmax + 0.0001 ) {
                                $display = $rstep < 1 ? rtrim( rtrim( number_format( $val, 2, '.', '' ), '0' ), '.' ) : (string) (int) $val;
                                printf( '<option value="%s">%s</option>', esc_attr( (string) $val ), esc_html( $display ) );
                                $val += $rstep;
                            }
                            ?>
                        </select>
                    </p>
                    <?php if ( ! empty( $recent_activities ) ) : ?>
                    <p class="tt-psc-field">
                        <label class="tt-field-label" for="tt-bh-activity"><?php esc_html_e( 'Related activity (optional)', 'talenttrack' ); ?></label>
                        <select id="tt-bh-activity" name="related_activity_id" class="tt-input">
                            <option value="0"><?php esc_html_e( '— None —', 'talenttrack' ); ?></option>
                            <?php foreach ( $recent_activities as $act ) : ?>
                                <option value="<?php echo (int) $act->id; ?>">
                                    <?php echo esc_html( sprintf( '%s · %s', \TT\Shared\Dates\TTDate::date( (string) $act->session_date ), (string) $act->title ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <?php endif; ?>
                    <p class="tt-psc-field">
                        <label class="tt-field-label" for="tt-bh-notes"><?php esc_html_e( 'Notes', 'talenttrack' ); ?></label>
                        <textarea id="tt-bh-notes" class="tt-input" name="notes" rows="3" placeholder="<?php esc_attr_e( 'Optional context — e.g. "responded well to substitution", "leadership in warm-up".', 'talenttrack' ); ?>"></textarea>
                    </p>
                    <div class="tt-psc-actions">
                        <?php echo FormSaveButton::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            'label'      => __( 'Save behaviour rating', 'talenttrack' ),
                            'cancel_url' => $cancel_url,
                        ] ); ?>
                    </div>
                </form>

                <?php if ( ! empty( $recent_behaviour ) ) : ?>
                    <div class="tt-psc-recent">
                    <h4 class="tt-psc-recent__head"><?php esc_html_e( 'Recent ratings', 'talenttrack' ); ?></h4>
                    <ul class="tt-psc-recent__list">
                        <?php foreach ( $recent_behaviour as $b ) :
                            // v3.74.2 — show rated_at (the meaningful "this
                            // happened on" date) instead of created_at;
                            // for legacy rows where rated_at is null,
                            // fall back. Also surface the related
                            // activity link.
                            $when = (string) ( $b->rated_at ?? $b->created_at ?? '' );
                            $related_activity_id = (int) ( $b->related_activity_id ?? 0 );
                            ?>
                            <li class="tt-psc-recent__item">
                                <span class="tt-psc-score"><?php echo esc_html( number_format_i18n( (float) $b->rating, 1 ) ); ?></span>
                                <span class="tt-psc-recent__meta"><?php echo esc_html( $when ); ?></span>
                                <?php if ( $related_activity_id > 0 ) : ?>
                                    <span class="tt-psc-recent__meta">
                                        <?php
                                        $act_url = \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'activities', $related_activity_id );
                                        echo \TT\Shared\Frontend\Components\RecordLink::inline( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                            __( 'View activity', 'talenttrack' ),
                                            $act_url
                                        );
                                        ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ( ! empty( $b->notes ) ) : ?>
                                    <div class="tt-psc-recent__notes"><?php echo esc_html( (string) $b->notes ); ?></div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    </div>
                <?php endif; ?>
            </section>
            <?php
        endif;

        // Potential column
        if ( \TT\Modules\Players\PlayerStatusModule::potentialCaptureAvailable() ) :
            ?>
            <section class="tt-psc-card">
                <h3 class="tt-psc-card__head"><?php esc_html_e( 'Set potential', 'talenttrack' ); ?></h3>
                <form method="post">
                    <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                    <input type="hidden" name="kind" value="potential" />
                    <p class="tt-psc-field">
                        <label class="tt-field-label tt-field-required" for="tt-pot-band"><?php esc_html_e( 'Potential band', 'talenttrack' ); ?></label>
                        <select id="tt-pot-band" name="potential_band" required class="tt-input">
                            <?php
                            // #3226 — one source for the band labels; there
                            // were two copies of this map and the trajectory
                            // would have been a third.
                            $bands = PotentialTrajectory::labels();
                            $current_band = $latest_potential ? (string) $latest_potential->potential_band : '';
                            foreach ( $bands as $code => $label ) :
                                ?>
                                <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $current_band, $code ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <p class="tt-psc-field">
                        <label class="tt-field-label" for="tt-pot-notes"><?php esc_html_e( 'Notes', 'talenttrack' ); ?></label>
                        <textarea id="tt-pot-notes" class="tt-input" name="notes" rows="3" placeholder="<?php esc_attr_e( "Optional rationale — e.g. why you've revised the band up or down.", 'talenttrack' ); ?>"></textarea>
                    </p>
                    <div class="tt-psc-actions">
                        <?php echo FormSaveButton::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            'label'      => __( 'Save', 'talenttrack' ),
                            'cancel_url' => $cancel_url,
                        ] ); ?>
                    </div>
                </form>

                <?php if ( $latest_potential ) : ?>
                    <p class="tt-psc-current">
                        <?php
                        // v3.74.2 — show set_at (the meaningful "this is
                        // when we judged it" date) instead of created_at.
                        $when_set = (string) ( $latest_potential->set_at ?? $latest_potential->created_at ?? '' );
                        printf(
                            /* translators: 1: band 2: timestamp */
                            esc_html__( 'Current: %1$s (recorded on %2$s).', 'talenttrack' ),
                            esc_html( $bands[ (string) $latest_potential->potential_band ] ?? (string) $latest_potential->potential_band ),
                            esc_html( $when_set )
                        );
                        ?>
                    </p>
                <?php endif; ?>

                <?php self::renderPotentialHistory( $player_id ); ?>
            </section>
            <?php
        endif;

        echo '</div>';
    }

    /**
     * #3226 — the trajectory, newest first.
     *
     * `tt_player_potential` has been append-only since migration 0042 and
     * the history was read by nothing a user could see. This is the screen
     * the profile's "View potential history →" link already pointed at, so
     * it is where the history belongs; until now that link landed on a page
     * showing only the current band.
     *
     * Direction is carried by a word as well as an arrow and a class.
     * Colour and glyph alone fail a colour-blind reader and a screen
     * reader respectively, and "the academy revised this child down" is
     * not a thing to communicate ambiguously.
     *
     * Composition only — the sequence and its directions come from
     * `PotentialTrajectory`, which the REST route also uses (CLAUDE.md §4).
     */
    private static function renderPotentialHistory( int $player_id ): void {
        $entries = ( new PotentialTrajectory() )->forPlayer( $player_id );

        // Nothing recorded: the form above is the whole story, and an
        // empty history card would only add noise.
        if ( ! $entries ) return;

        // One entry is not a trajectory — the "Current:" line above
        // already says everything there is to say.
        if ( count( $entries ) < 2 ) return;

        $entries = array_reverse( $entries );
        ?>
        <details class="tt-psc-history" open>
            <summary class="tt-psc-history__summary">
                <?php
                printf(
                    /* translators: %d: number of recorded potential entries */
                    esc_html( _n( 'Potential history (%d entry)', 'Potential history (%d entries)', count( $entries ), 'talenttrack' ) ),
                    (int) count( $entries )
                );
                ?>
            </summary>
            <ol class="tt-psc-history__list">
                <?php foreach ( $entries as $entry ) :
                    $direction = (string) $entry['direction'];
                    [ $glyph, $word ] = self::directionCue( $direction );
                    ?>
                    <li class="tt-psc-history__item tt-psc-history__item--<?php echo esc_attr( $direction ); ?>">
                        <p class="tt-psc-history__head">
                            <span class="tt-psc-history__band"><?php echo esc_html( (string) $entry['label'] ); ?></span>
                            <?php if ( $word !== '' ) : ?>
                                <span class="tt-psc-history__dir">
                                    <span aria-hidden="true"><?php echo esc_html( $glyph ); ?></span>
                                    <?php echo esc_html( $word ); ?>
                                </span>
                            <?php endif; ?>
                        </p>
                        <p class="tt-psc-history__meta">
                            <?php
                            $when = (string) $entry['set_at'];
                            $who  = (string) $entry['set_by_name'];
                            if ( $who !== '' ) {
                                printf(
                                    /* translators: 1: date 2: person who recorded it */
                                    esc_html__( '%1$s · by %2$s', 'talenttrack' ),
                                    esc_html( $when ),
                                    esc_html( $who )
                                );
                            } else {
                                echo esc_html( $when );
                            }
                            ?>
                        </p>
                        <?php if ( (string) $entry['notes'] !== '' ) : ?>
                            <p class="tt-psc-history__notes"><?php echo esc_html( (string) $entry['notes'] ); ?></p>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </details>
        <?php
    }

    /**
     * Glyph plus word for a revision. The word is what carries the meaning
     * — the glyph is decorative and hidden from assistive tech.
     *
     * @return array{0:string,1:string}
     */
    private static function directionCue( string $direction ): array {
        switch ( $direction ) {
            case PotentialTrajectory::UP:
                return [ '▲', __( 'revised up', 'talenttrack' ) ];
            case PotentialTrajectory::DOWN:
                return [ '▼', __( 'revised down', 'talenttrack' ) ];
            case PotentialTrajectory::SAME:
                return [ '=', __( 'reaffirmed', 'talenttrack' ) ];
            default:
                return [ '', '' ];
        }
    }

    /**
     * #1320 — routed through ActivitiesRepository::listRecentCompletedForPlayer
     * so this view and the FrontendPlayerDetailView hero popovers share
     * one source for the "Related activity" dropdown query.
     *
     * @return list<object>
     */
    private static function loadRecentActivitiesForPlayer( int $player_id, int $limit = 20 ): array {
        return ( new \TT\Modules\Activities\Repositories\ActivitiesRepository() )
            ->listRecentCompletedForPlayer( $player_id, $limit );
    }

    private static function enqueueViewCss(): void {
        wp_enqueue_style(
            'tt-frontend-player-status-capture',
            TT_PLUGIN_URL . 'assets/css/frontend-player-status-capture.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
    }
}
