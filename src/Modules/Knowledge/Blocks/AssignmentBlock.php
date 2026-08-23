<?php
namespace TT\Modules\Knowledge\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Knowledge\Frontend\KnowledgeLinks;
use TT\Modules\Knowledge\LessonContext;
use TT\Modules\Knowledge\LessonMarkdown;
use TT\Modules\Knowledge\Repositories\SubmissionRepository;
use TT\Modules\Knowledge\SubmissionAttachments;
use TT\Modules\Knowledge\SubmissionService;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FormSaveButton;

/**
 * AssignmentBlock — the practical assignment, and the place to hand it in.
 *
 *     ```tt-assignment id="04-nulpunt"
 *     **Praktijkopdracht 4**
 *
 *     Voer met je eigen team twee nulpuntmetingen uit …
 *     ```
 *
 * These assignments are the reason the course has a review queue at all.
 * A quiz can establish that a coach knows 4v4 needs seventy-two hours; only
 * a mentor reading a submitted twelve-week plan can establish that they
 * built one.
 *
 * ## Four states, and the block renders whichever one is true
 *
 *   - **nothing handed in** — the assignment text and a form
 *   - **awaiting review** — what they wrote, and that it is with a reviewer
 *   - **changes requested** — the feedback, and the form again
 *   - **approved or rejected** — the verdict, and no form
 *
 * A rejection does not reopen the form. Asking for changes is how a
 * reviewer invites another attempt, and collapsing the two would leave a
 * reviewer no way to say no that means it.
 *
 * ## The form posts
 *
 * A plain `<form method="post">` handled by `FrontendLessonView`, so
 * handing work in never depends on JavaScript. Attachments do need it —
 * they upload through the media REST endpoint — which is why the written
 * answer is the submission and a document is an addition to it rather than
 * the other way round. A coach with no JavaScript can still finish the
 * course.
 *
 * The multi-step path, `?tt_view=wizard&slug=submit-assignment`, exists
 * beside this form rather than instead of it (CLAUDE.md §3): this block is
 * the flat power-user form the wizard hands off to.
 */
final class AssignmentBlock implements BlockRenderer {

    /** POST action the lesson view dispatches on. */
    public const ACTION = 'tt_knowledge_assignment';

    public static function name(): string {
        return 'tt-assignment';
    }

    /**
     * The attachment control needs the media uploader script, which the
     * lesson enqueues on the strength of this flag.
     */
    public static function isInteractive(): bool {
        return true;
    }

    public static function render( array $attrs, string $body ): string {
        $id   = trim( $attrs['id'] ?? '' );
        $text = LessonMarkdown::renderProse( $body );

        $html  = '<section class="tt-lesson-assignment"'
            . ( $id !== '' ? ' data-tt-assignment="' . esc_attr( $id ) . '"' : '' ) . '>';
        $html .= '<p class="tt-lesson-assignment__label">'
            . esc_html__( 'Practical assignment', 'talenttrack' ) . '</p>';
        $html .= '<div class="tt-lesson-assignment__body">' . $text . '</div>';

        $html .= self::renderState();
        $html .= '</section>';

        return $html;
    }

    /**
     * Everything below the assignment text: the verdict so far, and a form
     * when it is the coach's turn.
     */
    private static function renderState(): string {
        if ( ! LessonContext::hasReader() ) {
            // No enrolment behind the render — a preview, or a reader whose
            // login is not linked to a staff record. The assignment is still
            // worth reading; there is just nowhere to put an answer.
            return '<p class="tt-lesson-assignment__note">'
                . esc_html__( 'Work this through with your own team. Sign in with a linked staff account to hand it in for review.', 'talenttrack' )
                . '</p>';
        }

        $repository = new SubmissionRepository();
        $service    = new SubmissionService();

        $latest = $repository->latestFor( LessonContext::enrolment(), LessonContext::lesson() );

        $html = self::renderVerdict( $latest );

        if ( $service->isResubmittable( $latest ) ) {
            $html .= self::renderForm( $latest );
        }

        return $html;
    }

