<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Modules\Activities\Repositories\ActivitiesRepository;
use TT\Modules\Media\MediaEntityType;
use TT\Modules\Vct\VctModule;

/**
 * #3376 — `tt_activity_deleted` had three subscribers and no publisher, so
 * every reference the subscribers exist to clear survived the delete.
 *
 * The bug was silent: nothing errored, rows simply drifted. Only an
 * assertion on the row *after* the delete catches a regression, so each
 * test reads the referencing row back rather than trusting a return value.
 *
 * Both directions matter. A publisher that fires on any call would be as
 * wrong as one that never fires — it would tear down the bindings of a
 * live activity whose delete matched nothing — so the no-op delete is
 * asserted too.
 */
final class ActivityDeletedEventTest extends WP_UnitTestCase {

    /** @var int */
    private $team_id;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        // The bin's lifecycle methods record an actor on every transition.
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Delete Event Team' ] );
        $this->team_id = (int) $wpdb->insert_id;
    }

    // ── the publisher ──────────────────────────────────────────────────

    public function test_hard_delete_announces_the_activity(): void {
        $activity_id = $this->activity();
        $seen        = [];
        $spy         = static function ( $id ) use ( &$seen ): void {
            $seen[] = (int) $id;
        };
        add_action( 'tt_activity_deleted', $spy, 10, 1 );

        ( new ActivitiesRepository() )->deleteWithAttendance( $activity_id );

        remove_action( 'tt_activity_deleted', $spy, 10 );
        $this->assertSame( [ $activity_id ], $seen );
    }

    /** A delete that removed nothing must not announce anything. */
    public function test_delete_of_a_missing_activity_announces_nothing(): void {
        $seen = 0;
        $spy  = static function () use ( &$seen ): void {
            $seen++;
        };
        add_action( 'tt_activity_deleted', $spy, 10, 1 );

        ( new ActivitiesRepository() )->deleteWithAttendance( 99999901 );

        remove_action( 'tt_activity_deleted', $spy, 10 );
        $this->assertSame( 0, $seen );
    }

    /**
     * The subscribers are registered, so the publisher reaches them.
     *
     * Media's two cleanup subscriptions used to sit inside `registerTiles()`
     * behind the media-retention enabled check, so on an install with
     * retention off they were never added at all and the publisher had
     * nothing to reach. This assertion is what catches that coming back.
     */
    public function test_the_subscribers_are_wired_to_the_action(): void {
        $this->assertNotFalse(
            has_action( 'tt_activity_deleted', [ VctModule::class, 'onActivityDeleted' ] ),
            'VCT no longer listens for the delete it cleans up after.'
        );
        $this->assertNotFalse(
            has_action( 'tt_activity_deleted', [ \TT\Modules\Media\MediaModule::class, 'onActivityDeleted' ] ),
            'Media no longer listens for the delete it cleans up after.'
        );
    }

    // ── what the subscribers do with it ────────────────────────────────

    public function test_bound_vct_session_is_unbound_and_reverted_to_draft(): void {
        global $wpdb;
        $activity_id = $this->activity();
        $other_id    = $this->activity();
        $bound       = $this->vctSession( $activity_id );
        $untouched   = $this->vctSession( $other_id );

        ( new ActivitiesRepository() )->deleteWithAttendance( $activity_id );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT activity_id, status FROM {$wpdb->prefix}tt_vct_sessions WHERE id = %d",
            $bound
        ) );
        $this->assertNotNull( $row, 'The session is preserved — only its binding goes.' );
        $this->assertNull( $row->activity_id );
        $this->assertSame( 'draft', (string) $row->status );

        $spared = $wpdb->get_row( $wpdb->prepare(
            "SELECT activity_id, status FROM {$wpdb->prefix}tt_vct_sessions WHERE id = %d",
            $untouched
        ) );
        $this->assertSame( $other_id, (int) $spared->activity_id, 'A session bound elsewhere was unbound.' );
        $this->assertSame( 'published', (string) $spared->status );
    }

    /**
     * #3426 — the path that was never covered. The recycle bin's purge
     * routes through the cascade plan, which nulls
     * `tt_vct_sessions.activity_id` on its way through, so a subscriber
     * that re-queried the binding after the delete found nothing and the
     * session stayed `published` — unbound, and stuck in a status the
     * coach could not leave.
     */
    public function test_purged_activity_unbinds_and_reverts_its_vct_session(): void {
        global $wpdb;
        $activity_id = $this->activity();
        $other_id    = $this->activity();
        $bound       = $this->vctSession( $activity_id );
        $untouched   = $this->vctSession( $other_id );

        $this->inBin( $activity_id );
        $deleted = ( new ArchiveRepository() )->purge( 'activity', [ $activity_id ], get_current_user_id() );

        $this->assertSame( 1, $deleted, 'the activity was purged out of the bin' );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT activity_id, status FROM {$wpdb->prefix}tt_vct_sessions WHERE id = %d",
            $bound
        ) );
        $this->assertNotNull( $row, 'The session is preserved — only its binding goes.' );
        $this->assertNull( $row->activity_id );
        $this->assertSame( 'draft', (string) $row->status, 'a purge leaves no session stuck on published' );

        $spared = $wpdb->get_row( $wpdb->prepare(
            "SELECT activity_id, status FROM {$wpdb->prefix}tt_vct_sessions WHERE id = %d",
            $untouched
        ) );
        $this->assertSame( $other_id, (int) $spared->activity_id, 'A session bound elsewhere was unbound.' );
        $this->assertSame( 'published', (string) $spared->status );
    }

    /**
     * `DELETE /activities/{id}/permanent` reaches the same cascade without
     * going through the bin, so it is asserted separately rather than
     * assumed to follow.
     */
    public function test_permanent_delete_unbinds_and_reverts_its_vct_session(): void {
        global $wpdb;
        $activity_id = $this->activity();
        $bound       = $this->vctSession( $activity_id );

        $deleted = ( new ArchiveRepository() )->deletePermanently( 'activity', [ $activity_id ] );

        $this->assertSame( 1, $deleted );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT activity_id, status FROM {$wpdb->prefix}tt_vct_sessions WHERE id = %d",
            $bound
        ) );
        $this->assertNotNull( $row );
        $this->assertNull( $row->activity_id );
        $this->assertSame( 'draft', (string) $row->status );
    }

    public function test_media_links_to_the_activity_are_removed(): void {
        global $wpdb;
        $activity_id = $this->activity();
        $other_id    = $this->activity();
        $media_id    = $this->media();

        $this->link( $media_id, MediaEntityType::ACTIVITY, $activity_id );
        $this->link( $media_id, MediaEntityType::ACTIVITY, $other_id );

        ( new ActivitiesRepository() )->deleteWithAttendance( $activity_id );

        $this->assertSame( 0, $this->linkCount( MediaEntityType::ACTIVITY, $activity_id ) );
        $this->assertSame(
            1,
            $this->linkCount( MediaEntityType::ACTIVITY, $other_id ),
            'The same photo on another activity was taken with it.'
        );
        $this->assertNotNull(
            $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}tt_media WHERE id = %d", $media_id ) ),
            'The media item is still linked elsewhere and must survive.'
        );
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function activity(): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'team_id'           => $this->team_id,
            'title'             => 'Delete event training',
            'session_date'      => '2026-04-01',
            'activity_type_key' => 'training',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** Archive then trash an activity, so purge() considers it eligible. */
    private function inBin( int $activity_id ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'tt_activities',
            [ 'archived_at' => current_time( 'mysql' ), 'archived_by' => get_current_user_id() ],
            [ 'id' => $activity_id ]
        );
        ( new ArchiveRepository() )->trash( 'activity', [ $activity_id ], get_current_user_id() );
    }

    private function vctSession( int $activity_id ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_vct_sessions', [
            'club_id'                => 1,
            'uuid'                   => wp_generate_uuid4(),
            'team_id'                => $this->team_id,
            'activity_id'            => $activity_id,
            'session_date'           => '2026-04-01',
            'age_group'              => 'U17',
            'md_context'             => 'MD-3',
            'total_duration_minutes' => 75,
            'status'                 => 'published',
            'generated_by'           => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function media(): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_media', [
            'club_id'     => 1,
            'uuid'        => wp_generate_uuid4(),
            'storage_key' => 'test/delete-event.jpg',
            'mime_type'   => 'image/jpeg',
            'kind'        => 'image',
            'file_size'   => 1024,
            'uploaded_by' => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function link( int $media_id, string $entity_type, int $entity_id ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_media_links', [
            'club_id'     => 1,
            'media_id'    => $media_id,
            'entity_type' => $entity_type,
            'entity_id'   => $entity_id,
        ] );
    }

    private function linkCount( string $entity_type, int $entity_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_media_links WHERE entity_type = %s AND entity_id = %d",
            $entity_type,
            $entity_id
        ) );
    }
}
