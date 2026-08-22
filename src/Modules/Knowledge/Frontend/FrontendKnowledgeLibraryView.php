<?php
namespace TT\Modules\Knowledge\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Knowledge\CourseAccessResolver;
use TT\Modules\Knowledge\CourseCompletionService;
use TT\Modules\Knowledge\CourseManifest;
use TT\Modules\Knowledge\CourseRegistry;
use TT\Modules\Knowledge\KnowledgePerson;
use TT\Modules\Knowledge\Repositories\EnrolmentRepository;
use TT\Shared\Frontend\Components\CrossViewLink;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Content\GateVerdict;

/**
 * FrontendKnowledgeLibraryView — the course catalogue.
 *
 * One card per course the reader may see, ordered so the work in front of
 * them comes first: in progress, then not started, then locked, then done.
 * A library that sorts alphabetically makes a coach hunt for the course
 * they are halfway through.
 *
 * Courses this install cannot have, and courses this reader may not open,
 * are absent — the resolver decides that, not this view (#2645).
 */
class FrontendKnowledgeLibraryView extends FrontendViewBase {

    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        KnowledgeAssets::enqueue();
    }

    public static function render( int $user_id, bool $is_admin ): void {
        $title = __( 'Knowledge library', 'talenttrack' );

        if ( ! current_user_can( 'tt_view_knowledge' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to this section.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        FrontendBreadcrumbs::fromDashboard( $title );
        self::renderHeader( $title );

        $person_id  = KnowledgePerson::forUser( $user_id );
        $access     = new CourseAccessResolver();
        $completion = new CourseCompletionService();
        $enrolments = new EnrolmentRepository();

        $cards = [];
        foreach ( CourseRegistry::all() as $slug => $manifest ) {
            $verdict = $access->forCourse( $slug, $person_id, $user_id );
            if ( ! $verdict->isListable() ) {
                continue;
            }

            $enrolment = $person_id > 0 ? $enrolments->findFor( $person_id, $slug ) : null;
            $progress  = $enrolment !== null
                ? $completion->progressFor( (int) $enrolment->id, $slug )
                : [ 'completed' => 0, 'total' => count( $manifest->lessonSlugs() ), 'percent' => 0 ];

            $cards[] = [
                'slug'      => $slug,
                'manifest'  => $manifest,
                'verdict'   => $verdict,
                'enrolment' => $enrolment,
                'progress'  => $progress,
                'rank'      => self::sortRank( $verdict, $enrolment ),
            ];
        }

        if ( $cards === [] ) {
            echo '<p class="tt-notice">' . esc_html__( 'No courses are available on this academy yet.', 'talenttrack' ) . '</p>';
            return;
        }

        usort( $cards, static function ( array $a, array $b ): int {
            return $a['rank'] <=> $b['rank']
                ?: strcasecmp( $a['manifest']->title(), $b['manifest']->title() );
        } );

        if ( $person_id <= 0 ) {
            echo '<p class="tt-notice">'
                . esc_html__( 'Your login is not linked to a staff record, so progress cannot be saved. You can still read every course.', 'talenttrack' )
                . '</p>';
        }

        echo '<ul class="tt-knowledge-grid">';
        foreach ( $cards as $card ) {
            self::renderCard( $card );
        }
        echo '</ul>';
    }

    /**
     * Sort key: what the reader should pick up first.
     *
     * In progress before not started, because resuming is the common
     * case; completed last, because it is a record rather than work.
     *
     * @param object|null $enrolment
     */
    private static function sortRank( GateVerdict $verdict, ?object $enrolment ): int {
        if ( $verdict->isLocked() ) {
            return 3;
        }

        if ( $enrolment === null ) {
            return 2;
        }

        return (string) $enrolment->status === EnrolmentRepository::STATUS_COMPLETED ? 4 : 1;
    }

    /**
     * @param array{slug:string, manifest:CourseManifest, verdict:GateVerdict, enrolment:object|null, progress:array{completed:int,total:int,percent:int}} $card
     */
    private static function renderCard( array $card ): void {
        $manifest = $card['manifest'];
        $verdict  = $card['verdict'];
        $locked   = $verdict->isLocked();
        $progress = $card['progress'];

        $url = KnowledgeLinks::course( $card['slug'] );

        echo '<li class="tt-knowledge-card' . ( $locked ? ' tt-knowledge-card--locked' : '' ) . '">';

        echo '<h3 class="tt-knowledge-card__title">';
        if ( $locked ) {
            echo esc_html( $manifest->title() );
        } else {
            CrossViewLink::render( 'course', static function () use ( $url, $manifest ): void {
                echo '<a href="' . esc_url( $url ) . '">' . esc_html( $manifest->title() ) . '</a>';
            } );
        }
        echo '</h3>';

        echo '<p class="tt-knowledge-card__summary">' . esc_html( $manifest->summary() ) . '</p>';

        echo '<p class="tt-knowledge-card__meta">';
        echo esc_html( sprintf(
            /* translators: %d: number of lessons */
            _n( '%d lesson', '%d lessons', count( $manifest->lessonSlugs() ), 'talenttrack' ),
            count( $manifest->lessonSlugs() )
        ) );
        if ( $manifest->estimatedHours() > 0 ) {
            echo ' · ' . esc_html( sprintf(
                /* translators: %d: estimated study hours */
                _n( '%d hour', '%d hours', $manifest->estimatedHours(), 'talenttrack' ),
                $manifest->estimatedHours()
            ) );
        }
        echo '</p>';

        KnowledgeChrome::renderStateChip( $verdict, $card['enrolment'] );

        if ( $locked ) {
            echo '<p class="tt-knowledge-card__locked">' . esc_html( KnowledgeChrome::lockReason( $verdict ) ) . '</p>';
        } else {
            KnowledgeChrome::renderProgressBar( $progress );
            $label = self::ctaLabel( $card['enrolment'] );
            CrossViewLink::render( 'course', static function () use ( $url, $label ): void {
                echo '<p class="tt-knowledge-card__cta"><a class="tt-btn tt-btn-secondary" href="' . esc_url( $url ) . '">'
                    . esc_html( $label )
                    . '</a></p>';
            } );
        }

        echo '</li>';
    }

    /** @param object|null $enrolment */
    private static function ctaLabel( ?object $enrolment ): string {
        if ( $enrolment === null ) {
            return __( 'Start', 'talenttrack' );
        }

        if ( (string) $enrolment->status === EnrolmentRepository::STATUS_COMPLETED ) {
            return __( 'Review', 'talenttrack' );
        }

        return __( 'Continue', 'talenttrack' );
    }
}
