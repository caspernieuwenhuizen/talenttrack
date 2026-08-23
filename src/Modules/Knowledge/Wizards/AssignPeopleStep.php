<?php
namespace TT\Modules\Knowledge\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\People\PeopleRepository;
use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\Knowledge\CourseRegistry;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 2 — who takes it (#2649).
 *
 * Staff only, filtered to the personas the course names in its `audience`.
 *
 * ## The fallback matters more than the filter
 *
 * Audience matching needs a person to be linked to a WordPress account whose
 * roles resolve to a persona. On an install where staff records exist but
 * accounts have not been linked yet — which is most installs early on — a
 * strict filter produces an empty list and a wizard that appears broken.
 *
 * So: filter when the filter finds somebody, and fall back to the full staff
 * list with a visible explanation when it does not. An administrator who can
 * see *why* the list is unfiltered can act on it; one staring at an empty
 * step can only guess.
 */
final class AssignPeopleStep implements WizardStepInterface {

    public const FIELD = 'person_ids';

    public function slug(): string { return 'people'; }

    public function label(): string { return __( 'Who', 'talenttrack' ); }

    public function render( array $state ): void {
        $course_slug = (string) ( $state[ AssignCourseStep::FIELD ] ?? '' );
        $manifest    = $course_slug !== '' ? CourseRegistry::get( $course_slug ) : null;

        if ( $manifest === null ) {
            echo '<p>' . esc_html__( 'Go back and choose a course first.', 'talenttrack' ) . '</p>';
            return;
        }

        [ $people, $filtered ] = self::candidates( $manifest->audience() );

        if ( $people === [] ) {
            echo '<p>' . esc_html__( 'This academy has no staff records to assign a course to.', 'talenttrack' ) . '</p>';
            return;
        }

        if ( $filtered ) {
            echo '<p>' . esc_html( sprintf(
                /* translators: %s: a comma-separated list of personas, e.g. "coach, head coach" */
                __( 'Staff matching this course\'s audience: %s.', 'talenttrack' ),
                implode( ', ', $manifest->audience() )
            ) ) . '</p>';
        } else {
            echo '<p class="description">'
                . esc_html__( 'Showing all staff. Nobody matched this course\'s audience, which usually means staff records are not linked to accounts yet.', 'talenttrack' )
                . '</p>';
        }

        $chosen = array_map( 'intval', (array) ( $state[ self::FIELD ] ?? [] ) );

        echo '<fieldset class="tt-assign-people">';
        echo '<legend>' . esc_html__( 'Who takes this course', 'talenttrack' ) . '</legend>';
        echo '<div class="tt-assign-people__list">';

        foreach ( $people as $person ) {
            $id    = (int) $person->id;
            $name  = trim( (string) ( $person->first_name ?? '' ) . ' ' . (string) ( $person->last_name ?? '' ) );
            $input = 'tt-assign-person-' . $id;

            echo '<label class="tt-assign-people__row" for="' . esc_attr( $input ) . '">';
            echo '<input type="checkbox" id="' . esc_attr( $input ) . '"'
                . ' name="' . esc_attr( self::FIELD ) . '[]"'
                . ' value="' . (int) $id . '"'
                . checked( in_array( $id, $chosen, true ), true, false ) . ' />';
            echo '<span>' . esc_html( $name !== '' ? $name : __( 'A staff member', 'talenttrack' ) ) . '</span>';
            echo '</label>';
        }

        echo '</div>';
        echo '</fieldset>';
    }

    public function validate( array $post, array $state ) {
        $ids = array_values( array_unique( array_filter( array_map(
            'intval',
            (array) ( $post[ self::FIELD ] ?? [] )
        ) ) ) );

        if ( $ids === [] ) {
            return new \WP_Error( 'nobody', __( 'Choose at least one person.', 'talenttrack' ) );
        }

        // Never trust the posted ids: re-read them as staff of this club, so
        // a hand-edited form cannot enrol a player or somebody else's person
        // record.
        $course_slug = (string) ( $state[ AssignCourseStep::FIELD ] ?? '' );
        $manifest    = $course_slug !== '' ? CourseRegistry::get( $course_slug ) : null;
        $audience    = $manifest !== null ? $manifest->audience() : [];

        [ $people ] = self::candidates( $audience );

        $allowed = [];
        foreach ( $people as $person ) {
            $allowed[] = (int) $person->id;
        }

        $valid = array_values( array_intersect( $ids, $allowed ) );

        if ( $valid === [] ) {
            return new \WP_Error( 'nobody_valid', __( 'Those people could not be found. Choose again.', 'talenttrack' ) );
        }

        return [ self::FIELD => $valid ];
    }

    public function nextStep( array $state ): ?string { return 'due'; }

    public function submit( array $state ) { return null; }

    /**
     * Staff who may take a course with this audience.
     *
     * @param  list<string> $audience
     * @return array{0: array<int, object>, 1: bool} the people, and whether the audience filter was applied
     */
    public static function candidates( array $audience ): array {
        $staff = ( new PeopleRepository() )->list( [ 'only_staff' => true, 'status' => 'active' ] );

        if ( $audience === [] ) {
            return [ $staff, false ];
        }

        $matched = [];
        foreach ( $staff as $person ) {
            $user_id = (int) ( $person->wp_user_id ?? 0 );
            if ( $user_id <= 0 ) continue;

            if ( array_intersect( PersonaResolver::personasFor( $user_id ), $audience ) !== [] ) {
                $matched[] = $person;
            }
        }

        return $matched !== [] ? [ $matched, true ] : [ $staff, false ];
    }
}
