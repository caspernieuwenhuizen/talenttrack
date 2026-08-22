<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\PlayerStatus;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Media\MediaEntityType;
use TT\Modules\Media\MediaKind;
use TT\Modules\Media\Repositories\MediaLinksRepository;
use TT\Modules\Media\Repositories\MediaRepository;
use TT\Modules\Media\Retention\MediaRetentionService;

/**
 * #2666 (epic #2589) — media retention.
 *
 * The assertions here are the four decisions, restated as behaviour. Each
 * of them is the kind of thing a later refactor could quietly invert, and
 * each inversion either destroys a player's development record or keeps a
 * child's photograph longer than the academy told their family it would.
 */
final class MediaRetentionTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        QueryHelpers::set_config( MediaRetentionService::CONFIG_KEY, '3' );
    }

    public function tear_down(): void {
        QueryHelpers::set_config( MediaRetentionService::CONFIG_KEY, (string) MediaRetentionService::DEFAULT_YEARS );
        parent::tear_down();
    }

    // ── R4: the default, and the off switch ────────────────────────────

    public function test_a_period_ships_by_default(): void {
        QueryHelpers::set_config( MediaRetentionService::CONFIG_KEY, '' );

        $this->assertSame( 3, MediaRetentionService::years() );
        $this->assertTrue( MediaRetentionService::isEnabled() );
    }

    public function test_zero_means_keep_indefinitely(): void {
        QueryHelpers::set_config( MediaRetentionService::CONFIG_KEY, '0' );

        $this->assertFalse( MediaRetentionService::isEnabled() );
        $this->assertSame( [], ( new MediaRetentionService() )->candidates() );
    }

    /**
     * A negative or absurd period would expire everything the moment it
     * was saved, so it is clamped rather than trusted.
     */
    public function test_an_absurd_period_is_clamped(): void {
        QueryHelpers::set_config( MediaRetentionService::CONFIG_KEY, '-5' );
        $this->assertSame( 0, MediaRetentionService::years() );

        QueryHelpers::set_config( MediaRetentionService::CONFIG_KEY, '9999' );
        $this->assertSame( 50, MediaRetentionService::years() );
    }

    // ── R1: the clock starts when the player leaves ────────────────────

    /**
     * The decision that matters most. A photo from a player's first
     * season is not expired just because it is old — the player is still
     * here, and that longitudinal record is the point of the product.
     */
    public function test_a_current_players_old_media_never_expires(): void {
        $player = $this->makePlayer( PlayerStatus::ACTIVE );
        $this->attach( $this->makeMedia( 'Old but current' ), $player );

        // Ten years old, and irrelevant: they never left.
        $this->addDepartureEvent( $player, '-10 years', 'status_released' );

        $names = $this->candidateTitles();
        $this->assertNotContains( 'Old but current', $names );
    }

    public function test_a_departed_player_inside_the_period_is_not_yet_listed(): void {
        $player = $this->makePlayer( PlayerStatus::RELEASED );
        $this->attach( $this->makeMedia( 'Left recently' ), $player );
        $this->addDepartureEvent( $player, '-1 year', 'status_released' );

        $this->assertNotContains( 'Left recently', $this->candidateTitles() );
    }

    public function test_a_departed_player_past_the_period_is_listed(): void {
        $player = $this->makePlayer( PlayerStatus::RELEASED );
        $this->attach( $this->makeMedia( 'Long gone' ), $player );
        $this->addDepartureEvent( $player, '-4 years', 'status_released' );

        $this->assertContains( 'Long gone', $this->candidateTitles() );
    }

    public function test_graduating_counts_as_leaving(): void {
        $player = $this->makePlayer( PlayerStatus::GRADUATED );
        $this->attach( $this->makeMedia( 'Graduated' ), $player );
        $this->addDepartureEvent( $player, '-4 years', 'status_graduated' );

        $this->assertContains( 'Graduated', $this->candidateTitles() );
    }

    /**
     * Nothing is materialised, so a player who comes back simply stops
     * being a candidate — no stored expiry date to go stale.
     */
    public function test_a_returning_player_falls_out_of_the_queue(): void {
        global $wpdb;

        $player = $this->makePlayer( PlayerStatus::RELEASED );
        $this->attach( $this->makeMedia( 'Came back' ), $player );
        $this->addDepartureEvent( $player, '-4 years', 'status_released' );

        $this->assertContains( 'Came back', $this->candidateTitles() );

        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'status' => PlayerStatus::ACTIVE ], [ 'id' => $player ] );

        $this->assertNotContains( 'Came back', $this->candidateTitles() );
    }

    // ── R2: expiry acts on the attachment, not the item ────────────────

    /**
     * The co-depiction rule (epic D5) carried into retention. A squad
     * photo is not one departed player's to expire.
     */
    public function test_removing_an_expired_link_leaves_media_that_others_still_use(): void {
        $gone   = $this->makePlayer( PlayerStatus::RELEASED );
        $stays  = $this->makePlayer( PlayerStatus::ACTIVE );
        $this->addDepartureEvent( $gone, '-4 years', 'status_released' );

        $media = $this->makeMedia( 'Squad photo' );
        $this->attach( $media, $gone );
        $this->attach( $media, $stays );
        ( new MediaLinksRepository() )->link( $media, MediaEntityType::TEAM, 12345 );

        $service = new MediaRetentionService();
        $row     = $this->candidateFor( 'Squad photo' );
        $this->assertNotNull( $row );

        $result = $service->removeAttachment( (int) $row['link_id'] );

        $this->assertTrue( $result['removed'] );
        $this->assertFalse( $result['media_deleted'], 'the photo still belongs to the other player and the team' );
        $this->assertNotNull( ( new MediaRepository() )->find( $media ) );
    }

    public function test_removing_the_last_link_deletes_the_item(): void {
        $gone = $this->makePlayer( PlayerStatus::RELEASED );
        $this->addDepartureEvent( $gone, '-4 years', 'status_released' );

        $media = $this->makeMedia( 'Portrait' );
        $this->attach( $media, $gone );

        $row    = $this->candidateFor( 'Portrait' );
        $result = ( new MediaRetentionService() )->removeAttachment( (int) $row['link_id'] );

        $this->assertTrue( $result['media_deleted'] );
        $this->assertNull( ( new MediaRepository() )->find( $media ) );
    }

    /** Team and activity links belong to records that do not leave. */
    public function test_team_and_activity_links_never_expire(): void {
        $links = new MediaLinksRepository();

        $media = $this->makeMedia( 'Team only' );
        $links->link( $media, MediaEntityType::TEAM, 4242 );
        $links->link( $media, MediaEntityType::ACTIVITY, 4243 );

        $this->assertNotContains( 'Team only', $this->candidateTitles() );
    }

    // ── R3: nothing is deleted without a decision ──────────────────────

    public function test_listing_candidates_deletes_nothing(): void {
        $gone = $this->makePlayer( PlayerStatus::RELEASED );
        $this->addDepartureEvent( $gone, '-4 years', 'status_released' );
        $media = $this->makeMedia( 'Untouched' );
        $this->attach( $media, $gone );

        $service = new MediaRetentionService();
        $service->candidates();
        $service->candidates();
        $service->pendingCount();

        $this->assertNotNull(
            ( new MediaRepository() )->find( $media ),
            'reading the queue must never be destructive'
        );
    }

    public function test_a_held_attachment_leaves_the_queue_and_records_why(): void {
        $gone = $this->makePlayer( PlayerStatus::RELEASED );
        $this->addDepartureEvent( $gone, '-4 years', 'status_released' );
        $media = $this->makeMedia( 'Safeguarding matter' );
        $this->attach( $media, $gone );

        $service = new MediaRetentionService();
        $row     = $this->candidateFor( 'Safeguarding matter' );

        $this->assertTrue( $service->hold( (int) $row['link_id'], 'Open safeguarding case' ) );
        $this->assertNotContains( 'Safeguarding matter', $this->candidateTitles() );

        $held = $service->held();
        $this->assertCount( 1, $held );
        $this->assertSame( 'Open safeguarding case', $held[0]->retention_hold_reason );
    }

    /**
     * "We kept it" without a reason is indistinguishable from nobody
     * having looked, so the reason is required rather than optional.
     */
    public function test_a_hold_without_a_reason_is_refused(): void {
        $gone = $this->makePlayer( PlayerStatus::RELEASED );
        $this->addDepartureEvent( $gone, '-4 years', 'status_released' );
        $media = $this->makeMedia( 'No reason' );
        $this->attach( $media, $gone );

        $row = $this->candidateFor( 'No reason' );

        $this->assertFalse( ( new MediaRetentionService() )->hold( (int) $row['link_id'], '   ' ) );
        $this->assertContains( 'No reason', $this->candidateTitles() );
    }

    public function test_releasing_a_hold_puts_it_back(): void {
        $gone = $this->makePlayer( PlayerStatus::RELEASED );
        $this->addDepartureEvent( $gone, '-4 years', 'status_released' );
        $media = $this->makeMedia( 'Back again' );
        $this->attach( $media, $gone );

        $service = new MediaRetentionService();
        $row     = $this->candidateFor( 'Back again' );

        $service->hold( (int) $row['link_id'], 'Was under appeal' );
        $this->assertNotContains( 'Back again', $this->candidateTitles() );

        $service->releaseHold( (int) $row['link_id'] );
        $this->assertContains( 'Back again', $this->candidateTitles() );
    }

    // ── the estimated-date fallback ────────────────────────────────────

    /**
     * A player released before journey events recorded it has no dated
     * departure. Rather than exclude them for ever — which would make the
     * feature useless for exactly the academies with history — the row
     * appears flagged as estimated, and a human decides. Safe, because
     * nothing is deleted without one.
     */
    public function test_a_player_with_no_departure_event_is_flagged_as_estimated(): void {
        global $wpdb;

        $gone = $this->makePlayer( PlayerStatus::RELEASED );
        $this->attach( $this->makeMedia( 'No event recorded' ), $gone );

        // No journey event; push the row's own timestamp back instead.
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}tt_players SET updated_at = %s WHERE id = %d",
            gmdate( 'Y-m-d H:i:s', strtotime( '-4 years' ) ),
            $gone
        ) );

        $row = $this->candidateFor( 'No event recorded' );

        $this->assertNotNull( $row );
        $this->assertTrue( $row['estimated'], 'the weaker date must be labelled, not passed off as a fact' );
    }

    // ── helpers ────────────────────────────────────────────────────────

    /** @return list<string> */
    private function candidateTitles(): array {
        return array_column( ( new MediaRetentionService() )->candidates(), 'title' );
    }

    /** @return array<string,mixed>|null */
    private function candidateFor( string $title ): ?array {
        foreach ( ( new MediaRetentionService() )->candidates() as $row ) {
            if ( $row['title'] === $title ) return $row;
        }
        return null;
    }

    private function makeMedia( string $title ): int {
        return ( new MediaRepository() )->insert( [
            'kind'         => MediaKind::VIDEO_LINK,
            'title'        => $title,
            'provider'     => 'veo',
            'external_url' => 'https://app.veo.co/matches/test/',
        ] );
    }

    private function attach( int $media_id, int $player_id ): int {
        return ( new MediaLinksRepository() )->link( $media_id, MediaEntityType::PLAYER, $player_id );
    }

    private function makePlayer( string $status ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => 1,
            'first_name' => 'Test',
            'last_name'  => 'Player',
            'team_id'    => 0,
            'status'     => $status,
            'wp_user_id' => null,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function addDepartureEvent( int $player_id, string $when, string $type ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_player_events", [
            'club_id'            => 1,
            'uuid'               => wp_generate_uuid4(),
            'player_id'          => $player_id,
            'event_type'         => $type,
            'event_date'         => gmdate( 'Y-m-d H:i:s', strtotime( $when ) ),
            // NOT NULL with no default — omitting any of these fails
            // under strict mode.
            'summary'            => 'Test departure',
            'source_module'      => 'tests',
            'source_entity_type' => 'player',
        ] );
    }
}