    /** What has happened to the work so far. Nothing, when nothing has. */
    private static function renderVerdict( ?object $latest ): string {
        if ( $latest === null ) return '';

        $outcome = (string) $latest->outcome;
        $body    = trim( (string) ( $latest->body ?? '' ) );

        $html  = '<div class="tt-lesson-assignment__submission" data-tt-submission-state="'
            . esc_attr( $outcome === '' ? 'pending' : $outcome ) . '">';

        $html .= '<h3 class="tt-lesson-assignment__subhead">'
            . esc_html__( 'What you handed in', 'talenttrack' ) . '</h3>';

        if ( $body !== '' ) {
            $html .= '<blockquote class="tt-lesson-assignment__quote">'
                . wpautop( esc_html( $body ) ) . '</blockquote>';
        }

        $html .= SubmissionAttachments::renderList( (int) $latest->id );
        $html .= '<p class="tt-lesson-assignment__status">' . esc_html( self::statusLine( $latest ) ) . '</p>';

        $feedback = trim( (string) ( $latest->feedback ?? '' ) );
        if ( $feedback !== '' ) {
            $html .= '<div class="tt-lesson-assignment__feedback">';
            $html .= '<h3 class="tt-lesson-assignment__subhead">'
                . esc_html__( 'What the reviewer said', 'talenttrack' ) . '</h3>';
            $html .= wpautop( esc_html( $feedback ) );
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    private static function statusLine( object $latest ): string {
        switch ( (string) $latest->outcome ) {
            case SubmissionRepository::OUTCOME_APPROVED:
                return __( 'Approved. This lesson counts as finished.', 'talenttrack' );

            case SubmissionRepository::OUTCOME_CHANGES:
                return __( 'The reviewer has asked for changes. Revise it below and hand it in again.', 'talenttrack' );

            case SubmissionRepository::OUTCOME_REJECTED:
                return __( 'Not approved. Talk to your reviewer about what to do next.', 'talenttrack' );

            default:
                return __( 'Handed in and waiting for review.', 'talenttrack' );
        }
    }

    /**
     * The hand-in form.
     *
     * Attachments are offered only on a submission that already exists,
     * because a `tt_media_links` row needs something to point at. On a
     * first attempt the coach writes and submits, and the attachment
     * control appears on what comes back — which also means a document is
     * never uploaded against work that was then abandoned.
     */
    private static function renderForm( ?object $latest ): string {
        $is_revision = $latest !== null;
        $draft       = $is_revision ? (string) ( $latest->body ?? '' ) : '';

        $html  = '<form method="post" class="tt-lesson-assignment__form">';
        $html .= wp_nonce_field( self::ACTION, '_tt_assignment_nonce', true, false );
        $html .= '<input type="hidden" name="tt_knowledge_action" value="' . esc_attr( self::ACTION ) . '" />';

        $field_id = 'tt-assignment-body-' . wp_unique_id();

        $html .= '<label class="tt-field" for="' . esc_attr( $field_id ) . '">';
        $html .= '<span class="tt-field__label">'
            . esc_html__( 'Your answer', 'talenttrack' ) . '</span>';
        $html .= '<textarea id="' . esc_attr( $field_id ) . '" name="assignment_body" class="tt-input"'
            . ' rows="10" required'
            . ' placeholder="' . esc_attr__( 'Describe what you did with your own team, and what you found.', 'talenttrack' ) . '">'
            . esc_textarea( $draft )
            . '</textarea>';
        $html .= '</label>';

        if ( $is_revision ) {
            $html .= SubmissionAttachments::renderUploader( (int) $latest->id );
        } else {
            $html .= '<p class="tt-lesson-assignment__note">'
                . esc_html__( 'You can attach a document once you have handed this in.', 'talenttrack' )
                . '</p>';
        }

        // §6: Cancel beside Save, through the shared helper so the pair
        // keeps the canonical order, spacing and tab sequence. Cancel goes
        // back to the course — the lesson is where the coach already is, so
        // sending them there would be a link to the page they are on — and
        // `tt_back` wins when the entry URL carried one.
        $html .= FormSaveButton::render( [
            'label'      => $is_revision
                ? __( 'Hand in again', 'talenttrack' )
                : __( 'Hand in for review', 'talenttrack' ),
            'cancel_url' => self::cancelUrl(),
        ] );

        $html .= '</form>';

        return $html;
    }

    /** Where Cancel goes: the `tt_back` target if there is one, else the course. */
    private static function cancelUrl(): string {
        $back = BackLink::resolve();
        if ( $back !== null ) return $back['url'];

        return KnowledgeLinks::course( LessonContext::course() );
    }
}
