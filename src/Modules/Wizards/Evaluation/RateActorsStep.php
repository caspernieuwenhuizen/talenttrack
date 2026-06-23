<?php
namespace TT\Modules\Wizards\Evaluation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * RateActorsStep (#0072) — the heart of the activity-first wizard.
 *
 * Renders the present/late players for the picked activity and lets
 * the coach quick-rate (one row per `meta.quick_rate`-flagged
 * category) or deep-rate (full sub-criteria + notes) each player in
 * one submission.
 *
 * #0080 Wave B4 — markup is now mobile-first. Each player renders as
 * a `<details>` card containing a `.tt-rate-grid`; per-category rows
 * stack vertically on phones (`<720px`) and switch to a 180px-label
 * + control two-column grid on `≥720px`. Inputs hit the v3.50.0 48px
 * touch-target floor; numeric inputs keep `inputmode="numeric"`.
 */
final class RateActorsStep implements WizardStepInterface {

    public function slug(): string  { return 'rate-actors'; }
    public function label(): string { return __( 'Rate players', 'talenttrack' ); }

    public function notApplicableFor( array $state ): bool {
        return ( $state['_path'] ?? '' ) !== 'activity-first';
    }

    public function render( array $state ): void {
        // #1067 — chip-grid / slider-row component CSS + JS. Idempotent
        // enqueue (WP no-ops a re-registered handle); shared with
        // HybridDeepRateStep and the post-game / self-eval forms.
        wp_enqueue_style(
            'tt-rating-input',
            TT_PLUGIN_URL . 'assets/css/components/rating-input.css',
            [],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-rating-input',
            TT_PLUGIN_URL . 'assets/js/components/rating-input.js',
            [],
            TT_VERSION,
            true
        );

        global $wpdb;
        $p = $wpdb->prefix;

        $aid = (int) ( $state['activity_id'] ?? 0 );
        $players = self::ratablePlayersForActivity( $aid );

        $quick_cats = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, label, category_key FROM {$p}tt_eval_categories
              WHERE parent_id IS NULL AND is_active = 1
                AND meta IS NOT NULL AND meta LIKE %s
              ORDER BY display_order, label",
            '%"quick_rate":true%'
        ) );

        // #1643 — for training activities the `mental` main category is
        // surfaced first and pre-expanded by default. Policy lives in the
        // domain layer (TrainingEvalDefaults), not in this view.
        $is_training = self::isTrainingActivity( $aid );
        if ( $is_training ) {
            $quick_cats = \TT\Infrastructure\Evaluations\TrainingEvalDefaults::sortPriorityFirst( (array) $quick_cats );
        }

        // v3.108.4 — A3: pull every active subcategory keyed by its
        // parent so each main row can render its detail children
        // beneath. Sub-categories already exist in the schema (parent_id
        // points to a main row) and the seed ships ~21 of them across
        // the 4 main categories; the wizard just never surfaced them.
        // Quick-rate stays on the main category; the subcategory inputs
        // are nested under each main row inside a `<details>` so the
        // coach can drill into a detailed rating without losing the
        // top-line ergonomic.
        $sub_cats_raw = $wpdb->get_results(
            "SELECT id, parent_id, label FROM {$p}tt_eval_categories
              WHERE parent_id IS NOT NULL AND is_active = 1
              ORDER BY parent_id, display_order, label"
        );
        $sub_cats_by_parent = [];
        foreach ( (array) $sub_cats_raw as $sc ) {
            $pid = (int) $sc->parent_id;
            $sub_cats_by_parent[ $pid ][] = $sc;
        }

        // v3.110.116 — was reading `wp_options[tt_rating_scale_max]`
        // (a stale key that never got written by the modern config UI)
        // with a hardcoded `5` fallback. Reads `tt_config[rating_max]`
        // / `rating_min` instead so the wizard input bounds track the
        // active scale (5–10 default post-migration). The dead SELECT
        // on tt_eval_categories also removed — never read.
        $min = (int) round( (float) \TT\Infrastructure\Query\QueryHelpers::get_config( 'rating_min', '5' ) );
        $max = (int) round( (float) \TT\Infrastructure\Query\QueryHelpers::get_config( 'rating_max', '10' ) );

        if ( empty( $players ) ) :
            ?><p class="tt-notice"><?php esc_html_e( 'No players to rate. Mark attendance first or pick another activity.', 'talenttrack' ); ?></p><?php
            return;
        endif;
        ?>
        <p style="color:var(--tt-muted);max-width:60ch;">
            <?php
            printf(
                /* translators: %d: player count */
                esc_html( _n( '%d player ready to rate. Tap a player to expand and rate; tap again to collapse.', '%d players ready to rate. Tap a player to expand and rate; tap again to collapse.', count( $players ), 'talenttrack' ) ),
                count( $players )
            );
            ?>
        </p>

        <?php
        // v3.110.75 — sticky overall progress at the top of the step.
        // Renders an empty "0 of N rated" line server-side; the inline
        // script below updates it as the coach rates / skips. aria-live
        // so screen readers announce updates.
        $main_cat_count = count( (array) $quick_cats );
        ?>
        <div class="tt-rate-progress" data-tt-rate-progress aria-live="polite"
             data-i18n-template="<?php
                 esc_attr_e(
                     /* translators: %1$d = number of players done (rated or skipped), %2$d = total players to rate. */
                     '%1$d of %2$d players rated',
                     'talenttrack'
                 );
             ?>">
            <?php
            printf(
                /* translators: %1$d = 0, %2$d = total. Initial server-rendered state before JS updates it. */
                esc_html__( '%1$d of %2$d players rated', 'talenttrack' ),
                0,
                count( $players )
            );
            ?>
        </div>

        <?php
        // #1372 — interruption-recovery notice. Hidden until the
        // buffer script below restores values from sessionStorage.
        ?>
        <p class="tt-notice" data-tt-rate-restored hidden>
            <?php esc_html_e( 'Restored your in-progress ratings from this device.', 'talenttrack' ); ?>
        </p>

        <div class="tt-rate-roster" data-tt-rate-roster data-main-cat-count="<?php echo (int) $main_cat_count; ?>"
             data-tt-rate-buffer-key="<?php echo esc_attr( 'tt-rate-buffer-' . $aid ); ?>"
             data-i18n-not-rated="<?php esc_attr_e( 'Not rated', 'talenttrack' ); ?>"
             data-i18n-rating="<?php esc_attr_e( 'Rating…', 'talenttrack' ); ?>"
             data-i18n-rated="<?php esc_attr_e( 'Rated', 'talenttrack' ); ?>"
             data-i18n-skipped="<?php esc_attr_e( 'Skipped', 'talenttrack' ); ?>">

        <?php foreach ( $players as $pl ) :
            $name = trim( (string) $pl->first_name . ' ' . (string) $pl->last_name );
            $pid  = (int) $pl->id;
            ?>
            <details class="tt-rate-player" data-tt-rate-player data-pid="<?php echo $pid; ?>">
                <summary class="tt-rate-player-summary">
                    <span class="tt-rate-player-name"><?php echo esc_html( $name ); ?></span>
                    <?php if ( ! empty( $pl->is_guest ) ) : ?>
                        <span class="tt-rate-player-guest"
                              title="<?php esc_attr_e( 'On loan from another team for this match.', 'talenttrack' ); ?>">
                            <?php esc_html_e( 'Guest', 'talenttrack' ); ?>
                        </span>
                    <?php endif; ?>
                    <span class="tt-rate-player-status tt-rate-player-status--empty" data-tt-rate-status>
                        <?php esc_html_e( 'Not rated', 'talenttrack' ); ?>
                    </span>
                </summary>

                <div class="tt-rate-grid">
                    <?php foreach ( (array) $quick_cats as $cat ) :
                        $cid    = (int) $cat->id;
                        $val    = (float) ( $state['ratings'][ $pid ][ $cid ] ?? 0 );
                        $iid    = 'tt-rate-' . $pid . '-' . $cid;
                        $label  = \TT\Infrastructure\Evaluations\EvalCategoriesRepository::displayLabel( (string) $cat->label, $cid );
                        $subs   = $sub_cats_by_parent[ $cid ] ?? [];
                        // #1643 — pre-expand the priority category (mental)
                        // for training activities only.
                        $expand = $is_training
                            && isset( $cat->category_key )
                            && \TT\Infrastructure\Evaluations\TrainingEvalDefaults::shouldExpand( (string) $cat->category_key );
                    ?>
                        <?php
                        echo \TT\Shared\Frontend\Components\RatingInputComponent::renderListRow( [
                            'name'        => 'ratings[' . $pid . '][' . $cid . ']',
                            'value'       => $val > 0 ? (string) $val : '',
                            'label'       => $label,
                            'min'         => (float) $min,
                            'max'         => (float) $max,
                            'input_class' => 'tt-rate-input',
                            'data_attrs'  => [ 'tt-rate-main' => (int) $cid ],
                        ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — component escapes
                        ?>
                        <?php if ( ! empty( $subs ) ) :
                            // v3.110.125 — was a native <details>/<summary>
                            // disclosure ("Detailed Technical") with the
                            // browser's default chevron + indent chrome.
                            // Replaced with a Basic/Detailed segmented
                            // pill toggle that flips a `data-state` on
                            // the subs panel; default state is Basic
                            // (subs hidden). Same DOM input names —
                            // values still POST when the operator never
                            // expanded Detailed (form values persist
                            // across toggle flips because the inputs
                            // stay in the DOM, just hidden).
                            $detail_btn_basic = __( 'Basic', 'talenttrack' );
                            $detail_btn_more  = __( 'Detailed', 'talenttrack' );
                            $toggle_state     = $expand ? 'detailed' : 'basic';
                            ?>
                            <div class="tt-rate-detail-toggle"
                                 data-tt-rate-detail-toggle
                                 data-state="<?php echo esc_attr( $toggle_state ); ?>"
                                 role="tablist"
                                 aria-label="<?php echo esc_attr( sprintf(
                                     /* translators: %s: main category label */
                                     __( '%s detail mode', 'talenttrack' ),
                                     $label
                                 ) ); ?>">
                                <button type="button" data-mode="basic"    role="tab" aria-selected="<?php echo $expand ? 'false' : 'true'; ?>"><?php echo esc_html( $detail_btn_basic ); ?></button>
                                <button type="button" data-mode="detailed" role="tab" aria-selected="<?php echo $expand ? 'true' : 'false'; ?>"><?php echo esc_html( $detail_btn_more ); ?></button>
                            </div>
                            <div class="tt-rate-subs" data-tt-rate-subs <?php echo $expand ? '' : 'hidden'; ?>>
                                <?php foreach ( $subs as $sub ) :
                                    $scid = (int) $sub->id;
                                    $sval = (float) ( $state['ratings'][ $pid ][ $scid ] ?? 0 );
                                    $siid = 'tt-rate-' . $pid . '-' . $scid;
                                    $slabel = \TT\Infrastructure\Evaluations\EvalCategoriesRepository::displayLabel( (string) $sub->label, $scid );
                                ?>
                                    <?php
                                    echo \TT\Shared\Frontend\Components\RatingInputComponent::renderListRow( [
                                        'name'        => 'ratings[' . $pid . '][' . $scid . ']',
                                        'value'       => $sval > 0 ? (string) $sval : '',
                                        'label'       => $slabel,
                                        'sub'         => true,
                                        'min'         => (float) $min,
                                        'max'         => (float) $max,
                                        'input_class' => 'tt-rate-input',
                                        'data_attrs'  => [ 'tt-rate-sub-parent' => (int) $cid ],
                                    ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — component escapes
                                    ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <div class="tt-rate-row">
                        <label class="tt-rate-label" for="tt-rate-notes-<?php echo $pid; ?>"><?php esc_html_e( 'Notes', 'talenttrack' ); ?></label>
                        <div class="tt-rate-control">
                            <textarea rows="2"
                                      id="tt-rate-notes-<?php echo $pid; ?>"
                                      class="tt-rate-notes"
                                      name="notes[<?php echo $pid; ?>]"><?php
                                echo esc_textarea( (string) ( $state['notes'][ $pid ] ?? '' ) );
                            ?></textarea>
                        </div>
                    </div>
                    <?php // #1386 — optional player-facing feedback. Separate from the
                          // staff-only Notes above: this string is shown to the player
                          // (and their parents) in My evaluations. ?>
                    <div class="tt-rate-row">
                        <label class="tt-rate-label" for="tt-rate-feedback-<?php echo $pid; ?>"><?php esc_html_e( 'Feedback for the player', 'talenttrack' ); ?></label>
                        <div class="tt-rate-control">
                            <textarea rows="2"
                                      id="tt-rate-feedback-<?php echo $pid; ?>"
                                      class="tt-rate-notes"
                                      name="player_feedback[<?php echo $pid; ?>]"
                                      placeholder="<?php esc_attr_e( 'Optional — what they did well and what to work on next. Shown to the player and their parents.', 'talenttrack' ); ?>"><?php
                                echo esc_textarea( (string) ( $state['player_feedback'][ $pid ] ?? '' ) );
                            ?></textarea>
                        </div>
                    </div>
                    <div class="tt-rate-row tt-rate-skip-row">
                        <label class="tt-rate-skip">
                            <input type="checkbox" name="skip[<?php echo $pid; ?>]" value="1" <?php checked( ! empty( $state['skip'][ $pid ] ) ); ?> />
                            <?php esc_html_e( 'Skip this player (no evaluation written)', 'talenttrack' ); ?>
                        </label>
                    </div>
                </div>
            </details>
        <?php endforeach; ?>
        </div><!-- /.tt-rate-roster -->

        <script>
        // v3.110.75 — per-player status pill + overall progress counter
        // for the rate-actors step. Scoped to the roster on this page;
        // safe to inline because the step renders only inside the
        // wizard view (no risk of running twice).
        (function () {
            var roster = document.querySelector('[data-tt-rate-roster]');
            if ( ! roster ) return;
            var progress = document.querySelector('[data-tt-rate-progress]');
            var i18n = {
                empty:    roster.getAttribute('data-i18n-not-rated') || 'Not rated',
                partial:  roster.getAttribute('data-i18n-rating')    || 'Rating…',
                complete: roster.getAttribute('data-i18n-rated')     || 'Rated',
                skipped:  roster.getAttribute('data-i18n-skipped')   || 'Skipped'
            };
            var template = progress ? ( progress.getAttribute('data-i18n-template') || '%1$d of %2$d players rated' ) : '';

            function mainInputsFor( details ) {
                // Quick-rate inputs only — exclude sub-category inputs
                // nested inside `.tt-rate-subs` <details>.
                var inputs = details.querySelectorAll( '.tt-rate-input' );
                var main = [];
                inputs.forEach( function ( input ) {
                    if ( ! input.closest( '.tt-rate-subs' ) ) main.push( input );
                } );
                return main;
            }

            function updatePlayer( details ) {
                var statusEl = details.querySelector( '[data-tt-rate-status]' );
                if ( ! statusEl ) return;
                var skipEl  = details.querySelector( 'input[name^="skip["]' );
                var main    = mainInputsFor( details );
                var filled  = 0;
                // #1067 — slider inputs sit at the midpoint when in
                // empty state and carry `data-tt-rating-empty`. Treat
                // those as unfilled regardless of their numeric value.
                main.forEach( function ( i ) {
                    if ( i.hasAttribute( 'data-tt-rating-empty' ) ) return;
                    if ( parseFloat( i.value ) > 0 ) filled++;
                } );

                var state, text;
                if ( skipEl && skipEl.checked ) {
                    state = 'skipped'; text = i18n.skipped;
                } else if ( filled === 0 ) {
                    state = 'empty';   text = i18n.empty;
                } else if ( filled >= main.length && main.length > 0 ) {
                    state = 'complete'; text = i18n.complete;
                } else {
                    state = 'partial'; text = i18n.partial;
                }
                statusEl.textContent = text;
                statusEl.className = 'tt-rate-player-status tt-rate-player-status--' + state;
                details.setAttribute( 'data-tt-rate-state', state );
            }

            function updateOverall() {
                if ( ! progress ) return;
                var details = roster.querySelectorAll( '[data-tt-rate-player]' );
                var done = 0;
                details.forEach( function ( d ) {
                    var s = d.getAttribute( 'data-tt-rate-state' ) || '';
                    if ( s === 'complete' || s === 'skipped' ) done++;
                } );
                progress.textContent = template
                    .replace( '%1$d', String( done ) )
                    .replace( '%2$d', String( details.length ) );
            }

            // v3.110.78 — sub-cat → main-cat live calc. When a coach
            // types a value in a sub-category input, recompute the
            // main category as the rounded average of its non-zero
            // subs and write it back to the main input. Triggers an
            // `input` event so the per-player status pill picks it up
            // through the normal listener chain. Skip if the input
            // isn't a sub (has no `data-tt-rate-sub-parent`).
            function recalcMainFromSubs( subInput ) {
                var parentCatId = subInput.getAttribute( 'data-tt-rate-sub-parent' );
                if ( ! parentCatId ) return;
                var details = subInput.closest( '[data-tt-rate-player]' );
                if ( ! details ) return;
                var mainInput = details.querySelector( '[data-tt-rate-main="' + parentCatId + '"]' );
                if ( ! mainInput ) return;
                var subs = details.querySelectorAll( '[data-tt-rate-sub-parent="' + parentCatId + '"]' );
                var sum = 0, count = 0;
                subs.forEach( function ( s ) {
                    if ( s.hasAttribute( 'data-tt-rating-empty' ) ) return;
                    var v = parseFloat( s.value );
                    if ( v > 0 ) { sum += v; count++; }
                } );
                if ( count > 0 ) {
                    var avg = Math.round( sum / count ); // whole star (5–9)
                    var max = parseFloat( mainInput.getAttribute( 'max' ) );
                    if ( ! isNaN( max ) && avg > max ) avg = max;
                    mainInput.value = String( avg );
                    mainInput.removeAttribute( 'data-tt-rating-empty' );
                    // #1641 — re-render the main category's stars + readout
                    // and refresh the per-player status pill. The star
                    // widget's own listener and the roster delegated
                    // listener both fire on this input event; a programmatic
                    // value set is otherwise silent for the stars.
                    mainInput.dispatchEvent( new Event( 'input', { bubbles: true } ) );
                }
            }

            // Initial paint — covers pre-filled wizard-state values.
            var allDetails = roster.querySelectorAll( '[data-tt-rate-player]' );
            allDetails.forEach( updatePlayer );
            updateOverall();

            // Delegate input + change events on the roster.
            roster.addEventListener( 'input', function ( e ) {
                if ( ! e.target ) return;
                if ( e.target.matches && e.target.matches( '[data-tt-rate-sub-parent]' ) ) {
                    recalcMainFromSubs( e.target );
                }
                var details = e.target.closest( '[data-tt-rate-player]' );
                if ( details ) { updatePlayer( details ); updateOverall(); }
                persistBuffer();
            } );
            roster.addEventListener( 'change', function ( e ) {
                if ( ! e.target ) return;
                if ( e.target.matches && e.target.matches( '[data-tt-rate-sub-parent]' ) ) {
                    recalcMainFromSubs( e.target );
                }
                var details = e.target.closest( '[data-tt-rate-player]' );
                if ( details ) { updatePlayer( details ); updateOverall(); }
                persistBuffer();
            } );

            // #1372 — interruption buffer. Every slider / notes / skip
            // change is mirrored into sessionStorage keyed per
            // activity, so a discarded tab (phone call, app switch)
            // doesn't cost the coach 14 players' worth of input.
            // Client-side only — no server writes before Review, so
            // the autosave race that got global wizard autosave
            // removed (FrontendWizardView v3.x) cannot recur.
            // sessionStorage survives mobile tab discards (the tab
            // restores in place); it does NOT survive an explicitly
            // closed tab — that's the documented boundary.
            var bufferKey = roster.getAttribute( 'data-tt-rate-buffer-key' ) || '';

            function snapshotBuffer() {
                var data = {};
                roster.querySelectorAll( 'input[name^="ratings["]' ).forEach( function ( el ) {
                    if ( el.hasAttribute( 'data-tt-rating-empty' ) ) return;
                    if ( el.value !== '' ) data[ el.name ] = el.value;
                } );
                roster.querySelectorAll( 'textarea[name^="notes["]' ).forEach( function ( el ) {
                    if ( el.value !== '' ) data[ el.name ] = el.value;
                } );
                roster.querySelectorAll( 'textarea[name^="player_feedback["]' ).forEach( function ( el ) {
                    if ( el.value !== '' ) data[ el.name ] = el.value;
                } );
                roster.querySelectorAll( 'input[name^="skip["]' ).forEach( function ( el ) {
                    if ( el.checked ) data[ el.name ] = '1';
                } );
                return data;
            }

            function persistBuffer() {
                if ( ! bufferKey ) return;
                try {
                    var data = snapshotBuffer();
                    if ( Object.keys( data ).length > 0 ) {
                        sessionStorage.setItem( bufferKey, JSON.stringify( data ) );
                    } else {
                        sessionStorage.removeItem( bufferKey );
                    }
                } catch ( err ) { /* storage unavailable — degrade silently */ }
            }

            function clearBuffer() {
                if ( ! bufferKey ) return;
                try { sessionStorage.removeItem( bufferKey ); } catch ( err ) { /* noop */ }
            }

            function rehydrateBuffer() {
                if ( ! bufferKey ) return;
                var raw = null;
                try { raw = sessionStorage.getItem( bufferKey ); } catch ( err ) { return; }
                if ( ! raw ) return;
                var data;
                try { data = JSON.parse( raw ); } catch ( err ) { return; }
                if ( ! data || typeof data !== 'object' ) return;
                var restored = 0;
                Object.keys( data ).forEach( function ( name ) {
                    var el = null;
                    try {
                        var esc = window.CSS && CSS.escape ? CSS.escape( name ) : name.replace( /([\[\]])/g, '\\$1' );
                        el = roster.querySelector( '[name="' + esc + '"]' );
                    } catch ( err ) { el = null; }
                    if ( ! el ) return;
                    if ( el.type === 'checkbox' ) {
                        if ( ! el.checked ) { el.checked = true; restored++; }
                        return;
                    }
                    // The buffer is fresher than the server-side wizard
                    // state (it's written on every input; state only on
                    // step submit) — buffered values win.
                    if ( el.value !== data[ name ] || el.hasAttribute( 'data-tt-rating-empty' ) ) {
                        el.value = data[ name ];
                        el.removeAttribute( 'data-tt-rating-empty' );
                        restored++;
                    }
                } );
                if ( restored > 0 ) {
                    // #1641 — repaint the star widgets from the restored
                    // hidden values (set programmatically, no input event).
                    if ( window.TT && window.TT.RatingInput && window.TT.RatingInput.refresh ) {
                        window.TT.RatingInput.refresh( roster );
                    }
                    // Re-derive mains from subs, repaint pills + progress.
                    roster.querySelectorAll( '[data-tt-rate-sub-parent]' ).forEach( function ( s ) {
                        if ( ! s.hasAttribute( 'data-tt-rating-empty' ) ) recalcMainFromSubs( s );
                    } );
                    allDetails.forEach( updatePlayer );
                    updateOverall();
                    var notice = document.querySelector( '[data-tt-rate-restored]' );
                    if ( notice ) notice.removeAttribute( 'hidden' );
                }
            }

            rehydrateBuffer();

            // Clear on Next (values move into the wizard state) and on
            // explicit Cancel; keep on Back so a return restores. The
            // submitter button carries tt_wizard_action.
            var wizardForm = roster.closest( 'form' );
            if ( wizardForm ) {
                wizardForm.addEventListener( 'submit', function ( e ) {
                    var action = '';
                    if ( e.submitter && e.submitter.name === 'tt_wizard_action' ) {
                        action = e.submitter.value || '';
                    }
                    if ( action !== 'back' ) clearBuffer();
                } );
            }

            // v3.110.125 — per-category Basic/Detailed pill toggle.
            // Click delegated on the roster so the handler picks up
            // every category across every player without per-element
            // wiring. State flips on the wrapper's `data-state`; the
            // sibling `.tt-rate-subs` panel toggles `hidden`. Form
            // values inside the subs persist across mode flips —
            // hiding doesn't unmount the inputs.
            roster.addEventListener( 'click', function ( e ) {
                var btn = e.target && e.target.closest ? e.target.closest( '.tt-rate-detail-toggle button' ) : null;
                if ( ! btn ) return;
                var wrap = btn.closest( '.tt-rate-detail-toggle' );
                if ( ! wrap ) return;
                var mode = btn.getAttribute( 'data-mode' );
                wrap.setAttribute( 'data-state', mode );
                var btns = wrap.querySelectorAll( 'button' );
                btns.forEach( function ( b ) {
                    b.setAttribute( 'aria-selected', b === btn ? 'true' : 'false' );
                } );
                // Next sibling is the subs panel.
                var panel = wrap.nextElementSibling;
                if ( panel && panel.matches( '[data-tt-rate-subs]' ) ) {
                    if ( mode === 'detailed' ) {
                        panel.removeAttribute( 'hidden' );
                    } else {
                        panel.setAttribute( 'hidden', '' );
                    }
                }
            } );
        })();
        </script>
        <?php
    }

    public function validate( array $post, array $state ) {
        $ratings = isset( $post['ratings'] ) && is_array( $post['ratings'] ) ? $post['ratings'] : [];
        $clean = [];
        // #1067 — slider input accepts 0.5 increments (schema is
        // DECIMAL(4,1)). Cast to float, snap to 0.5 to defend against
        // upstream input drift, and skip non-positive entries (the
        // component strips `name` from empty sliders so they don't
        // post — defensive belt + braces here).
        foreach ( $ratings as $pid => $cats ) {
            if ( ! is_array( $cats ) ) continue;
            foreach ( $cats as $cid => $v ) {
                $f = (float) $v;
                if ( $f <= 0 ) continue;
                $f = round( $f * 2 ) / 2;
                $clean[ (int) $pid ][ (int) $cid ] = $f;
            }
        }
        $notes = isset( $post['notes'] ) && is_array( $post['notes'] )
            ? array_map( 'sanitize_textarea_field', wp_unslash( $post['notes'] ) )
            : [];
        $player_feedback = isset( $post['player_feedback'] ) && is_array( $post['player_feedback'] )
            ? array_map( 'sanitize_textarea_field', wp_unslash( $post['player_feedback'] ) )
            : [];
        $skip  = isset( $post['skip'] ) && is_array( $post['skip'] )
            ? array_map( 'absint', $post['skip'] )
            : [];

        return [ 'ratings' => $clean, 'notes' => $notes, 'player_feedback' => $player_feedback, 'skip' => $skip ];
    }

    public function nextStep( array $state ): ?string { return 'behaviour'; }
    public function submit( array $state ) { return null; }

    /**
     * Players who should appear in the rate roster for the activity.
     *
     * v4.13.3 (#1032) — for match-type activities (`activity_type_key`
     * IN ('match','game')), the source of truth is the actual on-pitch
     * set (lineup + subs-on log), NOT `tt_attendance`. The attendance
     * table is a derived snapshot the match-finish endpoint writes for
     * every available player including bench (minutes_played = 0); it
     * is the wrong source for rating because:
     *   - bench / unused subs surface (zero minutes)
     *   - players from other teams with stale attendance rows surface
     *     (no team-scoping)
     *   - the finish endpoint INSERTs but doesn't reconcile orphans
     *
     * For training and any non-match activity the attendance query
     * stays: there is no lineup or sub log to derive from.
     *
     * The earlier fallback to the team's full roster (when no
     * attendance was recorded) was removed — a coach who skipped the
     * attendance step previously saw the entire roster as rateable,
     * misrepresenting who actually participated.
     *
     * @return list<object>
     */
    /**
     * #1643 — whether the picked activity is a training activity, used
     * to drive the training-only mental-first / pre-expanded default.
     * Club-scoped like every activity lookup in this class.
     */
    private static function isTrainingActivity( int $activity_id ): bool {
        if ( $activity_id <= 0 ) return false;
        global $wpdb;
        $p = $wpdb->prefix;
        $type_key = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT activity_type_key FROM {$p}tt_activities WHERE id = %d AND club_id = %d",
            $activity_id, CurrentClub::id()
        ) );
        return \TT\Infrastructure\Evaluations\TrainingEvalDefaults::isTrainingActivityType( $type_key );
    }

    public static function ratablePlayersForActivity( int $activity_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $activity = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, team_id, activity_type_key
               FROM {$p}tt_activities
              WHERE id = %d AND club_id = %d",
            $activity_id, CurrentClub::id()
        ) );
        if ( ! $activity ) return [];
        $team_id = (int) ( $activity->team_id ?? 0 );
        if ( $team_id <= 0 ) return [];

        // #1032 — branch by activity type. The legacy 'game' key and the
        // post-#988 'match' key both denote match activities (see
        // PostGameEvaluationTemplate's mixed-key filter).
        $type_key = strtolower( (string) ( $activity->activity_type_key ?? '' ) );
        if ( $type_key === 'match' || $type_key === 'game' ) {
            return self::ratablePlayersForMatch( $activity_id, $team_id );
        }

        return self::ratablePlayersFromAttendance( $activity_id );
    }

    /**
     * Match-activity source: starting XI from `tt_match_prep_lineup`
     * (both halves) UNION players who came on via
     * `tt_match_execution_substitutions` (subs-on, excluding reversed
     * events). The set is exactly the players who got onto the pitch
     * in either half — bench-only players are excluded by construction.
     *
     * `is_guest` is derived by comparing the player's home `team_id`
     * against the activity's `team_id`; players whose home team differs
     * (or who carry `team_id = 0`) are flagged so the rate-step row can
     * show a `Guest` pill — answers the "why is this other-team player
     * here?" surprise. The lineup / subs tables themselves don't carry
     * a guest column (#1032).
     *
     * Already-rated guard mirrors the attendance variant.
     *
     * @return list<object>
     */
    private static function ratablePlayersForMatch( int $activity_id, int $team_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $club_id = CurrentClub::id();

        $prep = ( new MatchPrepRepository() )->findByActivity( $activity_id );
        if ( ! $prep ) return [];
        $prep_id = (int) $prep->id;

        $exec = ( new MatchExecutionRepository() )->findByActivity( $activity_id );
        $exec_id = $exec ? (int) $exec->id : 0;

        // Build the on-pitch player_id set in PHP (one round-trip each)
        // rather than a multi-CTE UNION which gets fiddly with wpdb's
        // placeholder counting. Two small index-driven lookups.
        $on_pitch = [];
        $lineup_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT player_id FROM {$p}tt_match_prep_lineup
              WHERE match_prep_id = %d AND club_id = %d",
            $prep_id, $club_id
        ) );
        foreach ( (array) $lineup_rows as $r ) {
            $pid = (int) $r->player_id;
            if ( $pid > 0 ) $on_pitch[ $pid ] = true;
        }
        if ( $exec_id > 0 ) {
            $sub_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT DISTINCT player_on_id FROM {$p}tt_match_execution_substitutions
                  WHERE execution_id = %d AND club_id = %d AND reversed_at IS NULL",
                $exec_id, $club_id
            ) );
            foreach ( (array) $sub_rows as $r ) {
                $pid = (int) $r->player_on_id;
                if ( $pid > 0 ) $on_pitch[ $pid ] = true;
            }
        }
        if ( empty( $on_pitch ) ) return [];

        $ids = array_keys( $on_pitch );
        $in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // Placeholder order: team_id (CASE in SELECT), ids... (IN clause),
        // club_id (pl scope), activity_id + club_id (already-rated guard).
        $sql = "SELECT pl.id, pl.first_name, pl.last_name,
                       CASE WHEN pl.team_id = %d THEN 0 ELSE 1 END AS is_guest
                  FROM {$p}tt_players pl
                 WHERE pl.id IN ($in)
                   AND pl.club_id = %d
                   AND pl.archived_at IS NULL
                   AND NOT EXISTS (
                       SELECT 1 FROM {$p}tt_evaluations e
                        WHERE e.activity_id = %d
                          AND e.player_id   = pl.id
                          AND e.club_id     = %d
                   )
                 ORDER BY pl.last_name, pl.first_name";
        $params = array_merge( [ $team_id ], $ids, [ $club_id, $activity_id, $club_id ] );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
    }

    /**
     * Non-match attendance-based source — pre-#1032 logic preserved
     * unchanged for training / other activity types where lineup data
     * doesn't exist.
     *
     * @return list<object>
     */
    private static function ratablePlayersFromAttendance( int $activity_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        // v3.110.78 — `LOWER(att.status)` so the query matches
        // regardless of whether existing rows wrote the status
        // capitalised (legacy form path before v3.110.4 normalisation)
        // or via a localised lookup name. Pre-v3.110.78 this returned
        // zero rows on installs whose `tt_attendance.status` values
        // were 'Present' / 'Late' instead of lowercase — the rate
        // step then showed "no players to rate" even though the
        // attendance step had just persisted them.
        //
        // v3.110.97 — `NOT EXISTS` on `tt_evaluations` so already-rated
        // players are excluded from the rate roster. Pairs with the
        // new "Continue rating" CTA on the activity detail page:
        // a coach re-entering the wizard for an already-rated activity
        // sees ONLY the players they haven't rated yet, so submit
        // creates fresh eval rows for the un-rated set instead of
        // duplicating evals for everyone. No-op on first runs (no
        // eval rows yet).
        //
        // v4.3.20 (#943) — linked guests (is_guest=1, guest_player_id
        // points at a real tt_players row) also surface in the rate
        // roster. They resolve through the same player record as a
        // roster player; the join switches from `att.player_id` to
        // `COALESCE(att.guest_player_id, att.player_id)` so a single
        // SELECT covers both shapes. Anonymous guests
        // (guest_player_id IS NULL) stay excluded — no tt_players row
        // to evaluate against; their notes input on the activity form
        // is the existing capture mechanism. DISTINCT guards against
        // a player appearing as both a roster row and a linked-guest
        // row for the same activity (rare but possible).
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT pl.id, pl.first_name, pl.last_name
               FROM {$p}tt_attendance att
               INNER JOIN {$p}tt_players pl
                   ON pl.id = COALESCE( att.guest_player_id, att.player_id )
                   AND pl.club_id = att.club_id
              WHERE att.activity_id = %d AND att.club_id = %d
                AND LOWER(att.status) IN ( 'present', 'late' )
                AND ( att.is_guest = 0 OR att.guest_player_id IS NOT NULL )
                AND pl.archived_at IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM {$p}tt_evaluations e
                     WHERE e.activity_id = att.activity_id
                       AND e.player_id   = pl.id
                       AND e.club_id     = att.club_id
                  )
              ORDER BY pl.last_name, pl.first_name",
            $activity_id, CurrentClub::id()
        ) );
    }
}
