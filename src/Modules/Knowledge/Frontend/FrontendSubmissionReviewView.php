<?php
namespace TT\Modules\Knowledge\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Knowledge\CourseRegistry;
use TT\Modules\Knowledge\KnowledgePerson;
use TT\Modules\Knowledge\Repositories\EnrolmentRepository;
use TT\Modules\Knowledge\Repositories\SubmissionRepository;
use TT\Modules\Knowledge\ReviewerResolver;
use TT\Modules\Knowledge\SubmissionAttachments;
use TT\Modules\Knowledge\SubmissionQueue;
use TT\Modules\Knowledge\SubmissionService;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendSubmissionReviewView (#2648, epic #2641) — the review queue.
 *
 * What a mentor or head of development opens to read the coursework their
 * coaches have handed in, and to say what happens to it.
 *
 * ## The assignment text sits beside the submission
 *
 * A reviewer reading "I measured 4v4 at eleven minutes" needs to know what
 * was asked before that means anything. The lesson's `tt-assignment` block
 * is rendered above each submission, from the corpus, so the question and
 * the answer are never more than a scroll apart. This is the reason the
 * queue is a view of its own rather than a list of links to lessons.
 *
 * ## Oldest first
 *
 * A queue ordered newest-first starves whoever handed in on a busy week.
 * The ordering is the repository's, not this view's.
 *
 * ## Who sees what
 *
 * `SubmissionRepository::listPending()` narrows to the reviewer's own
 * routed work plus everything unrouted. A holder of `tt_manage_knowledge`
 * sees the whole queue, because somebody has to be able to clear it when a
 * mentor is away. Both facts come from `ReviewerResolver`; this view asks
 * and renders.
 */
class FrontendSubmissionReviewView extends FrontendViewBase {

    public const SLUG   = 'submission-review';
    public const ACTION = 'tt_knowledge_review';

    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        KnowledgeAssets::enqueue();
    }

    public static function render( int $user_id, bool $is_admin ): void {
        $title     = __( 'Assignments to review', 'talenttrack' );
        $person_id = KnowledgePerson::forUser( $user_id );

        // A mentor holds no management capability, so the gate is "are you
        // a reviewer" rather than a capability test — otherwise the queue
        // would be hidden from exactly the people work is routed to.
        if ( ! current_user_can( 'tt_view_knowledge' ) || ! ReviewerResolver::isReviewer( $user_id, $person_id ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to this section.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();

        $notice = self::handlePost( $user_id, $person_id );

        FrontendBreadcrumbs::fromDashboard( $title );
        self::renderHeader( $title );

        if ( $notice !== '' ) {
            echo '<p class="tt-notice tt-notice--success">' . esc_html( $notice ) . '</p>';
        }

        $pending = ( new SubmissionRepository() )->listPending(
            current_user_can( ReviewerResolver::REVIEW_CAP ) ? 0 : $person_id
        );

        if ( $pending === [] ) {
            echo '<p class="tt-notice">'
                . esc_html__( 'Nothing is waiting for review.', 'talenttrack' )
                . '</p>';
            return;
        }

        echo '<p class="tt-knowledge-review__intro">' . esc_html( sprintf(
            /* translators: %d: how many assignments are waiting */
            _n(
                '%d assignment is waiting, oldest first.',
                '%d assignments are waiting, oldest first.',
                count( $pending ),
                'talenttrack'
            ),
            count( $pending )
        ) ) . '</p>';

        // One query for the authors rather than one per row: the queue is
        // the surface a head of development opens on a Monday morning, and
        // a per-row lookup is what makes that page slow exactly when it is
        // longest.
        $context = SubmissionQueue::contextFor( $pending );

        foreach ( $pending as $submission ) {
            self::renderCard( $submission, $context );
        }
    }

    /**
     * One submission: what was asked, what came back, and the verdict form.
     *
     * @param array<int, array<string, string>> $context enrolment_id → author + course
     */
    private static function renderCard( object $submission, array $context ): void {
        $submission_id = (int) $submission->id;
        $enrolment_id  = (int) $submission->enrolment_id;
        $lesson_slug   = (string) $submission->lesson_slug;

        $meta        = $context[ $enrolment_id ] ?? [];
        $course_slug = (string) ( $meta['course_slug'] ?? '' );
        $author      = (string) ( $meta['author'] ?? __( 'A coach', 'talenttrack' ) );

        $manifest = $course_slug !== '' ? CourseRegistry::get( $course_slug ) : null;
        $lesson   = $course_slug !== '' ? CourseRegistry::lesson( $course_slug, $lesson_slug ) : null;

        echo '<article class="tt-review-card" data-tt-submission="' . (int) $submission_id . '">';

        echo '<header class="tt-review-card__header">';
        echo '<h2 class="tt-review-card__title">' . esc_html( $author ) . '</h2>';
        echo '<p class="tt-review-card__meta">' . esc_html( sprintf(
            /* translators: 1: course title, 2: lesson title */
            __( '%1$s — %2$s', 'talenttrack' ),
            $manifest !== null ? $manifest->title() : $course_slug,
            $lesson !== null ? $lesson->title() : $lesson_slug
        ) ) . '</p>';
        echo '<p class="tt-review-card__meta">' . esc_html( sprintf(
            /* translators: %s: how long ago the assignment was handed in, e.g. "3 days" */
            __( 'Handed in %s ago', 'talenttrack' ),
            human_time_diff( (int) strtotime( (string) $submission->submitted_at ) )
        ) ) . '</p>';
        echo '</header>';

        // The question, so the answer can be judged against it.
        if ( $lesson !== null ) {
            echo '<details class="tt-review-card__brief" open>';
            echo '<summary>' . esc_html__( 'What was asked', 'talenttrack' ) . '</summary>';
            echo SubmissionQueue::assignmentHtml( $lesson ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer escapes; see LessonMarkdown.
            echo '</details>';
        }

        echo '<div class="tt-review-card__answer">';
        echo '<h3 class="tt-review-card__subhead">' . esc_html__( 'What they handed in', 'talenttrack' ) . '</h3>';
        echo wp_kses_post( wpautop( esc_html( (string) ( $submission->body ?? '' ) ) ) );
        echo SubmissionAttachments::renderList( $submission_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the component.
        echo '</div>';

        self::renderVerdictForm( $submission_id );

        echo '</article>';
    }

    /**
     * The verdict.
     *
     * Feedback is `required` on the form and re-checked server-side, but
     * only for the two outcomes that need it — approving work needs no
     * justification, and demanding one would train reviewers to type "ok".
     * The browser cannot express "required unless approved", so the
     * attribute is left off and `SubmissionService` is the enforcement;
     * the hint says which is which.
     */
    private static function renderVerdictForm( int $submission_id ): void {
        $field_id = 'tt-review-feedback-' . $submission_id;

        echo '<form method="post" class="tt-review-card__form">';
        wp_nonce_field( self::ACTION, '_tt_review_nonce' );
        echo '<input type="hidden" name="tt_knowledge_action" value="' . esc_attr( self::ACTION ) . '" />';
        echo '<input type="hidden" name="submission_id" value="' . (int) $submission_id . '" />';

        // The options sit in a wrapper rather than directly in the
        // fieldset: a `legend` inside a flex container is laid out
        // inconsistently across browsers, so the fieldset stays a plain
        // block and the wrapper does the flexing.
        echo '<fieldset class="tt-review-card__outcomes">';
        echo '<legend>' . esc_html__( 'Your decision', 'talenttrack' ) . '</legend>';
        echo '<div class="tt-review-card__outcome-list">';

        foreach ( self::outcomeChoices() as $value => $label ) {
            $input_id = 'tt-review-' . $submission_id . '-' . $value;
            echo '<label class="tt-review-card__outcome" for="' . esc_attr( $input_id ) . '">';
            echo '<input type="radio" id="' . esc_attr( $input_id ) . '" name="outcome"'
                . ' value="' . esc_attr( $value ) . '" required />';
            echo '<span>' . esc_html( $label ) . '</span>';
            echo '</label>';
        }

        echo '</div>';
        echo '</fieldset>';

        echo '<label class="tt-field" for="' . esc_attr( $field_id ) . '">';
        echo '<span class="tt-field__label">' . esc_html__( 'Feedback', 'talenttrack' ) . '</span>';
        echo '<span class="tt-field__hint">'
            . esc_html__( 'Required unless you are approving it.', 'talenttrack' ) . '</span>';
        echo '<textarea id="' . esc_attr( $field_id ) . '" name="feedback" class="tt-input" rows="5"></textarea>';
        echo '</label>';

        // §6: Cancel returns to the queue the reviewer came from, which is
        // this page — so it is the one form where Cancel is a reload, and
        // that is exactly what "I have changed my mind about this verdict"
        // should do: drop the half-typed feedback and leave the row alone.
        echo FormSaveButton::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the component.
            'label'      => __( 'Record decision', 'talenttrack' ),
            'cancel_url' => KnowledgeLinks::submissionReview(),
        ] );

        echo '</form>';
    }

    /** @return array<string, string> */
    private static function outcomeChoices(): array {
        return [
            SubmissionRepository::OUTCOME_APPROVED => __( 'Approve', 'talenttrack' ),
            SubmissionRepository::OUTCOME_CHANGES  => __( 'Ask for changes', 'talenttrack' ),
            SubmissionRepository::OUTCOME_REJECTED => __( 'Do not approve', 'talenttrack' ),
        ];
    }

    /**
     * Record a verdict.
     *
     * The authorisation is per submission, not per page: reaching the queue
     * does not entitle a mentor to rule on a submission routed elsewhere,
     * and a posted `submission_id` is a number anybody can change.
     */
    private static function handlePost( int $user_id, int $person_id ): string {
        if ( ( $_POST['tt_knowledge_action'] ?? '' ) !== self::ACTION ) {
            return '';
        }

        if ( ! isset( $_POST['_tt_review_nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_tt_review_nonce'] ) ), self::ACTION )
        ) {
            return '';
        }

        $submission_id = isset( $_POST['submission_id'] ) ? (int) $_POST['submission_id'] : 0;
        $outcome       = isset( $_POST['outcome'] )
            ? sanitize_key( wp_unslash( (string) $_POST['outcome'] ) )
            : '';
        $feedback = isset( $_POST['feedback'] )
            ? sanitize_textarea_field( wp_unslash( (string) $_POST['feedback'] ) )
            : '';

        $submission = ( new SubmissionRepository() )->find( $submission_id );
        if ( $submission === null ) {
            return __( 'That submission no longer exists.', 'talenttrack' );
        }

        $routed_to = $submission->reviewer_person_id === null
            ? null
            : (int) $submission->reviewer_person_id;

        if ( ! ReviewerResolver::canReview( $user_id, $person_id, $routed_to ) ) {
            return __( 'That submission is not yours to review.', 'talenttrack' );
        }

        $result = ( new SubmissionService() )->review( $submission_id, $outcome, $feedback, $person_id );

        switch ( $result['status'] ) {
            case SubmissionService::OK:
                return $result['completed']
                    ? __( 'Decision recorded. That completed their course.', 'talenttrack' )
                    : __( 'Decision recorded.', 'talenttrack' );

            case SubmissionService::ERR_NO_FEEDBACK:
                return __( 'Say why before asking for changes or turning it down.', 'talenttrack' );

            case SubmissionService::ERR_BAD_OUTCOME:
                return __( 'Choose a decision first.', 'talenttrack' );

            default:
                return __( 'That decision could not be recorded.', 'talenttrack' );
        }
    }
}
