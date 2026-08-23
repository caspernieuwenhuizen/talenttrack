<?php
namespace TT\Modules\Knowledge\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Knowledge\CourseRegistry;
use TT\Modules\Knowledge\Frontend\KnowledgeLinks;
use TT\Modules\Knowledge\KnowledgePerson;
use TT\Modules\Knowledge\ReviewerResolver;
use TT\Modules\Knowledge\SubmissionAttachments;
use TT\Modules\Knowledge\SubmissionService;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 3 — confirm and hand in (#2648).
 *
 * The step that turns the draft into a submission. Everything before it is
 * reversible; this is the one that puts the work in somebody's queue, so it
 * shows what is about to be sent and who will read it before it does.
 *
 * Naming the reviewer here matters more than it looks. A coach who knows
 * their submission is going to their own mentor writes differently from one
 * handing work to an anonymous queue, and a coach who has no mentor should
 * find that out now rather than wonder for a week why nobody has replied.
 */
final class AssignmentConfirmStep implements WizardStepInterface {

    public function slug(): string { return 'confirm'; }

    public function label(): string { return __( 'Check and hand in', 'talenttrack' ); }

    public function render( array $state ): void {
        $submission_id = (int) ( $state['submission_id'] ?? 0 );
        if ( $submission_id <= 0 ) {
            echo '<p>' . esc_html__( 'Go back and write your answer first.', 'talenttrack' ) . '</p>';
            return;
        }

        $course_slug = (string) ( $state['course_slug'] ?? '' );
        $lesson_slug = (string) ( $state['lesson_slug'] ?? '' );
        $lesson      = $course_slug !== '' ? CourseRegistry::lesson( $course_slug, $lesson_slug ) : null;

        if ( $lesson !== null ) {
            echo '<p>' . esc_html( sprintf(
                /* translators: %s: the lesson the assignment belongs to */
                __( 'Handing in the assignment for %s.', 'talenttrack' ),
                $lesson->title()
            ) ) . '</p>';
        }

        echo '<h3 class="tt-wizard__subhead">' . esc_html__( 'Your answer', 'talenttrack' ) . '</h3>';
        echo wp_kses_post( wpautop( esc_html( (string) ( $state[ AssignmentWriteStep::FIELD ] ?? '' ) ) ) );

        $files = SubmissionAttachments::renderList( $submission_id );
        echo $files !== ''
            ? $files // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the component.
            : '<p class="description">' . esc_html__( 'No documents attached.', 'talenttrack' ) . '</p>';

        echo '<h3 class="tt-wizard__subhead">' . esc_html__( 'Who will review it', 'talenttrack' ) . '</h3>';
        echo '<p>' . esc_html( self::reviewerLine() ) . '</p>';
    }

    /**
     * Who this will go to, resolved the same way the hand-in will resolve
     * it — so the sentence a coach reads here is the routing they get.
     */
    private static function reviewerLine(): string {
        $reviewer_id = ReviewerResolver::forSubmitter( KnowledgePerson::current() );

        if ( $reviewer_id <= 0 ) {
            return __( 'You have no mentor set, so this goes to the shared review queue and whoever picks it up first.', 'talenttrack' );
        }

        $name = self::personName( $reviewer_id );

        return $name === ''
            ? __( 'This goes to your mentor.', 'talenttrack' )
            : sprintf(
                /* translators: %s: the mentor who will review the assignment */
                __( 'This goes to your mentor, %s.', 'talenttrack' ),
                $name
            );
    }

    private static function personName( int $person_id ): string {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT first_name, last_name FROM {$wpdb->prefix}tt_people WHERE id = %d",
            $person_id
        ) );

        if ( ! $row ) return '';

        return trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) );
    }

    public function validate( array $post, array $state ) {
        return [];
    }

    public function nextStep( array $state ): ?string { return null; }

    public function submit( array $state ) {
        $submission_id = (int) ( $state['submission_id'] ?? 0 );

        $result = ( new SubmissionService() )->handInDraft( $submission_id );

        if ( $result['status'] !== SubmissionService::OK ) {
            return new \WP_Error(
                'not_handed_in',
                $result['status'] === SubmissionService::ERR_EMPTY
                    ? __( 'Go back and write your answer before handing it in.', 'talenttrack' )
                    : __( 'That assignment could not be handed in.', 'talenttrack' )
            );
        }

        $course_slug = (string) ( $state['course_slug'] ?? '' );
        $lesson_slug = (string) ( $state['lesson_slug'] ?? '' );

        // Back to the lesson, where the block now shows the submission and
        // its state — rather than to a wizard-shaped confirmation page the
        // coach would then have to navigate out of.
        return [
            'redirect_url' => $course_slug !== '' && $lesson_slug !== ''
                ? KnowledgeLinks::lesson( $course_slug, $lesson_slug )
                : KnowledgeLinks::library(),
        ];
    }
}
