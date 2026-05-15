<?php
namespace TT\Modules\Wizards\Evaluation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
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
        global $wpdb;
        $p = $wpdb->prefix;

        $aid = (int) ( $state['activity_id'] ?? 0 );
        $players = self::ratablePlayersForActivity( $aid );

        $quick_cats = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, label FROM {$p}tt_eval_categories
              WHERE parent_id IS NULL AND is_active = 1
                AND meta IS NOT NULL AND meta LIKE %s
              ORDER BY display_order, label",
            '%"quick_rate":true%'
        ) );

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

        $rating = $wpdb->get_var( "SELECT rating_max FROM {$p}tt_eval_categories LIMIT 0" );
        $max = (int) ( get_option( 'tt_rating_scale_max', 5 ) ?: 5 );

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

        <div class="tt-rate-roster" data-tt-rate-roster data-main-cat-count="<?php echo (int) $main_cat_count; ?>"
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
                    <span class="tt-rate-player-status tt-rate-player-status--empty" data-tt-rate-status>
                        <?php esc_html_e( 'Not rated', 'talenttrack' ); ?>
                    </span>
                </summary>

                <div class="tt-rate-grid">
                    <?php foreach ( (array) $quick_cats as $cat ) :
                        $cid    = (int) $cat->id;
                        $val    = (int) ( $state['ratings'][ $pid ][ $cid ] ?? 0 );
                        $iid    = 'tt-rate-' . $pid . '-' . $cid;
                        $label  = \TT\Infrastructure\Evaluations\EvalCategoriesRepository::displayLabel( (string) $cat->label, $cid );
                        $subs   = $sub_cats_by_parent[ $cid ] ?? [];
                    ?>
                        <div class="tt-rate-row">
                            <label class="tt-rate-label" for="<?php echo esc_attr( $iid ); ?>"><?php echo esc_html( $label ); ?></label>
                            <div class="tt-rate-control">
                                <input type="number" min="0" max="<?php echo (int) $max; ?>"
                                       step="1"
                                       inputmode="numeric"
                                       id="<?php echo esc_attr( $iid ); ?>"
                                       class="tt-rate-input"
                                       name="ratings[<?php echo $pid; ?>][<?php echo $cid; ?>]"
                                       data-tt-rate-main="<?php echo (int) $cid; ?>"
                                       value="<?php echo $val > 0 ? (int) $val : ''; ?>" />
                                <span class="tt-rate-max">
                                    / <?php echo (int) $max; ?>
                                </span>
                            </div>
                        </div>
                        <?php if ( ! empty( $subs ) ) : ?>
                            <details class="tt-rate-subs">
                                <summary class="tt-rate-subs-toggle">
                                    <?php
                                    /* translators: %s: main category label (Technical / Tactical / Physical / Mental) */
                                    echo esc_html( sprintf( __( 'Detailed %s', 'talenttrack' ), $label ) );
                                    ?>
                                </summary>
                                <?php foreach ( $subs as $sub ) :
                                    $scid = (int) $sub->id;
                                    $sval = (int) ( $state['ratings'][ $pid ][ $scid ] ?? 0 );
                                    $siid = 'tt-rate-' . $pid . '-' . $scid;
                                    $slabel = \TT\Infrastructure\Evaluations\EvalCategoriesRepository::displayLabel( (string) $sub->label, $scid );
                                ?>
                                    <div class="tt-rate-row tt-rate-row--sub">
                                        <label class="tt-rate-label" for="<?php echo esc_attr( $siid ); ?>">↳ <?php echo esc_html( $slabel ); ?></label>
                                        <div class="tt-rate-control">
                                            <input type="number" min="0" max="<?php echo (int) $max; ?>"
                                                   step="1"
                                                   inputmode="numeric"
                                                   id="<?php echo esc_attr( $siid ); ?>"
                                                   class="tt-rate-input"
                                                   name="ratings[<?php echo $pid; ?>][<?php echo $scid; ?>]"
                                                   data-tt-rate-sub-parent="<?php echo (int) $cid; ?>"
                                                   value="<?php echo $sval > 0 ? (int) $sval : ''; ?>" />
                                            <span class="tt-rate-max">
                                                / <?php echo (int) $max; ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </details>
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
                main.forEach( function ( i ) { if ( parseInt( i.value, 10 ) > 0 ) filled++; } );

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
                    var v = parseInt( s.value, 10 );
                    if ( v > 0 ) { sum += v; count++; }
                } );
                if ( count > 0 ) {
                    var avg = Math.round( sum / count );
                    var max = parseInt( mainInput.getAttribute( 'max' ), 10 );
                    if ( ! isNaN( max ) && avg > max ) avg = max;
                    mainInput.value = String( avg );
                    // Don't redispatch input here — the outer event
                    // listener already calls updatePlayer + updateOverall
                    // for the original sub-cat change.
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
            } );
            roster.addEventListener( 'change', function ( e ) {
                if ( ! e.target ) return;
                if ( e.target.matches && e.target.matches( '[data-tt-rate-sub-parent]' ) ) {
                    recalcMainFromSubs( e.target );
                }
                var details = e.target.closest( '[data-tt-rate-player]' );
                if ( details ) { updatePlayer( details ); updateOverall(); }
            } );
        })();
        </script>
        <?php
    }

    public function validate( array $post, array $state ) {
        $ratings = isset( $post['ratings'] ) && is_array( $post['ratings'] ) ? $post['ratings'] : [];
        $clean = [];
        foreach ( $ratings as $pid => $cats ) {
            if ( ! is_array( $cats ) ) continue;
            foreach ( $cats as $cid => $v ) {
                $v = (int) $v;
                if ( $v <= 0 ) continue;
                $clean[ (int) $pid ][ (int) $cid ] = $v;
            }
        }
        $notes = isset( $post['notes'] ) && is_array( $post['notes'] )
            ? array_map( 'sanitize_textarea_field', wp_unslash( $post['notes'] ) )
            : [];
        $skip  = isset( $post['skip'] ) && is_array( $post['skip'] )
            ? array_map( 'absint', $post['skip'] )
            : [];

        return [ 'ratings' => $clean, 'notes' => $notes, 'skip' => $skip ];
    }

    public function nextStep( array $state ): ?string { return 'review'; }
    public function submit( array $state ) { return null; }

    /**
     * Players present/late on the activity. The previous fallback to
     * the team's full roster (when no attendance was recorded) is
     * gone — the user surfaced that as confusing: a coach who skipped
     * the attendance step would see the entire roster as rateable,
     * misrepresenting who actually participated. Without the fallback
     * the rate step refuses to start until someone has been marked
     * present/late on the activity.
     *
     * @return list<object>
     */
    public static function ratablePlayersForActivity( int $activity_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $team_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT team_id FROM {$p}tt_activities WHERE id = %d AND club_id = %d",
            $activity_id, CurrentClub::id()
        ) );
        if ( $team_id <= 0 ) return [];

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
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT pl.id, pl.first_name, pl.last_name
               FROM {$p}tt_attendance att
               INNER JOIN {$p}tt_players pl ON pl.id = att.player_id AND pl.club_id = att.club_id
              WHERE att.activity_id = %d AND att.club_id = %d
                AND LOWER(att.status) IN ( 'present', 'late' )
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
