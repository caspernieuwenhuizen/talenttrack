<?php
namespace TT\Modules\Knowledge\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Knowledge\CourseRegistry;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 1 — which course (#2649).
 *
 * Lists every course the corpus provides, not the subset the administrator
 * can personally open. Assigning is a management action about somebody
 * else's learning: a head of development who has not taken the periodisation
 * course is exactly the person who assigns it.
 */
final class AssignCourseStep implements WizardStepInterface {

    public const FIELD = 'course_slug';

    public function slug(): string { return 'course'; }

    public function label(): string { return __( 'Course', 'talenttrack' ); }

    public function render( array $state ): void {
        $courses = CourseRegistry::all();

        if ( $courses === [] ) {
            echo '<p>' . esc_html__( 'This install has no courses.', 'talenttrack' ) . '</p>';
            return;
        }

        $chosen = (string) ( $state[ self::FIELD ] ?? '' );

        echo '<label class="tt-field" for="tt-assign-course">';
        echo '<span class="tt-field__label">' . esc_html__( 'Course', 'talenttrack' ) . '</span>';
        echo '<select id="tt-assign-course" name="' . esc_attr( self::FIELD ) . '" class="tt-input" required>';
        echo '<option value="">' . esc_html__( 'Choose a course', 'talenttrack' ) . '</option>';

        foreach ( $courses as $slug => $manifest ) {
            printf(
                '<option value="%1$s"%2$s>%3$s</option>',
                esc_attr( (string) $slug ),
                selected( $chosen, (string) $slug, false ),
                esc_html( $manifest->title() )
            );
        }

        echo '</select>';
        echo '</label>';
    }

    public function validate( array $post, array $state ) {
        $slug = isset( $post[ self::FIELD ] ) ? sanitize_key( (string) $post[ self::FIELD ] ) : '';

        if ( $slug === '' || CourseRegistry::get( $slug ) === null ) {
            return new \WP_Error( 'no_course', __( 'Choose a course to assign.', 'talenttrack' ) );
        }

        return [ self::FIELD => $slug ];
    }

    public function nextStep( array $state ): ?string { return 'people'; }

    public function submit( array $state ) { return null; }
}
