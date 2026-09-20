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
        [ $team, $squad ] = $this->generateTeamWithMedia( 'Utrecht JO16-1', 'JO16' );

        $unconsented = 0;
        foreach ( $squad as $player ) {
            if ( (int) $player->media_consent === 1 ) continue;
            $unconsented++;

            $this->assertSame(
                [],
                $this->ownPhotosOf( (int) $player->id ),
                "player {$player->id} has no media consent but has a photo of their own"
            );
        }

        $this->assertGreaterThan( 0, $unconsented, 'the fixture produced nobody without consent' );
    }

    public function test_a_consented_player_does_get_a_portrait(): void {
        [ $team, $squad ] = $this->generateTeamWithMedia( 'Twente JO17-1', 'JO17' );

        $with_portrait = 0;
        foreach ( $squad as $player ) {
            if ( (int) $player->media_consent !== 1 ) continue;
            if ( $this->ownPhotosOf( (int) $player->id ) ) $with_portrait++;
        }

        $this->assertGreaterThan( 0, $with_portrait, 'no consented player got a portrait — the demo shows nothing' );
    }

    /**
     * The counter-intuitive half of the locked decision. An unconsented
     * player stays on the squad photo, because mixed consent on one image
     * is the case the consent surfaces were built to handle.
     */
    public function test_the_squad_photo_keeps_at_least_one_unconsented_player(): void {
        [ $team, $squad ] = $this->generateTeamWithMedia( 'Vitesse JO19-1', 'JO19' );

        $team_photo_ids = $this->mediaLinkedTo( MediaEntityType::TEAM, (int) $team->id );
        $this->assertNotEmpty( $team_photo_ids, 'the fixture produced no squad photo' );

        $co_depicted_without_consent = 0;
        foreach ( $squad as $player ) {
            if ( (int) $player->media_consent === 1 ) continue;
            $linked = $this->mediaLinkedTo( MediaEntityType::PLAYER, (int) $player->id );
            if ( array_intersect( $linked, $team_photo_ids ) ) $co_depicted_without_consent++;
        }

        $this->assertGreaterThan(
            0,
            $co_depicted_without_consent,
            'no unconsented player is on the squad photo — the co-depiction case #3804 exists for is not demonstrated'
        );
    }

    // ── media helpers ──────────────────────────────────────────────────

    /**
     * @return array{0:object, 1:object[]} the team row and its squad
     */
    private function generateTeamWithMedia( string $name, string $age_group ): array {
        if ( ! function_exists( 'imagecreatetruecolor' ) ) {
            $this->markTestSkipped( 'GD is not available; MediaGenerator draws its placeholders with it.' );
        }

        $team = $this->makeTeam( $name, $age_group, 99 );
        $this->runPlayers( [ $team ], 12, 'media-consent-batch' );
        $squad = $this->squad( (int) $team->id );

        $media = new MediaGenerator(
            new DemoBatchRegistry( 'media-consent-batch' ),
            [ $team ],
            $squad,
            'en_US'
        );
        $written = $media->generate();
        if ( $written === 0 ) {
            $this->markTestSkipped( 'MediaGenerator wrote nothing — no image store available in this environment.' );
        }

        return [ $team, $squad ];
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
