<?php
namespace TT\Modules\Training\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\FeatureRegistry;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Exercises\Vision\ExerciseFuzzyMatcher;
use TT\Modules\Exercises\Vision\VisionDataRegion;
use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * FrontendTrainingPhotoView (#2502, wave 9 of epic #2493) — photograph a
 * hand-written plan and get a draft back.
 *
 * ## Everything this needs on the server already existed
 *
 * `POST /vision/extract` extracts and fuzzy-matches; `POST /training/plans`
 * takes `source => 'photo'`; `PUT /training/plans/{id}/blocks` replaces the
 * block set wholesale. Wave 9 is the screen those three were built for and
 * never got, which is why it is a view and a script rather than a module.
 *
 * ## Three states, and the middle one is not a spinner
 *
 * Capture, read, review. The review step is the reason this is not simply
 * the generator's step 4: there the system proposes blocks it chose and the
 * coach decides whether they LIKE them; here the coach decides whether a
 * machine read their handwriting CORRECTLY. Different question, so every row
 * carries how confident the match is, why it might be wrong, and an editable
 * name and duration.
 *
 * ## Nothing is persisted before the coach confirms
 *
 * The extraction round-trips through the browser and is held in memory. Close
 * the tab at the review step and there is no plan, no blocks, and no
 * photograph anywhere. The first write of any kind happens when the coach
 * presses the button that says so.
 *
 * ## Where the photograph goes is on the screen
 *
 * Not in a settings page. The coach deciding whether to take the photo is the
 * person entitled to know where it lands, so the declared
 * `TT_VISION_DATA_REGION` is rendered next to the shutter. An install that
 * has declared nothing cannot reach this screen at all — the same refusal
 * `VisionExtractRestController` makes, made before the camera opens rather
 * than after the picture is taken.
 */
final class FrontendTrainingPhotoView extends FrontendViewBase {

    /**
     * Above this, a match is shown as certain and the row is not tinted.
     *
     * Its lower sibling is `ExerciseFuzzyMatcher::DEFAULT_MIN_SIMILARITY`
     * and is read from there. This one has no equivalent in the matcher —
     * the matcher only knows "good enough to offer"; deciding what is good
     * enough to stop asking a coach about is a product judgement, and it
     * belongs on the screen that acts on it.
     */
    public const CONFIDENCE_SURE = 0.85;

    /**
     * How long a photograph held on a coach's phone survives (#2735).
     *
     * Seven days, decided 2026-08-23 and recorded in
     * `docs/photo-capture-dpia.md` § 3: the window in which a coach who
     * photographs on a Friday evening and looks the following weekend still
     * has their session. It is a ceiling, not a target — a held photo is
     * dropped the moment it has been read and reviewed.
     */
    public const HOLD_DAYS = 7;

    public static function render( int $user_id, bool $is_admin ): void {
        FrontendBreadcrumbs::fromDashboard(
            __( 'Photo to plan', 'talenttrack' ),
            [ FrontendBreadcrumbs::viewCrumb( 'training-plans', __( 'Training', 'talenttrack' ) ) ]
        );

        if ( ! AuthorizationService::userCanOrMatrix( $user_id, 'tt_training_plan' ) ) {
            echo '<p class="tt-notice">'
                . esc_html__( 'You do not have permission to build training plans.', 'talenttrack' )
                . '</p>';
            return;
        }

        // The feature switch, and then the destination. Both are refusals
        // the REST layer would also make; making them here means a coach
        // is told before they frame a photograph, not after.
        if ( ! FeatureRegistry::isEnabled( 'exercises_vision_extraction' ) ) {
            self::renderHeader( __( 'Photo to plan', 'talenttrack' ) );
            echo '<p class="tt-notice">'
                . esc_html__( 'Reading a photographed plan is switched off for this academy. Build a plan by hand instead.', 'talenttrack' )
                . '</p>';
            self::renderManualFallback();
            return;
        }

        // #3106 — and the plan. Told here, before the camera opens, for the
        // same reason the switch is: a coach who has framed a photograph
        // and then been refused has wasted a minute they were standing on a
        // touchline for. The manual fallback stays — the point is to leave
        // with a plan either way.
        if ( ! \TT\Modules\License\LicenseGate::allows( 'exercises_vision_extraction' ) ) {
            self::renderHeader( __( 'Photo to plan', 'talenttrack' ) );
            echo \TT\Modules\License\UpgradePanel::render( 'exercises_vision_extraction' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — UpgradePanel returns escaped HTML
            self::renderManualFallback();
            return;
        }

        if ( ! VisionDataRegion::isDeclared() ) {
            self::renderHeader( __( 'Photo to plan', 'talenttrack' ) );
            echo '<p class="tt-notice">'
                . esc_html__( 'This site has not said where photographs would be sent to be read, so this cannot be used yet. An administrator needs to set that up first. Nothing has been sent.', 'talenttrack' )
                . '</p>';
            self::renderManualFallback();
            return;
        }

        self::enqueue( $user_id );
        self::renderHeader( __( 'Photo to plan', 'talenttrack' ) );

        echo '<p class="tt-muted tt-photo__intro">'
            . esc_html__( 'Photograph the training you wrote out. You get a draft to check before anything is saved.', 'talenttrack' )
            . '</p>';

        echo '<div class="tt-photo" data-tt-photo></div>';

        echo '<noscript><p class="tt-notice">'
            . esc_html__( 'Reading a photograph needs JavaScript. You can still build a plan by hand.', 'talenttrack' )
            . '</p></noscript>';

        self::renderManualFallback();
    }

    /**
     * The way out.
     *
     * Present on every path, including the two refusals, because a coach
     * who came here to plan a training should never reach a dead end — the
     * flat form has always worked and is one link away.
     */
    private static function renderManualFallback(): void {
        printf(
            '<p class="tt-photo__manual"><a href="%s">%s</a></p>',
            esc_url( add_query_arg( /* tt-xview-ok — same module, and the fallback this screen degrades to */
                [ 'tt_view' => 'training-plans' ],
                RecordLink::dashboardUrl()
            ) ),
            esc_html__( 'Build a training plan by hand instead', 'talenttrack' )
        );
    }

    private static function enqueue( int $user_id ): void {
        wp_enqueue_style(
            'tt-frontend-training-photo',
            TT_PLUGIN_URL . 'assets/css/frontend-training-photo.css',
            [],
            TT_VERSION
        );

        // #2735 — the hold store, loaded first so the capture script can
        // reach `TT.photoHold` on its own init.
        self::enqueuePhotoHold();

        wp_enqueue_script(
            'tt-frontend-training-photo',
            TT_PLUGIN_URL . 'assets/js/frontend-training-photo.js',
            [ 'tt-photo-hold' ],
            TT_VERSION,
            true
        );

        // `wp_add_inline_script`, not `wp_localize_script`: the confidence
        // thresholds are numbers, and localize casts every scalar to a
        // string. `0.85` arriving as "0.85" would make every comparison
        // in the review grid a string comparison, and the tints would be
        // wrong in ways nobody would notice until a coach trusted one.
        $config = [
            'restBase'   => esc_url_raw( rest_url( 'talenttrack/v1' ) ),
            'nonce'      => wp_create_nonce( 'wp_rest' ),
            'teams'      => self::teamOptions( $user_id ),
            'dataRegion' => VisionDataRegion::region(),
            'sure'       => self::CONFIDENCE_SURE,
            // Read from the matcher, never restated. A second copy of
            // this number would drift the day someone tunes the matcher,
            // and the tint would then tell a coach a row is trustworthy
            // on a threshold the matcher no longer uses.
            'maybe'      => ExerciseFuzzyMatcher::DEFAULT_MIN_SIMILARITY,
            'plansUrl'   => esc_url_raw( add_query_arg( /* tt-xview-ok — the plan this screen creates */
                [ 'tt_view' => 'training-plan' ],
                RecordLink::dashboardUrl()
            ) ),
            'holdDays'   => self::HOLD_DAYS,
            'i18n'       => self::strings(),
        ];

        wp_add_inline_script(
            'tt-frontend-training-photo',
            'var TT_TRAINING_PHOTO = ' . wp_json_encode( $config ) . ';',
            'before'
        );
    }

    /**
     * The hold store, and the strings it renders on its own.
     *
     * Enqueued from here and from the plans list, because a coach who
     * navigated away has to be able to find the photo that is waiting;
     * `wp_enqueue_script` deduplicates.
     */
    public static function enqueuePhotoHold(): void {
        wp_enqueue_script(
            'tt-photo-hold',
            TT_PLUGIN_URL . 'assets/js/tt-photo-hold.js',
            [],
            TT_VERSION,
            true
        );

        wp_add_inline_script(
            'tt-photo-hold',
            'var TT_PHOTO_HOLD = ' . wp_json_encode( [
                'holdDays' => self::HOLD_DAYS,
                'i18n'     => [
                    'pendingOne'  => __( 'A photo is waiting to be read', 'talenttrack' ),
                    /* translators: %d is how many photographs are waiting to be read. */
                    'pendingMany' => __( '%d photos are waiting to be read', 'talenttrack' ),
                ],
            ] ) . ';',
            'before'
        );
    }

    /**
     * The teams this coach may plan for.
     *
     * A photographed plan has to belong to one, and asking after the
     * extraction — when the coach is already reading rows — would be a
     * question in the wrong place. It sits on the capture screen instead.
     *
     * @return list<array{value:int, label:string}>
     */
    private static function teamOptions( int $user_id ): array {
        // The same split the generator wizard's first step makes, so the
        // two screens offer a coach the same teams.
        $teams = current_user_can( 'tt_edit_settings' )
            ? QueryHelpers::get_teams()
            : QueryHelpers::get_teams_for_coach( $user_id );

        $out = [];
        foreach ( $teams as $team ) {
            $id = (int) ( $team->id ?? 0 );
            if ( $id <= 0 ) continue;

            $out[] = [ 'value' => $id, 'label' => (string) ( $team->name ?? '' ) ];
        }

        return $out;
    }

    /** @return array<string,string> */
    private static function strings(): array {
        return [
            // capture
            'frameIt'        => __( 'Lay the sheet flat and fill the frame', 'talenttrack' ),
            'takePhoto'      => __( 'Take a photo', 'talenttrack' ),
            'fromLibrary'    => __( 'From gallery', 'talenttrack' ),
            'forTeam'        => __( 'For team', 'talenttrack' ),
            'noCamera'       => __( 'No camera available on this device. Choose a photo instead.', 'talenttrack' ),
            'cameraRefused'  => __( 'This browser did not give access to the camera. Choose a photo from your gallery instead.', 'talenttrack' ),
            /* translators: %s is where the photograph is processed, e.g. "EU (Frankfurt)". */
            'destination'    => __( 'The photo is read by an AI service in %s and is not stored here. Player names are not copied into notes.', 'talenttrack' ),

            // reading
            'reading'        => __( 'Reading…', 'talenttrack' ),
            'readingSub'     => __( 'Usually a few seconds. You can change anything afterwards.', 'talenttrack' ),
            'nothingSaved'   => __( 'Nothing has been saved yet.', 'talenttrack' ),

            // review
            'checkThis'      => __( 'Does this look right?', 'talenttrack' ),
            /* translators: 1: number of exercises read, 2: total minutes. */
            'readSummary'    => __( '%1$d exercises read · %2$d minutes. Change whatever is wrong.', 'talenttrack' ),
            'legendSure'     => __( 'certain', 'talenttrack' ),
            'legendMaybe'    => __( 'check this one', 'talenttrack' ),
            'legendUnsure'   => __( 'not recognised', 'talenttrack' ),
            'nameLabel'      => __( 'Name', 'talenttrack' ),
            'minutesLabel'   => __( 'Minutes', 'talenttrack' ),
            'noMatch'        => __( 'No match', 'talenttrack' ),
            'whyMaybe'       => __( 'Close to more than one exercise. Check this is the right one.', 'talenttrack' ),
            'whyUnsure'      => __( 'Not recognised. It stays as a loose block if you leave it — and then it does not count towards what your players have been taught.', 'talenttrack' ),
            'planTitle'      => __( 'Title', 'talenttrack' ),
            'discard'        => __( 'Discard', 'talenttrack' ),
            'createDraft'    => __( 'Create draft', 'talenttrack' ),
            'removeRow'      => __( 'Remove', 'talenttrack' ),

            // outcomes
            'creating'       => __( 'Creating the draft…', 'talenttrack' ),
            'created'        => __( 'Draft created. Opening it…', 'talenttrack' ),
            'createFailed'   => __( 'The draft could not be created. Nothing was saved; try again.', 'talenttrack' ),
            'readFailed'     => __( 'That photo could not be read. Try a clearer one, or build the plan by hand.', 'talenttrack' ),
            'offline'        => __( 'You have no connection, so the photo cannot be read yet. Try again when you have signal — nothing has been sent.', 'talenttrack' ),

            // held (#2735)
            /* translators: %d is the number of days a photo is kept on the phone. */
            'held'           => __( 'No connection, so the photo is waiting on this phone. It is read as soon as you have signal, and is deleted after %d days whether or not it has been read.', 'talenttrack' ),
            'heldStill'      => __( 'Still no connection. The photo is safe on this phone and will be read when you have signal.', 'talenttrack' ),
            'resuming'       => __( 'Reading the photo that was waiting…', 'talenttrack' ),
            'pendingOne'     => __( 'A photo is waiting to be read. It goes as soon as you have signal.', 'talenttrack' ),
            /* translators: %d is how many photographs are waiting to be read. */
            'pendingMany'    => __( '%d photos are waiting to be read. They go as soon as you have signal.', 'talenttrack' ),
            'expiredOne'     => __( 'A photo waited too long and has been deleted. It was never read, so nothing was saved — take it again if you still need it.', 'talenttrack' ),
            /* translators: %d is how many photographs were deleted. */
            'expiredMany'    => __( '%d photos waited too long and have been deleted. They were never read, so nothing was saved — take them again if you still need them.', 'talenttrack' ),
            'tooBig'         => __( 'That photo is too large to send. Take another one, or choose a smaller file.', 'talenttrack' ),
            'nothingRead'    => __( 'Nothing could be read from that photo. Try again with the sheet flat and the whole page in frame.', 'talenttrack' ),
            'titleRequired'  => __( 'Give the training a title before creating it.', 'talenttrack' ),
            'teamRequired'   => __( 'Choose which team this training is for.', 'talenttrack' ),
        ];
    }
}
