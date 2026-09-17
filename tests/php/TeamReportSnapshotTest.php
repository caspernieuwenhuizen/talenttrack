<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Analytics\Frontend\TeamMonthlyReportSnapshotPage;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;
use TT\Modules\Analytics\Reports\TeamReportAccess;
use TT\Modules\Analytics\Reports\TeamReportSnapshotRepository;

/**
 * #3517 (epic #3513) — frozen monthly reports for a staff meeting.
 *
 * The first group is the one that matters most. A monthly report names every
 * player in a squad and carries their attendance, their test readings and who
 * needs a conversation — the densest collection of information about minors
 * this product produces. **A snapshot is never reachable without signing in**,
 * and there is no token that changes that. If a later change adds one, these
 * fail.
 *
 * The second group is what makes a snapshot worth taking: the numbers are
 * frozen and the notes are not.
 */
final class TeamReportSnapshotTest extends WP_UnitTestCase {

    private TeamReportSnapshotRepository $repo;
    private int $team_id;
    private int $staff_id;

    public function set_up(): void {
        parent::set_up();
        $this->repo     = new TeamReportSnapshotRepository();
        $this->team_id  = 4242;
        $this->staff_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
    }

    /** @return array<string,mixed> */
    private function report(): array {
        return [
            'team_id' => $this->team_id,
            'from'    => '2026-08-01',
            'to'      => '2026-08-31',
            'blocks'  => [ 'letterhead', 'kpi', 'attendance' ],
            'data'    => [
                'letterhead' => [ 'squad_size' => 14, 'activity_count' => 9 ],
                'kpi'        => [ 'attendance_pct' => 81.5 ],
                'attendance' => [ 'rows' => [ [ 'player_id' => 1, 'name' => 'Anna Bakker', 'attendance_pct' => 90.0 ] ] ],
            ],
        ];
    }

    private function newSnapshot(): string {
        return $this->repo->create(
            $this->team_id,
            [ 'team_id' => $this->team_id, 'layout' => 'A', 'blocks' => [ 'kpi', 'attendance' ] ],
            $this->report(),
            'August staff meeting',
            $this->staff_id
        );
    }

    // ── signed in, always ──────────────────────────────────────────────

    /**
     * The whole security property in one assertion: user 0 is refused, so no
     * request without a session can reach a snapshot's contents.
     */
    public function test_a_signed_out_reader_is_refused(): void {
        wp_set_current_user( 0 );

        $this->assertFalse( TeamReportAccess::canRead( 0, $this->team_id ) );
        $this->assertFalse(
            TeamMonthlyReportSnapshotPage::render( $this->newSnapshot() ),
            'A snapshot must not render for a signed-out reader.'
        );
    }

    /** No query parameter is a key. If one ever is, this fails. */
    public function test_no_token_parameter_makes_a_snapshot_readable(): void {
        $uuid = $this->newSnapshot();
        wp_set_current_user( 0 );

        foreach ( [ 'token', 'share', 'key', 'access', 'sig', 'hash' ] as $param ) {
            $_GET[ $param ] = 'anything';
        }

        try {
            $this->assertFalse(
                TeamMonthlyReportSnapshotPage::render( $uuid ),
                'No query parameter may unlock a snapshot.'
            );
        } finally {
            foreach ( [ 'token', 'share', 'key', 'access', 'sig', 'hash' ] as $param ) {
                unset( $_GET[ $param ] );
            }
        }
    }

    /** The URL must not carry a secret that would make it work when forwarded. */
    public function test_the_snapshot_url_carries_no_token(): void {
        $url = TeamMonthlyReportSnapshotPage::url( 'a-uuid' );

        $this->assertStringContainsString( 'snapshot=a-uuid', $url );
        foreach ( [ 'token', 'share', 'key=', 'sig', 'hash', 'expires' ] as $secret ) {
            $this->assertStringNotContainsString( $secret, $url, "The URL must not carry '{$secret}'." );
        }
    }

    public function test_a_signed_in_reader_with_access_can_read_it(): void {
        wp_set_current_user( $this->staff_id );

        $this->assertTrue( TeamReportAccess::canRead( $this->staff_id, $this->team_id ) );
        $this->assertNotNull( $this->repo->find( $this->newSnapshot() ) );
    }

    public function test_an_unknown_uuid_does_not_render(): void {
        wp_set_current_user( $this->staff_id );

        $this->assertNull( $this->repo->find( 'no-such-snapshot' ) );
        $this->assertFalse( TeamMonthlyReportSnapshotPage::render( 'no-such-snapshot' ) );
    }

