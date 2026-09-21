<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\PlayerSearchPickerComponent;

/**
 * CoachForms — shared form-rendering helpers for coaching actions.
 *
 * v3.0.0 slice 4. Extracted from the legacy CoachDashboardView so
 * the new focused FrontendEvaluationsView could share the eval form
 * markup. The session form helper was retired in Sprint 2 session
 * 2.3 (FrontendActivitiesManageView renders its own form); the goals
 * form helper was retired in Sprint 2 session 2.4
 * (FrontendGoalsManageView renders its own form). Only renderEvalForm
 * has a live caller now.
 *
 * Form submissions go through the TalentTrack REST API; each form
 * declares its endpoint via `data-rest-path` (relative to
 * `/wp-json/talenttrack/v1/`). See `assets/js/public.js` for the
 * submit handler. The legacy `tt_fe_*` admin-ajax actions were retired
 * in #0019 Sprint 1 session 2.
 */
class CoachForms {

    /**
     * @param object[]    $teams
     * @param int         $preset_player_id  v3.110.3 — when set, the form
     *                                       pre-fills the team + player
     *                                       pickers and hides them; the
     *                                       operator only sees the rating
     *                                       inputs. Used by the "Add
     *                                       evaluation" CTA on the player
     *                                       profile's empty Evaluations
     *                                       tab.
     * @param object|null $existing_eval     v3.110.55 — when set, render
     *                                       in EDIT mode: form posts PUT
     *                                       to /evaluations/{id}, every
     *                                       field is pre-filled from the
     *                                       row, every existing rating is
     *                                       pre-populated.
     */
    public static function renderEvalForm( array $teams, bool $is_admin, int $preset_player_id = 0, ?object $existing_eval = null ): void {
        $categories = QueryHelpers::get_categories();
        $types      = QueryHelpers::get_eval_types();
        $rmin  = QueryHelpers::get_config( 'rating_min', '5' );
        $rmax  = QueryHelpers::get_config( 'rating_max', '10' );
        $rstep = QueryHelpers::get_config( 'rating_step', '0.5' );

        $type_meta = [];
        foreach ( $types as $t ) {
            $m = QueryHelpers::lookup_meta( $t );
            $type_meta[ (int) $t->id ] = ! empty( $m['requires_match_details'] ) ? 1 : 0;
        }

        // #1643 — training-eval default: surface the mental category
        // first + pre-expanded when the Type dropdown is set to Training.
        // The selected type is only known client-side, so resolve the
        // training type id + mental category id here and hand them to the
        // JS enhancement. Policy (which type, which category) stays in the
        // TrainingEvalDefaults domain helper.
        $training_type_id = 0;
        foreach ( $types as $t ) {
            if ( \TT\Infrastructure\Evaluations\TrainingEvalDefaults::isTrainingTypeName( (string) $t->name ) ) {
                $training_type_id = (int) $t->id;
                break;
            }
        }
        $mental_cat = ( new \TT\Infrastructure\Evaluations\EvalCategoriesRepository() )
            ->getByKey( \TT\Infrastructure\Evaluations\TrainingEvalDefaults::PRIORITY_CATEGORY_KEY );
        $mental_cat_id = $mental_cat ? (int) $mental_cat->id : 0;

        wp_enqueue_script(
            'tt-training-eval-defaults',
            TT_PLUGIN_URL . 'assets/js/components/training-eval-defaults.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script(
            'tt-training-eval-defaults',
            'TT_TRAINING_EVAL_DEFAULTS',
            [
                'trainingTypeId'  => $training_type_id,
                'mentalCategoryId' => $mental_cat_id,
            ]
        );

        // F6 — low-rating comment policy. Threshold and mode come
        // from tt_config; the JS at the bottom of this form watches
        // every rating input and surfaces a warning (or a hard block
        // when configured) if any rating is ≤ threshold and the
        // notes textarea is empty.
        $low_threshold = (float) QueryHelpers::get_config( 'eval_low_rating_threshold', '3' );
        $low_mode      = (string) QueryHelpers::get_config( 'eval_low_rating_require_comment', 'soft' );

        $players = $is_admin ? QueryHelpers::get_players() : [];
        if ( ! $is_admin ) {
            foreach ( $teams as $t ) {
                $players = array_merge( $players, QueryHelpers::get_players( (int) $t->id ) );
            }
        }

        // v3.110.3 — preset path: the "Add evaluation" CTA on a player
        // profile passes ?player_id=N. Look up the player's team_id and
        // pre-fill both pickers; render hidden inputs in place of the
        // dropdowns.
        $preset_player = $preset_player_id > 0 ? QueryHelpers::get_player( $preset_player_id ) : null;
        $preset_team_id = $preset_player ? (int) ( $preset_player->team_id ?? 0 ) : 0;
        $hide_pickers = $preset_player !== null && $preset_team_id > 0;

        // v3.110.55 — edit mode: the form switches to PUT against the
        // existing eval, every field pre-fills from `$existing_eval`,
        // and the player picker is replaced with a hidden input (a
        // player swap mid-edit would invalidate ratings against the
        // wrong player). Existing ratings are fetched once and merged
        // into the inputs as `value="..."`.
        $is_edit          = $existing_eval !== null;
        $rest_path        = $is_edit ? 'evaluations/' . (int) $existing_eval->id : 'evaluations';
        $rest_method      = $is_edit ? 'PUT' : 'POST';
        $submit_label     = $is_edit ? __( 'Save changes', 'talenttrack' ) : __( 'Save', 'talenttrack' );
        $existing_player  = $is_edit ? (int) $existing_eval->player_id : 0;
        $existing_ratings = [];
        if ( $is_edit ) {
            $rating_rows = ( new \TT\Infrastructure\Evaluations\EvalRatingsRepository() )->getForEvaluation( (int) $existing_eval->id );
            foreach ( $rating_rows as $rr ) {
                $existing_ratings[ (int) $rr->category_id ] = (float) $rr->rating;
            }
        }
        if ( $is_edit ) {
            $hide_pickers = true;
        }
        ?>
        <?php
        // #3008 (epic #2881) — an evaluation being edited autosaves; one
        // being written for the first time does not.
        //
        // The split is not squeamishness. Autosave writes to a record, and
        // in create mode there is no record yet — pointing it at `POST
        // /evaluations` would spawn a row on the first keystroke and leave
        // an empty evaluation on a player's file behind every coach who
        // opened the form and thought better of it. Creation stays a
        // deliberate act (the wizard owns it where wizards are on).
        //
        // Editing is the composing case the pilot asked about: a coach
        // rewriting what they said about a child, where losing the
        // paragraph is what stops them writing the next one.
        $autosaves = $is_edit;
        if ( $autosaves ) {
            \TT\Shared\Frontend\Components\FormAutosave::enqueue();
        }

        // #3969 — one card per category, in its own mobile-first sheet.
        // The page header is the form's title; the form prints none.
        wp_enqueue_style( 'tt-frontend-eval-form', TT_PLUGIN_URL . 'assets/css/frontend-eval-form.css', [ 'tt-public' ], TT_VERSION );
        wp_enqueue_script( 'tt-eval-form', TT_PLUGIN_URL . 'assets/js/components/eval-form.js', [], TT_VERSION, true );
        ?>
        <form id="tt-eval-form"
              class="tt-evf <?php echo $autosaves ? 'tt-autosave-form' : 'tt-ajax-form'; ?>"
              data-tt-evf
              data-tt-evf-type-meta="<?php echo esc_attr( (string) wp_json_encode( $type_meta ) ); ?>"
              data-tt-evf-low-threshold="<?php echo esc_attr( (string) $low_threshold ); ?>"
              data-tt-evf-low-mode="<?php echo esc_attr( $low_mode ); ?>"
              data-tt-evf-min="<?php echo esc_attr( (string) $rmin ); ?>"
              data-tt-evf-max="<?php echo esc_attr( (string) $rmax ); ?>"
              data-tt-evf-step="<?php echo esc_attr( (string) $rstep ); ?>"
              <?php if ( $autosaves ) {
                  echo \TT\Shared\Frontend\Components\FormAutosave::formAttrs( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — the component escapes each attribute
                      $rest_path,
                      $rest_method,
                      'evaluation:' . $rest_path
                  );
              } else {
                  printf(
                      'data-rest-path="%s" data-rest-method="%s" data-redirect-after-save="1"',
                      esc_attr( $rest_path ),
                      esc_attr( $rest_method )
                  );
              } ?>>
            <?php if ( $hide_pickers ) :
                $hidden_player_id = $is_edit ? $existing_player : $preset_player_id;
                $hidden_player    = $is_edit ? QueryHelpers::get_player( $existing_player ) : $preset_player;
                ?>
                <input type="hidden" name="player_id" value="<?php echo esc_attr( (string) $hidden_player_id ); ?>" />
                <?php self::renderSubject( $hidden_player, (int) $hidden_player_id ); ?>
            <?php else : ?>
                <?php self::renderSubject( null, 0 ); ?>
                <?php
                // v3.110.4 — single picker with embedded team filter.
                // Replaces the previous team-first two-picker flow:
                // PlayerSearchPickerComponent renders a built-in "All
                // teams" team filter ABOVE the search input; selecting
                // a team filters the player list, leaving "All teams"
                // shows every player in the user's context. The
                // separate `eval_team_id` picker is gone — it was only
                // used as a player-list filter (REST never read it),
                // and the embedded version handles that without the
                // dead-end "select team first" placeholder.
                ?>
                <div class="tt-field" data-tt-eval-player-wrap>
                    <?php echo PlayerSearchPickerComponent::render( [
                        'name'             => 'player_id',
                        'label'            => __( 'Player', 'talenttrack' ),
                        'required'         => true,
                        'players'          => $players,
                        'placeholder'      => __( 'Type a name to search…', 'talenttrack' ),
                        'show_team_filter' => true,
                    ] ); ?>
                </div>
            <?php endif; ?>
            <?php
            $cur_type_id     = $is_edit ? (int) ( $existing_eval->eval_type_id ?? 0 ) : 0;
            // v3.110.105 — back-fill the Type dropdown when the
            // existing row has an `activity_id` but no
            // `eval_type_id` (legacy mark-attendance wizard rows
            // written before the inserter started persisting type).
            // Same helper the inserter uses on the write side, so
            // the dropdown defaults match what would be saved if the
            // coach re-submitted without changing anything.
            if ( $is_edit && $cur_type_id <= 0 ) {
                $existing_aid = (int) ( $existing_eval->activity_id ?? 0 );
                if ( $existing_aid > 0
                     && class_exists( '\\TT\\Modules\\Wizards\\Evaluation\\EvaluationInserter' )
                ) {
                    $cur_type_id = \TT\Modules\Wizards\Evaluation\EvaluationInserter::evalTypeIdForActivity( $existing_aid );
                }
            }
            $cur_eval_date   = $is_edit ? (string) ( $existing_eval->eval_date ?? '' ) : current_time( 'Y-m-d' );
            $cur_opponent    = $is_edit ? (string) ( $existing_eval->opponent ?? '' ) : '';
            $cur_competition = $is_edit ? (string) ( $existing_eval->competition ?? '' ) : '';
            $cur_result      = $is_edit ? (string) ( $existing_eval->game_result ?? '' ) : '';
            $cur_home_away   = $is_edit ? (string) ( $existing_eval->home_away ?? '' ) : '';
            $cur_notes       = $is_edit ? (string) ( $existing_eval->notes ?? '' ) : '';
            // #1386 — player-facing feedback, distinct from staff-only notes.
            $cur_feedback    = $is_edit ? (string) ( $existing_eval->player_feedback ?? '' ) : '';
            $match_open      = $is_edit && $cur_type_id > 0 && ! empty( $type_meta[ $cur_type_id ] );
            ?>
            <section class="tt-evf__section" aria-labelledby="tt-evf-h-details">
            <div class="tt-evf__section-head"><h2 class="tt-evf__section-title" id="tt-evf-h-details"><?php echo esc_html_x( 'Details', 'evaluation form section', 'talenttrack' ); ?></h2></div>
            <div class="tt-evf__fields">
                <div class="tt-evf__field"><label for="tt_fe_eval_type"><?php esc_html_e( 'Type', 'talenttrack' ); ?> *</label><select name="eval_type_id" id="tt_fe_eval_type" required>
                    <option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                    <?php foreach ( $types as $t ) : ?>
                        <option value="<?php echo (int) $t->id; ?>" data-match="<?php echo (int) $type_meta[ (int) $t->id ]; ?>" <?php selected( $cur_type_id, (int) $t->id ); ?>><?php echo esc_html( (string) $t->name ); ?></option>
                    <?php endforeach; ?>
                </select></div>
                <div class="tt-evf__field"><label for="tt-evf-date"><?php esc_html_e( 'Date', 'talenttrack' ); ?> *</label><input type="date" id="tt-evf-date" name="eval_date" value="<?php echo esc_attr( $cur_eval_date ); ?>" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required /></div>
            </div>
            <div class="tt-evf__fields" id="tt-fe-match-fields" data-tt-evf-match <?php echo $match_open ? '' : 'hidden'; ?>>
                <div class="tt-evf__field"><label for="tt-evf-opponent"><?php esc_html_e( 'Opponent', 'talenttrack' ); ?></label><input type="text" id="tt-evf-opponent" name="opponent" autocomplete="off" value="<?php echo esc_attr( $cur_opponent ); ?>" /></div>
                <div class="tt-evf__field">
                    <label for="tt-evf-competition"><?php esc_html_e( 'Competition', 'talenttrack' ); ?></label>
                    <select name="competition" id="tt-evf-competition">
                        <option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                        <?php foreach ( \TT\Infrastructure\Query\QueryHelpers::get_lookups( 'game_subtype' ) as $tt_ct ) : ?>
                            <option value="<?php echo esc_attr( (string) $tt_ct->name ); ?>" <?php selected( $cur_competition, (string) $tt_ct->name ); ?>><?php echo esc_html( __( (string) $tt_ct->name, 'talenttrack' ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tt-evf__field"><label for="tt-evf-result"><?php esc_html_e( 'Result', 'talenttrack' ); ?></label><input type="text" id="tt-evf-result" name="game_result" placeholder="2-1" autocomplete="off" value="<?php echo esc_attr( $cur_result ); ?>" /></div>
                <div class="tt-evf__field"><label for="tt-evf-home-away"><?php esc_html_e( 'Home/Away', 'talenttrack' ); ?></label><select name="home_away" id="tt-evf-home-away"><option value="">—</option><option value="home" <?php selected( $cur_home_away, 'home' ); ?>><?php esc_html_e( 'Home', 'talenttrack' ); ?></option><option value="away" <?php selected( $cur_home_away, 'away' ); ?>><?php esc_html_e( 'Away', 'talenttrack' ); ?></option></select></div>
                <?php
                // #3817 — "Minutes played" used to be a field here. It has
                // stored nothing since #2159 moved match minutes to
                // `tt_attendance`: the evaluation write path stopped reading
                // the key and the input went on collecting a number that was
                // dropped on save. Recorded minutes are entered on the
                // activity's attendance screen, which is the one place the
                // minutes reports read.
                ?>
            </div>
            </section>
            <section class="tt-evf__section" aria-labelledby="tt-evf-h-ratings">
            <div class="tt-evf__section-head">
                <h2 class="tt-evf__section-title" id="tt-evf-h-ratings"><?php esc_html_e( 'Ratings', 'talenttrack' ); ?></h2>
                <span class="tt-evf__scale"><?php
                    printf(
                        /* translators: 1: lowest rating on the scale, 2: highest rating on the scale */
                        esc_html__( 'Scale %1$s–%2$s', 'talenttrack' ),
                        esc_html( (string) $rmin ),
                        esc_html( (string) $rmax )
                    );
                ?></span>
            </div>
            <div class="tt-evf__cats">
            <?php
            // v3.110.66 — main-category rating inputs are NOT marked
            // `required` in edit mode. The evaluation model is
            // either-or-or-neither (per `EvalRatingsRepository` docblock:
            // "for any given (evaluation, main_category), the coach
            // either entered a direct main rating, OR rated
            // subcategories, OR did neither"). An eval that was
            // originally saved with subcategory ratings only (the
            // common detailed-eval pattern) had no direct main values,
            // so opening it for edit on this form forced the coach to
            // backfill ratings they hadn't entered originally — turning
            // a notes-only edit into a "fill in everything" chore.
            // Create mode keeps `required` because there's no existing
            // sub data to fall back on; clearing every category at
            // create time would produce an evaluation with zero
            // ratings, which is almost never the intent.
            $rating_required = $is_edit ? '' : 'required';
            // v3.110.105 — render sub-category inputs nested under
            // each main category. Pre-fill from `$existing_ratings`
            // (keyed by category_id), which already includes sub
            // rows when present. Subs sit in the category card's body;
            // the input itself is
            // identical to the main row so the REST layer doesn't
            // need to distinguish them on save. Pulled from the
            // EvalCategoriesRepository directly so we don't depend
            // on `get_categories()`'s legacy-shape filter (which
            // only returns main rows).
            $cat_repo = class_exists( '\\TT\\Infrastructure\\Evaluations\\EvalCategoriesRepository' )
                ? new \TT\Infrastructure\Evaluations\EvalCategoriesRepository()
                : null;
            // v3.110.125 — Basic / Detailed segmented toggle per main
            // category. Pre-fix all sub-categories were always
            // visible, doubling the form length on installs with the
            // full 4×3 sub vocabulary. New default is Basic (subs
            // hidden); the toggle flips to Detailed to reveal them.
            // Edit mode auto-defaults to Detailed when any existing
            // sub-cat already has a value, so re-opening a previously-
            // sub-rated eval doesn't appear to lose data.
            $toggle_basic_label = __( 'Basic', 'talenttrack' );
            $toggle_detail_label = __( 'Detailed', 'talenttrack' );
            foreach ( $categories as $cat ) :
                $cid = (int) $cat->id;
                $cur_rating = isset( $existing_ratings[ $cid ] ) ? (string) $existing_ratings[ $cid ] : '';
                $cat_label  = EvalCategoriesRepository::displayLabel( (string) $cat->name );
                $sub_cats   = $cat_repo !== null ? $cat_repo->getChildren( $cid ) : [];

                // Auto-default: Detailed when any sub for this main
                // has a stored value (edit case); Basic otherwise.
                $has_sub_values = false;
                foreach ( (array) $sub_cats as $sub_check ) {
                    $scid_check = (int) $sub_check->id;
                    if ( $scid_check > 0 && ! empty( $existing_ratings[ $scid_check ] ) ) {
                        $has_sub_values = true;
                        break;
                    }
                }
                $initial_state = $has_sub_values ? 'detailed' : 'basic';
                $has_subs      = $cat_repo !== null && ! empty( $sub_cats );
                $main_input_id = 'tt-evf-rating-' . $cid;
                // Card per category (#3969). `data-tt-eval-cat` is the hook
                // `training-eval-defaults.js` moves and expands.
                ?>
                <div class="tt-evf-cat" data-tt-eval-cat="<?php echo (int) $cid; ?>">
                <div class="tt-evf-cat__head">
                    <h3 class="tt-evf-cat__name">
                        <label for="<?php echo esc_attr( $main_input_id ); ?>"><?php echo esc_html( $cat_label ); ?><?php echo $is_edit ? '' : ' *'; ?></label>
                        <?php if ( $has_subs ) : ?>
                            <span class="tt-evf-cat__sub-count"><?php
                                echo esc_html( sprintf(
                                    /* translators: %d: number of subcategories in a rating category */
                                    _n( '%d subcategory', '%d subcategories', count( (array) $sub_cats ), 'talenttrack' ),
                                    count( (array) $sub_cats )
                                ) );
                            ?></span>
                        <?php endif; ?>
                    </h3>
                    <?php if ( $has_subs ) : ?>
                        <div class="tt-evf-mode"
                             data-tt-rate-detail-toggle
                             data-state="<?php echo esc_attr( $initial_state ); ?>"
                             role="tablist"
                             aria-label="<?php echo esc_attr( sprintf(
                                 /* translators: %s: main category label */
                                 __( '%s detail mode', 'talenttrack' ),
                                 $cat_label
                             ) ); ?>">
                            <button type="button" data-mode="basic"    role="tab" aria-selected="<?php echo $initial_state === 'basic' ? 'true' : 'false'; ?>"><?php echo esc_html( $toggle_basic_label ); ?></button>
                            <button type="button" data-mode="detailed" role="tab" aria-selected="<?php echo $initial_state === 'detailed' ? 'true' : 'false'; ?>"><?php echo esc_html( $toggle_detail_label ); ?></button>
                        </div>
                    <?php endif; ?>
                    <div class="tt-evf-cat__rate">
                        <input type="number" inputmode="decimal" class="tt-evf-rating" id="<?php echo esc_attr( $main_input_id ); ?>" data-tt-evf-main-rating name="ratings[<?php echo $cid; ?>]" min="<?php echo esc_attr( $rmin ); ?>" max="<?php echo esc_attr( $rmax ); ?>" step="<?php echo esc_attr( $rstep ); ?>" <?php echo $rating_required; ?> value="<?php echo esc_attr( $cur_rating ); ?>" />
                    </div>
                </div>
                <?php if ( $has_subs ) : ?>
                <div class="tt-evf-cat__subs" data-tt-rate-subs <?php echo $initial_state === 'basic' ? 'hidden' : ''; ?>>
                <?php
                foreach ( (array) $sub_cats as $sub ) :
                    $scid = (int) $sub->id;
                    if ( $scid <= 0 ) continue;
                    $sub_rating   = isset( $existing_ratings[ $scid ] ) ? (string) $existing_ratings[ $scid ] : '';
                    $sub_label    = EvalCategoriesRepository::displayLabel( (string) ( $sub->label ?? $sub->name ?? '' ), $scid );
                    $sub_input_id = 'tt-evf-rating-' . $scid;
                    ?>
                    <div class="tt-evf-sub">
                        <label for="<?php echo esc_attr( $sub_input_id ); ?>"><?php echo esc_html( $sub_label ); ?></label>
                        <input type="number" inputmode="decimal" class="tt-evf-rating" id="<?php echo esc_attr( $sub_input_id ); ?>" name="ratings[<?php echo $scid; ?>]" min="<?php echo esc_attr( $rmin ); ?>" max="<?php echo esc_attr( $rmax ); ?>" step="<?php echo esc_attr( $rstep ); ?>" value="<?php echo esc_attr( $sub_rating ); ?>" />
                    </div>
                <?php
                endforeach;
                ?>
                </div>
                <?php endif; ?>
                </div>
                <?php
            endforeach; ?>
            </div>
            </section>
            <section class="tt-evf__section" aria-labelledby="tt-evf-h-notes">
            <div class="tt-evf__section-head"><h2 class="tt-evf__section-title" id="tt-evf-h-notes"><?php echo esc_html_x( 'Notes', 'evaluation form section', 'talenttrack' ); ?></h2></div>
            <div class="tt-evf-warning" data-tt-low-rating-warning role="status" hidden>
                <?php
                // #1365 — inline SVG instead of the OS-dependent warning glyph.
                echo \TT\Shared\Icons\IconRenderer::render( 'warning', [ 'width' => 16, 'height' => 16 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — trusted SVG.
                ?>
                <span><?php
                    printf(
                        /* translators: %s is the threshold value */
                        esc_html__( 'One or more ratings are at or below %s — please add a comment explaining the low score.', 'talenttrack' ),
                        esc_html( rtrim( rtrim( number_format( $low_threshold, 1 ), '0' ), '.' ) )
                    );
                ?></span>
            </div>
            <div class="tt-evf__field tt-evf__field--wide"><label for="tt-evf-notes"><?php esc_html_e( 'Internal notes (staff only)', 'talenttrack' ); ?></label><textarea id="tt-evf-notes" name="notes" rows="3" placeholder="<?php esc_attr_e( 'Not shown to the player — staff-only.', 'talenttrack' ); ?>" data-tt-low-rating-notes><?php echo esc_textarea( $cur_notes ); ?></textarea></div>
            <?php // #1386 — optional feedback shown to the player + parents (Notes above stay staff-only). ?>
            <div class="tt-evf__field tt-evf__field--wide"><label for="tt-evf-feedback"><?php esc_html_e( 'Feedback for the player', 'talenttrack' ); ?></label><textarea id="tt-evf-feedback" name="player_feedback" rows="3" placeholder="<?php esc_attr_e( 'Optional — what they did well and what to work on next. Shown to the player and their parents.', 'talenttrack' ); ?>"><?php echo esc_textarea( $cur_feedback ); ?></textarea></div>
            </section>
            <?php
            // v3.110.58 — CLAUDE.md § 6: Save + Cancel on every
            // record-mutating form. tt_back wins when the entry URL
            // captured one; otherwise fall back to the eval detail in
            // edit mode and the evaluations list in create mode.
            $dash_url   = \TT\Shared\Frontend\Components\RecordLink::dashboardUrl();
            $list_url   = add_query_arg( [ 'tt_view' => 'evaluations' ], $dash_url );
            $detail_url = $is_edit
                ? add_query_arg( [ 'tt_view' => 'evaluations', 'id' => (int) $existing_eval->id ], $dash_url )
                : $list_url;
            $back       = \TT\Shared\Frontend\Components\BackLink::resolve();
            $cancel_url = $back !== null ? $back['url'] : ( $is_edit ? $detail_url : $list_url );

            if ( $autosaves ) {
                // #3008 — the save state replaces Save + Cancel. Two commit
                // affordances on one form is worse than either alone, and
                // §6's Cancel governs a form where Save is the commit;
                // there is nothing uncommitted here to walk away from.
                echo '<div class="tt-form-actions">';
                \TT\Shared\Frontend\Components\SaveState::render();
                echo '</div>';
            } else {
                echo FormSaveButton::render( [ 'label' => $submit_label, 'cancel_url' => $cancel_url ] );
            }
            ?>
            <div class="tt-form-msg"></div>
        </form>
        <?php
    }

    /**
     * The strip that names the player the evaluation is about: photo or
     * initials, name, team and position.
     *
     * With no player yet (the create form's picker path) it renders
     * empty and hidden, and `eval-form.js` fills it in from the picker
     * once a player is chosen.
     */
    private static function renderSubject( ?object $player, int $player_id ): void {
        $name  = $player !== null ? QueryHelpers::player_display_name( $player ) : ( $player_id > 0 ? '#' . $player_id : '' );
        $photo = $player !== null ? \TT\Modules\Players\Services\PlayerPhoto::url( $player ) : '';
        $meta  = [];
        if ( $player !== null ) {
            $team_id = (int) ( $player->team_id ?? 0 );
            $team    = $team_id > 0 ? (array) QueryHelpers::get_team( $team_id ) : [];
            $team_name = (string) ( $team['name'] ?? '' );
            if ( $team_name !== '' ) {
                $meta[] = $team_name;
            }
            $positions = json_decode( (string) ( $player->preferred_positions ?? '' ), true );
            if ( is_array( $positions ) && isset( $positions[0] ) && (string) $positions[0] !== '' ) {
                $meta[] = LabelTranslator::positionLabel( (string) $positions[0] );
            }
        }
        $live = $name === '';
        ?>
        <div class="tt-evf__subject" data-tt-evf-subject <?php echo $live ? 'data-tt-evf-subject-live hidden' : ''; ?>>
            <span class="tt-evf__avatar" aria-hidden="true" data-tt-evf-subject-avatar><?php
                if ( $photo !== '' ) {
                    printf( '<img src="%s" alt="" width="44" height="44" />', esc_url( $photo ) );
                } else {
                    echo esc_html( $name !== '' ? \TT\Shared\Frontend\Components\RecordSpine::initials( $name ) : '' );
                }
            ?></span>
            <div>
                <div class="tt-evf__subject-name" data-tt-evf-subject-name><?php echo esc_html( $name ); ?></div>
                <div class="tt-evf__subject-meta" data-tt-evf-subject-meta><?php echo esc_html( implode( ' · ', $meta ) ); ?></div>
            </div>
        </div>
        <?php
    }

    /** @param object[] $teams */
    public static function renderSessionForm( array $teams ): void {
        $statuses = QueryHelpers::get_lookup_names( 'attendance_status' );
        $all_players = [];
        foreach ( $teams as $t ) {
            foreach ( QueryHelpers::get_players( (int) $t->id ) as $pl ) {
                $all_players[ (int) $pl->id ] = $pl;
            }
        }
        ?>
        <h3><?php esc_html_e( 'Record Training Activity', 'talenttrack' ); ?></h3>
        <form id="tt-activity-form" class="tt-ajax-form" data-rest-path="activities" data-rest-method="POST">
            <div class="tt-form-row"><label><?php esc_html_e( 'Title', 'talenttrack' ); ?> *</label><input type="text" name="title" required /></div>
            <div class="tt-form-row"><label><?php esc_html_e( 'Date', 'talenttrack' ); ?> *</label><input type="date" name="session_date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required /></div>
            <div class="tt-form-row"><label><?php esc_html_e( 'Team', 'talenttrack' ); ?></label><select name="team_id">
                <?php foreach ( $teams as $t ) : ?>
                    <option value="<?php echo (int) $t->id; ?>"><?php echo esc_html( (string) $t->name ); ?></option>
                <?php endforeach; ?>
            </select></div>
            <div class="tt-form-row"><label><?php esc_html_e( 'Location', 'talenttrack' ); ?></label><input type="text" name="location" /></div>
            <div class="tt-form-row"><label><?php esc_html_e( 'Notes', 'talenttrack' ); ?></label><textarea name="notes" rows="2"></textarea></div>
            <h4><?php esc_html_e( 'Attendance', 'talenttrack' ); ?></h4>
            <?php if ( empty( $all_players ) ) : ?>
                <p><em><?php esc_html_e( 'No players on your teams yet.', 'talenttrack' ); ?></em></p>
            <?php else : ?>
                <table class="tt-table"><thead><tr><th><?php esc_html_e( 'Player', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Notes', 'talenttrack' ); ?></th></tr></thead><tbody>
                <?php foreach ( $all_players as $pl ) : ?>
                    <tr><td><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></td>
                        <td><select name="att[<?php echo (int) $pl->id; ?>][status]">
                            <?php foreach ( $statuses as $s ) : ?>
                                <option value="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( LabelTranslator::attendanceStatus( $s ) ); ?></option>
                            <?php endforeach; ?>
                        </select></td>
                        <td><input type="text" name="att[<?php echo (int) $pl->id; ?>][notes]" style="width:150px" /></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
            <?php echo FormSaveButton::render( [ 'label' => __( 'Save', 'talenttrack' ) ] ); ?>
            <div class="tt-form-msg"></div>
        </form>
        <?php
    }

    /** @param object[] $teams */
    public static function renderGoalsForm( array $teams, bool $is_admin ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $players = $is_admin ? QueryHelpers::get_players() : [];
        if ( ! $is_admin ) {
            foreach ( $teams as $t ) {
                $players = array_merge( $players, QueryHelpers::get_players( (int) $t->id ) );
            }
        }
        $statuses   = QueryHelpers::get_lookup_names( 'goal_status' );
        $priorities = QueryHelpers::get_lookup_names( 'goal_priority' );
        $pids = wp_list_pluck( $players, 'id' );
        $goals = [];
        if ( $pids ) {
            $ph = implode( ',', array_fill( 0, count( $pids ), '%d' ) );
            $goals = $wpdb->get_results( $wpdb->prepare(
                "SELECT g.*, CONCAT(pl.first_name,' ',pl.last_name) AS player_name
                 FROM {$p}tt_goals g
                 LEFT JOIN {$p}tt_players pl ON g.player_id = pl.id
                 WHERE g.player_id IN ($ph) AND g.archived_at IS NULL
                 ORDER BY g.created_at DESC
                 LIMIT 30",
                ...$pids
            ) );
        }
        ?>
        <h3><?php esc_html_e( 'Add goal', 'talenttrack' ); ?></h3>
        <form id="tt-goal-form" class="tt-ajax-form" data-rest-path="goals" data-rest-method="POST">
            <div class="tt-form-row"><label><?php esc_html_e( 'Player', 'talenttrack' ); ?> *</label><select name="player_id" required>
                <option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                <?php foreach ( $players as $pl ) : ?>
                    <option value="<?php echo (int) $pl->id; ?>"><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></option>
                <?php endforeach; ?>
            </select></div>
            <div class="tt-form-row"><label><?php esc_html_e( 'Title', 'talenttrack' ); ?> *</label><input type="text" name="title" required /></div>
            <div class="tt-form-row"><label><?php esc_html_e( 'Description', 'talenttrack' ); ?></label><textarea name="description" rows="2"></textarea></div>
            <div class="tt-form-row"><label><?php esc_html_e( 'Priority', 'talenttrack' ); ?></label><select name="priority">
                <?php foreach ( $priorities as $pr ) : ?>
                    <option value="<?php echo esc_attr( strtolower( $pr ) ); ?>"><?php echo esc_html( LabelTranslator::goalPriority( $pr ) ); ?></option>
                <?php endforeach; ?>
            </select></div>
            <div class="tt-form-row"><label><?php esc_html_e( 'Due Date', 'talenttrack' ); ?></label><input type="date" name="due_date" /></div>
            <?php // #2566 — quick-add asks what the goal develops too, or the
                  // coach's fastest path is also the one that never tags. ?>
            <?php echo \TT\Shared\Frontend\Components\GoalPrinciplePicker::render( [ 'id' => 'tt-coachform-principles', 'legacy_rows' => true ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — component escapes its own output. ?>
            <?php echo FormSaveButton::render( [ 'label' => __( 'Add goal', 'talenttrack' ) ] ); ?>
            <div class="tt-form-msg"></div>
        </form>
        <h3 style="margin-top:20px;"><?php esc_html_e( 'Current Goals', 'talenttrack' ); ?></h3>
        <?php if ( empty( $goals ) ) : ?>
            <p><?php esc_html_e( 'No goals yet.', 'talenttrack' ); ?></p>
        <?php else : ?>
            <table class="tt-table"><thead><tr>
                <th><?php esc_html_e( 'Player', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Goal', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Priority', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Due', 'talenttrack' ); ?></th><th></th>
            </tr></thead><tbody>
            <?php foreach ( $goals as $g ) : ?>
                <tr><td><?php echo esc_html( (string) $g->player_name ); ?></td><td><?php echo esc_html( (string) $g->title ); ?></td>
                    <td><?php echo esc_html( LabelTranslator::goalPriority( (string) $g->priority ) ); ?></td>
                    <td><select class="tt-goal-status-select" data-goal-id="<?php echo (int) $g->id; ?>">
                        <?php foreach ( $statuses as $st ) :
                            $v = strtolower( str_replace( ' ', '_', $st ) );
                            ?>
                            <option value="<?php echo esc_attr( $v ); ?>" <?php selected( (string) $g->status, $v ); ?>><?php echo esc_html( LabelTranslator::goalStatus( $v ) ); ?></option>
                        <?php endforeach; ?>
                    </select></td>
                    <td><?php echo esc_html( $g->due_date ? \TT\Shared\Dates\TTDate::date( (string) $g->due_date ) : '—' ); ?></td>
                    <td><button class="tt-btn-sm tt-goal-delete" data-goal-id="<?php echo (int) $g->id; ?>" aria-label="<?php esc_attr_e( 'Delete', 'talenttrack' ); ?>"><?php echo \TT\Shared\Icons\IconRenderer::render( 'x', [ 'width' => 12, 'height' => 12 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — trusted SVG. ?></button></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        <?php endif; ?>
        <?php
    }
}
