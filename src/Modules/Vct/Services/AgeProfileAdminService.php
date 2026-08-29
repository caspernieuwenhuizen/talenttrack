<?php
namespace TT\Modules\Vct\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Vct\Repositories\VctAgeProfilesRepository;
use TT\Modules\Vct\Repositories\VctSessionTemplatesRepository;

/**
 * AgeProfileAdminService (#2601) — add and remove an age profile.
 *
 * The decisions live here rather than in the REST controller or the
 * config view, so the two surfaces cannot answer differently and a
 * non-WordPress front end gets the same behaviour.
 *
 * ## Adding also copies a session blueprint
 *
 * A profile alone does not make the generator work. The profile supplies
 * the load ceiling (`AgeAdmissibilityRule`, pass 1); the session template
 * supplies the shape of the training (`SessionCompositionRule`, pass 3).
 * An academy that added U15 and only got a profile would clear the first
 * block and hit the second, with a message pointing at a surface that
 * does not exist.
 *
 * So creating a profile copies the session templates of the **nearest**
 * age group that has them — U15 inherits U14's shape, not U10's. The
 * blueprint is a starting point; the numbers that carry the age safety
 * are the ones the operator just typed, and the rule pipeline applies
 * them over the top.
 *
 * ## Removing is refused while a team is in that age group
 *
 * Deleting a profile silently disables the generator for every team in
 * that band, and a coach hitting "this age group has no profile" the
 * following week would have no way to connect it to a click on the
 * configuration screen. So the delete is refused while a live team is in
 * the band, and says how many.
 *
 * The alternative — orphan the drafts — is not on the table, because
 * there is nothing to orphan: a saved plan carries its own blocks, and
 * the profile is read at draft time. Removing a profile changes what can
 * be drafted next, never what was drafted before.
 */
final class AgeProfileAdminService {

    private VctAgeProfilesRepository $profiles;
    private VctSessionTemplatesRepository $templates;

    public function __construct(
        ?VctAgeProfilesRepository $profiles = null,
        ?VctSessionTemplatesRepository $templates = null
    ) {
        $this->profiles  = $profiles  ?? new VctAgeProfilesRepository();
        $this->templates = $templates ?? new VctSessionTemplatesRepository();
    }

    /**
     * @param array<string,mixed> $data
     * @return array{id: int, templates_copied: int, error: string}
     */
    public function create( array $data ): array {
        $age_group = trim( (string) ( $data['age_group'] ?? '' ) );

        if ( $age_group === '' ) {
            return [ 'id' => 0, 'templates_copied' => 0, 'error' => __( 'Pick an age group.', 'talenttrack' ) ];
        }
        if ( $this->profiles->findByAgeGroup( $age_group ) !== null ) {
            return [
                'id'               => 0,
                'templates_copied' => 0,
                'error'            => sprintf(
                    /* translators: %s is an age group, e.g. U15. */
                    __( '%s already has a profile. Edit that one instead.', 'talenttrack' ),
                    $age_group
                ),
            ];
        }

        $id = $this->profiles->create( $data );
        if ( $id <= 0 ) {
            return [ 'id' => 0, 'templates_copied' => 0, 'error' => __( 'The age profile could not be saved.', 'talenttrack' ) ];
        }

        $source = $this->nearestAgeGroupWithTemplates( $age_group );
        $copied = $source !== null ? $this->templates->copyAgeGroup( $source, $age_group ) : 0;

        return [ 'id' => $id, 'templates_copied' => $copied, 'error' => '' ];
    }

    /**
     * @return array{deleted: bool, error: string}
     */
    public function delete( int $id ): array {
        $profile = $this->profiles->findById( $id );
        if ( $profile === null ) {
            return [ 'deleted' => false, 'error' => __( 'That age profile no longer exists.', 'talenttrack' ) ];
        }

        $age_group = (string) $profile['age_group'];
        $in_use    = $this->teamsInAgeGroup( $age_group );
        if ( $in_use > 0 ) {
            return [
                'deleted' => false,
                'error'   => sprintf(
                    /* translators: 1: number of teams, 2: age group, e.g. U13. */
                    _n(
                        'Cannot remove this profile: %1$d team is in %2$s and would lose automatic training drafts.',
                        'Cannot remove this profile: %1$d teams are in %2$s and would lose automatic training drafts.',
                        $in_use,
                        'talenttrack'
                    ),
                    $in_use,
                    $age_group
                ),
            ];
        }

        if ( ! $this->profiles->delete( $id ) ) {
            return [ 'deleted' => false, 'error' => __( 'The age profile could not be removed.', 'talenttrack' ) ];
        }

        return [ 'deleted' => true, 'error' => '' ];
    }

    /**
     * Nearest age group that already has session templates, measured on
     * the age ordinal. Ties break downwards — a new U15 takes U14's shape
     * rather than U16's, because the younger neighbour is the more
     * conservative starting point where load is concerned.
     */
    private function nearestAgeGroupWithTemplates( string $age_group ): ?string {
        $target = AgeProfileCoverage::ordinal( $age_group );
        if ( $target === PHP_INT_MAX ) $target = 99;

        $best          = null;
        $best_distance = null;
        foreach ( $this->templates->ageGroupsWithTemplates() as $candidate ) {
            $ordinal = AgeProfileCoverage::ordinal( $candidate );
            if ( $ordinal === PHP_INT_MAX ) continue;

            $distance = abs( $ordinal - $target );
            if ( $best_distance === null
                || $distance < $best_distance
                || ( $distance === $best_distance && $ordinal < AgeProfileCoverage::ordinal( (string) $best ) )
            ) {
                $best          = $candidate;
                $best_distance = $distance;
            }
        }

        return $best;
    }

    private function teamsInAgeGroup( string $age_group ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_teams
              WHERE club_id = %d AND age_group = %s AND archived_at IS NULL",
            CurrentClub::id(),
            $age_group
        ) );
    }
}
