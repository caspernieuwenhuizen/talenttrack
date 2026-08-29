<?php
namespace TT\Tests\Php;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Vct\Repositories\VctAgeProfilesRepository;
use TT\Modules\Vct\Repositories\VctSessionTemplatesRepository;
use TT\Modules\Vct\Rules\AgeAdmissibilityRule;
use TT\Modules\Vct\Rules\SessionPlanContext;
use TT\Modules\Vct\Services\AgeProfileAdminService;
use TT\Modules\Vct\Services\AgeProfileCoverage;
use WP_UnitTestCase;

/**
 * #2601 — the training generator could only draft for U10-U14, and there
 * was no way to add a sixth age profile.
 *
 * Two things are pinned here.
 *
 * **The boundary is derived.** "Below the modelled range" has to follow
 * the profiles that exist, not a hardcoded list of age-group slugs —
 * otherwise the day an academy adds a U9 profile, the copy keeps telling
 * its coaches U9 has no load model and the code contradicts the database.
 *
 * **Adding a profile is enough on its own.** The profile clears pass 1;
 * the session template clears pass 3. An add path that only wrote the
 * profile would move the wall rather than remove it, so creation copies
 * the nearest age group's blueprint.
 */
final class VctAgeProfileCoverageTest extends WP_UnitTestCase {

    private VctAgeProfilesRepository $profiles;
    private VctSessionTemplatesRepository $templates;