    // ── frozen data, editable notes ────────────────────────────────────

    public function test_a_snapshot_stores_the_rendered_report_not_a_reference(): void {
        $row = $this->repo->find( $this->newSnapshot() );
        $this->assertNotNull( $row );

        $frozen = TeamReportSnapshotRepository::reportOf( $row );

        $this->assertSame( 81.5, $frozen['data']['kpi']['attendance_pct'] );
        $this->assertSame( [ 'letterhead', 'kpi', 'attendance' ], $frozen['blocks'] );
        $this->assertSame( '2026-08-01', $frozen['from'] );
    }

    public function test_a_note_can_be_written_read_and_cleared(): void {
        $uuid = $this->newSnapshot();

        $this->assertTrue( $this->repo->putNote( $uuid, 'attendance', 'Two away on holiday.', $this->staff_id ) );

        $notes = TeamReportSnapshotRepository::notesOf( $this->repo->find( $uuid ) );
        $this->assertSame( 'Two away on holiday.', $notes['attendance']['body'] );
        $this->assertSame( $this->staff_id, $notes['attendance']['author'] );
        $this->assertNotSame( '', $notes['attendance']['updated_at'] );

        // Clearing removes the entry, so "no note" and "a note that was
        // cleared" read the same on the page and in the PDF.
        $this->repo->putNote( $uuid, 'attendance', '   ', $this->staff_id );
        $this->assertSame( [], TeamReportSnapshotRepository::notesOf( $this->repo->find( $uuid ) ) );
    }

    /** Drafting before the meeting and recording during it is the workflow. */
    public function test_editing_a_note_leaves_the_frozen_numbers_alone(): void {
        $uuid   = $this->newSnapshot();
        $before = TeamReportSnapshotRepository::reportOf( $this->repo->find( $uuid ) );

        $this->repo->putNote( $uuid, 'kpi', 'Discussed at length.', $this->staff_id );

        $after = TeamReportSnapshotRepository::reportOf( $this->repo->find( $uuid ) );
        $this->assertSame( $before, $after );
    }

    public function test_a_note_on_something_that_is_not_a_section_is_refused(): void {
        $uuid = $this->newSnapshot();

        $this->assertFalse( $this->repo->putNote( $uuid, 'not_a_section', 'x', $this->staff_id ) );
    }

    public function test_a_very_long_note_is_truncated_rather_than_rejected(): void {
        $uuid = $this->newSnapshot();
        $this->repo->putNote( $uuid, 'kpi', str_repeat( 'a', 9000 ), $this->staff_id );

        $notes = TeamReportSnapshotRepository::notesOf( $this->repo->find( $uuid ) );
        $this->assertSame( TeamReportSnapshotRepository::MAX_NOTE_LENGTH, strlen( $notes['kpi']['body'] ) );
    }

    // ── surviving a changing product ───────────────────────────────────

    /**
     * A snapshot renders what it stored. A block the vocabulary no longer has
     * must not reach a renderer that no longer knows it.
     */
    public function test_a_block_removed_from_the_vocabulary_is_dropped_on_read(): void {
        $report           = $this->report();
        $report['blocks'] = [ 'kpi', 'a_block_from_the_future' ];
        $report['data']['a_block_from_the_future'] = [ 'x' => 1 ];

        $uuid = $this->repo->create( $this->team_id, [], $report, 'Odd one', $this->staff_id );
        $frozen = TeamReportSnapshotRepository::reportOf( $this->repo->find( $uuid ) );

        $this->assertSame( [ 'kpi' ], $frozen['blocks'] );
        $this->assertArrayNotHasKey( 'a_block_from_the_future', $frozen['data'] );
    }

    /** A section added after the snapshot was taken is simply absent. */
    public function test_a_block_added_later_is_absent_rather_than_empty(): void {
        $frozen = TeamReportSnapshotRepository::reportOf( $this->repo->find( $this->newSnapshot() ) );

        $this->assertArrayNotHasKey( TeamMonthlyReportBlock::MATCHES, $frozen['data'] );
    }

    public function test_malformed_stored_json_opens_rather_than_fatals(): void {
        $row = (object) [ 'team_id' => 1, 'period_from' => '', 'period_to' => '', 'data_json' => '{"blocks":', 'notes_json' => 'nonsense' ];

        $this->assertSame( [], TeamReportSnapshotRepository::reportOf( $row )['blocks'] );
        $this->assertSame( [], TeamReportSnapshotRepository::notesOf( $row ) );
    }
}
