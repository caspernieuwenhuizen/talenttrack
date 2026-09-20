<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\Generators\MediaGenerator;
use TT\Modules\DemoData\Generators\PlayerGenerator;
use TT\Modules\Media\MediaEntityType;

/**
 * #3846 — the demo academy used to be internally contradictory about media
 * consent. No generator wrote `media_consent`, so every demo player fell back
 * to the column default of 0 — no consent on record — while `MediaGenerator`
 * linked a portrait to the first three players of every team regardless.
 * Photos on file for children whose record says nobody ever agreed to them,
 * and no player anywhere demonstrating the consented case.
 *
 * What is pinned here:
 *
 *   - every squad carries both states, with provenance only alongside a yes;
 *   - a repeat run produces the same assignment (`run_order` is a
 *     reproducibility contract);
 *   - no portrait of an unconsented child;
 *   - the squad photo keeps its unconsented players. That is the
 *     counter-intuitive half and the one a later "tidy-up" would undo: one
 *     image depicting children of mixed consent is the co-depiction case
 *     epic #2589 decided on (D5) and the case #3804's surfaces exist to
 *     display.
 */
final class DemoMediaConsentTest extends WP_UnitTestCase {

    private string $p;
    private int $club;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
    }

    // ── fixtures ───────────────────────────────────────────────────────

    /** @return object a team row shaped the way DemoGenerator hands them over */
    private function makeTeam( string $name, string $age_group, int $coach_id ): object {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [
            'club_id'   => $this->club,
            'name'      => $name,
            'age_group' => $age_group,
        ] );
        return (object) [
            'id'                 => (int) $wpdb->insert_id,
            'name'               => $name,
            'age_group'          => $age_group,
            'head_coach_user_id' => $coach_id,
        ];
    }

    /**
     * @param object[] $teams
     * @return object[] the generator's own return
     */
    private function runPlayers( array $teams, int $per_team, string $batch ): array {
        $gen = new PlayerGenerator(
            new DemoBatchRegistry( $batch ),
            $teams,
            [ 'admin' => 1 ],
            $per_team,
            8
        );
        return $gen->generate();
    }

    /**
     * The squad in insertion order, which is the order the consent rule is
     * positional against.
     *
     * @return object[]
     */
    private function squad( int $team_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->p}tt_players
              WHERE club_id = %d AND team_id = %d AND status = 'active'
              ORDER BY id",
            $this->club,
            $team_id
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /** @param object[] $squad @return int[] */
    private function consentPattern( array $squad ): array {
        $out = [];
        foreach ( $squad as $player ) {
            $out[] = (int) $player->media_consent;
        }
        return $out;
    }

    // ── the consent assignment ─────────────────────────────────────────

    public function test_every_squad_carries_both_consent_states(): void {
        $team = $this->makeTeam( 'Ajax JO13-1', 'JO13', 77 );
        $this->runPlayers( [ $team ], 12, 'consent-batch' );

        $squad = $this->squad( (int) $team->id );
        $this->assertCount( 12, $squad );

        $consented   = array_filter( $squad, static fn( $p ) => (int) $p->media_consent === 1 );
        $unconsented = array_filter( $squad, static fn( $p ) => (int) $p->media_consent === 0 );

        $this->assertNotEmpty( $consented, 'no consented player — the field can never be seen doing anything' );
        $this->assertNotEmpty( $unconsented, 'no unconsented player — the dossier gap cannot be demonstrated' );
    }

    public function test_provenance_is_written_only_alongside_a_yes(): void {
        $team = $this->makeTeam( 'Feyenoord JO15-1', 'JO15', 88 );
        $this->runPlayers( [ $team ], 12, 'consent-batch' );

        $saw_full_provenance = false;
        foreach ( $this->squad( (int) $team->id ) as $player ) {
            if ( (int) $player->media_consent === 1 ) {
                $this->assertNotNull( $player->media_consent_at, 'consent with no date on record' );
                $this->assertNotNull( $player->media_consent_by, 'consent with nobody recorded as taking it' );
                $this->assertSame( 88, (int) $player->media_consent_by, 'the team head coach records it' );
                $saw_full_provenance = true;
                continue;
            }
            $this->assertNull( $player->media_consent_at, 'a date on record under "no consent"' );
            $this->assertNull( $player->media_consent_by, 'a recorder on record under "no consent"' );
        }

        $this->assertTrue( $saw_full_provenance, 'no player carried a complete consent record' );
    }

    public function test_the_assignment_is_positional_so_a_reseed_reproduces_it(): void {
        $first_team  = $this->makeTeam( 'PSV JO14-1', 'JO14', 11 );
        $second_team = $this->makeTeam( 'PSV JO14-2', 'JO14', 12 );

        $this->runPlayers( [ $first_team ], 12, 'run-one' );
        $this->runPlayers( [ $second_team ], 12, 'run-two' );

        $one = $this->consentPattern( $this->squad( (int) $first_team->id ) );
        $two = $this->consentPattern( $this->squad( (int) $second_team->id ) );

        $this->assertSame( $one, $two, 'two runs of the same squad size disagreed — the assignment is not reproducible' );
        $this->assertSame(
            [ 1, 1, 1, 1, 0, 1, 1, 1, 1, 0, 1, 1 ],
            $one,
            'every fifth player is the unconsented one'
        );
    }

    public function test_a_squad_too_small_for_every_fifth_still_carries_both(): void {
        $team = $this->makeTeam( 'AZ JO11-1', 'JO11', 21 );
        $this->runPlayers( [ $team ], 3, 'small-squad' );

        $this->assertSame(
            [ 1, 1, 0 ],
            $this->consentPattern( $this->squad( (int) $team->id ) ),
            'a squad under five lost its unconsented case entirely'
        );
    }

    // ── what the media generator does with it ──────────────────────────

    /**
     * The acceptance criterion, walked player by player: a child with no
     * consent on record has no photo of their own.
     */
    public function test_no_portrait_is_taken_of_a_player_without_consent(): void {
        [ $squad, $subjects ] = $this->squadAndPortraitSubjects( 'Utrecht JO16-1', 'JO16' );

        $subject_ids = $this->idsOf( $subjects );

        $unconsented = 0;
        foreach ( $squad as $player ) {
            if ( (int) $player->media_consent === 1 ) continue;
            $unconsented++;

            $this->assertNotContains(
                (int) $player->id,
                $subject_ids,
                "player {$player->id} has no media consent but is a portrait subject"
            );
        }

        $this->assertGreaterThan( 0, $unconsented, 'the fixture produced nobody without consent' );
    }

    public function test_a_consented_player_does_get_a_portrait(): void {
        [ $squad, $subjects ] = $this->squadAndPortraitSubjects( 'Twente JO17-1', 'JO17' );

        $this->assertNotEmpty( $subjects, 'no consented player is a portrait subject — the demo shows nothing' );

        $consented_ids = [];
        foreach ( $squad as $player ) {
            if ( (int) $player->media_consent === 1 ) $consented_ids[] = (int) $player->id;
        }

        foreach ( $this->idsOf( $subjects ) as $id ) {
            $this->assertContains( $id, $consented_ids );
        }
    }

    /**
     * The counter-intuitive half of the locked decision. An unconsented
     * player stays on the squad photo, because mixed consent on one image
     * is the case the consent surfaces were built to handle.
     */
    public function test_the_squad_photo_keeps_at_least_one_unconsented_player(): void {
        $team  = $this->makeTeam( 'Vitesse JO19-1', 'JO19', 99 );
        $this->runPlayers( [ $team ], 12, 'media-consent-batch' );
        $squad = $this->squad( (int) $team->id );

        $depicted = $this->idsOf( $this->mediaGeneratorFor( $team, $squad )->squadPhotoSubjects( $squad ) );

        $without_consent = 0;
        foreach ( $squad as $player ) {
            if ( (int) $player->media_consent === 1 ) continue;
            if ( in_array( (int) $player->id, $depicted, true ) ) $without_consent++;
        }

        $this->assertGreaterThan(
            0,
            $without_consent,
            'no unconsented player is on the squad photo — the co-depiction case #3804 exists for is not demonstrated'
        );
    }

    /**
     * The same walk, end to end, against the links the generator actually
     * wrote. Skipped where GD cannot write a JPEG — the generator draws its
     * placeholders rather than shipping them, so on such an install it
     * creates no images at all and there is nothing to walk. The selection
     * tests above cover the rule itself in either environment.
     */
    public function test_the_written_links_match_the_selection(): void {
        if ( ! function_exists( 'imagejpeg' ) ) {
            $this->markTestSkipped( 'GD cannot write a JPEG here, so MediaGenerator draws no placeholders.' );
        }

        $team = $this->makeTeam( 'Heerenveen JO18-1', 'JO18', 99 );
        $this->runPlayers( [ $team ], 12, 'media-consent-batch' );
        $squad = $this->squad( (int) $team->id );
        $this->assertCount( 12, $squad, 'the fixture squad did not generate' );

        $this->mediaGeneratorFor( $team, $squad )->generate();

        // The squad photo is the subject of this test, and it only exists
        // if the placeholder could be drawn and stored. Where it could not,
        // there is nothing to walk and the selection tests above are the
        // coverage; failing here would report an environment as a bug.
        if ( $this->storedImages() === 0 ) {
            $this->markTestSkipped( 'No placeholder image could be stored in this environment.' );
        }

        $team_photo_ids = $this->mediaLinkedTo( MediaEntityType::TEAM, (int) $team->id );
        $this->assertNotEmpty( $team_photo_ids, 'the fixture produced no team media at all' );

        $co_depicted = 0;
        foreach ( $squad as $player ) {
            if ( (int) $player->media_consent === 1 ) continue;

            $this->assertSame(
                [],
                $this->ownPhotosOf( (int) $player->id ),
                "player {$player->id} has no media consent but has a photo of their own"
            );

            $linked = $this->mediaLinkedTo( MediaEntityType::PLAYER, (int) $player->id );
            if ( array_intersect( $linked, $team_photo_ids ) ) $co_depicted++;
        }

        $this->assertGreaterThan( 0, $co_depicted, 'the squad photo lost its unconsented players' );
    }

    // ── media helpers ──────────────────────────────────────────────────

    /**
     * @param object[] $squad
     */
    private function mediaGeneratorFor( object $team, array $squad ): MediaGenerator {
        return new MediaGenerator(
            new DemoBatchRegistry( 'media-consent-batch' ),
            [ $team ],
            $squad,
            'en_US'
        );
    }

    /**
     * @return array{0:object[], 1:object[]} the squad, and the players a
     *   portrait would be taken of
     */
    private function squadAndPortraitSubjects( string $name, string $age_group ): array {
        $team  = $this->makeTeam( $name, $age_group, 99 );
        $this->runPlayers( [ $team ], 12, 'media-consent-batch' );
        $squad = $this->squad( (int) $team->id );

        $subjects = array_slice( $this->mediaGeneratorFor( $team, $squad )->consentedIn( $squad ), 0, 3 );

        return [ $squad, $subjects ];
    }

    /**
     * @param object[] $players
     * @return int[]
     */
    private function idsOf( array $players ): array {
        $out = [];
        foreach ( $players as $player ) {
            $out[] = (int) ( $player->id ?? 0 );
        }
        return $out;
    }

    /** How many image rows the generator managed to store. */
    private function storedImages(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->p}tt_media WHERE kind = 'image'"
        );
    }

    /** @return int[] media ids linked to one entity */
    private function mediaLinkedTo( string $entity_type, int $entity_id ): array {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT media_id FROM {$this->p}tt_media_links
              WHERE entity_type = %s AND entity_id = %d",
            $entity_type,
            $entity_id
        ) );
        return array_map( 'intval', (array) $ids );
    }

    /**
     * Media linked to this player and to no team — a photo of them alone,
     * which is what "portrait" means here. The squad photo is excluded
     * precisely because it is linked to the team as well.
     *
     * @return int[]
     */
    private function ownPhotosOf( int $player_id ): array {
        $out = [];
        foreach ( $this->mediaLinkedTo( MediaEntityType::PLAYER, $player_id ) as $media_id ) {
            if ( $this->isLinkedToATeam( $media_id ) ) continue;
            $out[] = $media_id;
        }
        return $out;
    }

    private function isLinkedToATeam( int $media_id ): bool {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->p}tt_media_links
              WHERE media_id = %d AND entity_type = %s",
            $media_id,
            MediaEntityType::TEAM
        ) ) > 0;
    }
}