    public function set_up(): void {
        parent::set_up();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $this->profiles  = new VctAgeProfilesRepository();
        $this->templates = new VctSessionTemplatesRepository();

        // Own the whole vocabulary for this test: the seeded U10-U14 rows
        // would otherwise decide where the floor is.
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'tt_vct_age_profiles', [ 'club_id' => (int) CurrentClub::id() ] );
        $wpdb->delete( $wpdb->prefix . 'tt_vct_session_templates', [ 'club_id' => (int) CurrentClub::id() ] );
    }

    /* ---- the derived boundary ------------------------------------------- */

    public function test_the_floor_follows_the_profiles_that_exist(): void {
        $this->seedProfile( 'U12' );
        $this->seedProfile( 'U14' );

        $this->assertTrue( AgeProfileCoverage::isBelowModelledRange( 'U9', $this->profiles ) );
        $this->assertFalse( AgeProfileCoverage::isBelowModelledRange( 'U15', $this->profiles ) );
    }

    /** Adding a younger profile moves the floor; nothing else changes. */
    public function test_adding_a_younger_profile_moves_the_floor(): void {
        $this->seedProfile( 'U12' );
        $this->assertTrue( AgeProfileCoverage::isBelowModelledRange( 'U9', $this->profiles ) );

        $this->seedProfile( 'U9' );

        $this->assertFalse(
            AgeProfileCoverage::isBelowModelledRange( 'U9', $this->profiles ),
            'the copy must follow the configuration, not a hardcoded list of age groups'
        );
    }

    /** A hole inside the range is missing, not unmodelled. */
    public function test_a_gap_above_the_floor_is_not_below_the_range(): void {
        $this->seedProfile( 'U10' );
        $this->seedProfile( 'U14' );

        $this->assertFalse( AgeProfileCoverage::isBelowModelledRange( 'U12', $this->profiles ) );
    }

    /** A senior squad is unconfigured, never "too young". */
    public function test_a_non_numeric_age_group_is_never_below_the_range(): void {
        $this->seedProfile( 'U12' );

        $this->assertFalse( AgeProfileCoverage::isBelowModelledRange( 'Senior', $this->profiles ) );
    }

    /* ---- the two block messages ----------------------------------------- */

    public function test_an_age_below_the_range_blocks_with_the_by_design_code(): void {
        $this->seedProfile( 'U12' );

        $warning = $this->firstWarningFor( 'U8' );

        $this->assertSame( 'age_below_modelled_range', $warning['code'] );
        $this->assertSame( 'block', $warning['severity'], 'age safety is never inferred from a default' );
    }

    public function test_an_age_above_the_range_blocks_with_the_actionable_code(): void {
        $this->seedProfile( 'U12' );

        $warning = $this->firstWarningFor( 'U17' );

        $this->assertSame( 'missing_age_profile', $warning['code'] );
        $this->assertSame( 'block', $warning['severity'] );
    }

    /* ---- the add path ---------------------------------------------------- */

    public function test_adding_a_profile_copies_the_nearest_session_blueprint(): void {
        $this->seedProfile( 'U14' );
        $this->seedTemplate( 'U14', 'NONE' );
        $this->seedTemplate( 'U10', 'NONE' );

        $result = ( new AgeProfileAdminService( $this->profiles, $this->templates ) )->create( [
            'age_group'            => 'U15',
            'session_minutes_max'  => 90,
            'intensity_band_max'   => 7,
            'weekly_load_envelope' => 1890,
        ] );

        $this->assertGreaterThan( 0, $result['id'] );
        $this->assertSame( 1, $result['templates_copied'] );

        $template = $this->templates->findFor( 'U15', 'NONE' );
        $this->assertNotNull( $template, 'a profile without a blueprint just moves the block one pass later' );
        $this->assertSame( 100, $template['total_duration_minutes_target'], 'U15 takes U14 shape, not U10' );
    }

    /** The whole point: the generator works for the new age group. */
    public function test_a_new_profile_unblocks_the_age_rule(): void {
        $this->seedProfile( 'U14' );
        $this->seedTemplate( 'U14', 'NONE' );

        ( new AgeProfileAdminService( $this->profiles, $this->templates ) )->create( [
            'age_group'            => 'U15',
            'session_minutes_max'  => 90,
            'intensity_band_max'   => 7,
            'weekly_load_envelope' => 1890,
        ] );

        $ctx = $this->runAgeRule( 'U15' );

        $this->assertSame( [], $ctx->warnings );
        $this->assertSame( 90, $ctx->session_minutes_max );
        $this->assertSame( 7, $ctx->intensity_band_max );
    }

    public function test_a_duplicate_age_group_is_refused_with_a_reason(): void {
        $this->seedProfile( 'U14' );

        $result = ( new AgeProfileAdminService( $this->profiles, $this->templates ) )->create( [
            'age_group'           => 'U14',
            'session_minutes_max' => 90,
            'intensity_band_max'  => 7,
        ] );

        $this->assertSame( 0, $result['id'] );
        $this->assertNotSame( '', $result['error'] );
    }

    /* ---- the delete guard ------------------------------------------------ */

    public function test_a_profile_a_live_team_depends_on_cannot_be_removed(): void {
        $id = $this->seedProfile( 'U14' );
        $this->seedTeam( 'U14' );

        $result = ( new AgeProfileAdminService( $this->profiles, $this->templates ) )->delete( $id );

        $this->assertFalse( $result['deleted'] );
        $this->assertNotSame( '', $result['error'] );
        $this->assertNotNull( $this->profiles->findById( $id ) );
    }

    public function test_an_unused_profile_can_be_removed(): void {
        $id = $this->seedProfile( 'U19' );

        $result = ( new AgeProfileAdminService( $this->profiles, $this->templates ) )->delete( $id );

        $this->assertTrue( $result['deleted'] );
        $this->assertNull( $this->profiles->findById( $id ) );
    }

    /* ---- helpers -------------------------------------------------------- */

    private function seedProfile( string $age_group ): int {
        return $this->profiles->create( [
            'age_group'            => $age_group,
            'session_minutes_max'  => 80,
            'intensity_band_max'   => 6,
            'weekly_load_envelope' => 1440,
        ] );
    }

    private function seedTemplate( string $age_group, string $md_context ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_vct_session_templates', [
            'club_id'                       => (int) CurrentClub::id(),
            'uuid'                          => wp_generate_uuid4(),
            'age_group'                     => $age_group,
            'md_context'                    => $md_context,
            'slots_json'                    => (string) wp_json_encode( [ [ 'category' => 'warmup' ] ] ),
            // Distinct per age group so the "nearest" assertion can tell
            // which one was copied.
            'total_duration_minutes_target' => $age_group === 'U14' ? 100 : 60,
        ] );
    }

    private function seedTeam( string $age_group ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [
            'club_id'   => (int) CurrentClub::id(),
            'name'      => 'Test ' . $age_group,
            'age_group' => $age_group,
        ] );
    }

    private function runAgeRule( string $age_group ): SessionPlanContext {
        $ctx = new SessionPlanContext();
        $ctx->age_group = $age_group;
        return ( new AgeAdmissibilityRule( $this->profiles ) )->apply( $ctx );
    }

    /** @return array{code:string,severity:string,details:array<string,mixed>} */
    private function firstWarningFor( string $age_group ): array {
        $warnings = $this->runAgeRule( $age_group )->warnings;
        $this->assertNotEmpty( $warnings, 'an age group with no profile must block' );
        return $warnings[0];
    }
}
