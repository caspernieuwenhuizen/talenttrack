<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Cron\ScheduledReportsRunner;
use TT\Modules\Analytics\Reports\PlayerReportComposition;
use TT\Modules\Analytics\Reports\PlayerReportDelivery;
use TT\Modules\Analytics\ScheduledReportsRepository;
use TT\Modules\Comms\Channel\Adapters\EmailChannelAdapter;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;
use TT\Modules\Comms\Templates\ScheduledReportTemplate;
use TT\Modules\Export\Exporters\PlayerReportPdfDocument;

/**
 * #3891 (epic #3871) — the player report, mailed on the 1st.
 *
 * Pinned: a squad schedule plans one report per player in shirt order and a
 * player schedule plans one; an archived team or an owner who lost access
 * stops the schedule rather than mailing; a squad larger than the cap stops
 * rather than truncating; a good squad run mails one PDF named for the team;
 * and the batch prints each player from a new page.
 *
 * The owner is a `tt_club_admin`; see `PlayerReportTest` for why.
 */
final class PlayerReportScheduleTest extends WP_UnitTestCase {

    private int $owner = 0;
    private int $team  = 0;
    private ScheduledReportsRepository $repo;

    /** @var list<string> attachment basenames the transport saw */
    private array $sent = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        $this->owner = (int) self::factory()->user->create( [ 'role' => 'tt_club_admin' ] );

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => CurrentClub::id(), 'name' => 'JO15-2' ] );
        $this->team = (int) $wpdb->insert_id;
        $this->repo = new ScheduledReportsRepository();

        ( require dirname( __DIR__, 2 ) . '/database/migrations/0266_scheduled_reports_composition.php' )->up();

        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        TemplateRegistry::register( new ScheduledReportTemplate() );
        ChannelAdapterRegistry::register( new EmailChannelAdapter() );
        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );
        QueryHelpers::set_config( 'comms_quiet_hours_start', '03:00' );
        QueryHelpers::set_config( 'comms_quiet_hours_end', '03:01' );

        $this->sent = [];
        add_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10, 2 );
    }

    public function tear_down(): void {
        remove_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10 );
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        parent::tear_down();
    }

    /**
     * @param mixed               $accepted
     * @param array<string,mixed> $payload
     */
    public function captureSend( $accepted, array $payload ): bool {
        foreach ( (array) ( $payload['attachments'] ?? [] ) as $path ) {
            $this->sent[] = basename( (string) $path );
        }
        return true;
    }

    public function test_a_squad_schedule_plans_every_player_in_shirt_order(): void {
        $late  = $this->player( 'Zeta', 3 );
        $first = $this->player( 'Alpha', 11 );
        $none  = $this->player( 'Beta', null );

        $plan = PlayerReportDelivery::plan( $this->schedule( true ), '2026-10-01' );

        $this->assertTrue( $plan['ok'], $plan['error'] );
        $this->assertSame( [ $late, $first, $none ], $plan['players'], 'shirt number first, no number last' );
        $this->assertStringContainsString( 'JO15-2', $plan['filename'] );
    }

    public function test_a_player_schedule_plans_one(): void {
        $pid  = $this->player( 'Solo', 7 );
        $plan = PlayerReportDelivery::plan( $this->schedule( false, $pid ), '2026-10-01' );

        $this->assertTrue( $plan['ok'], $plan['error'] );
        $this->assertSame( [ $pid ], $plan['players'] );
    }

    public function test_an_archived_team_stops_the_schedule_instead_of_mailing(): void {
        global $wpdb;
        $this->player( 'Kept', 4 );
        $id = $this->create( $this->schedule( true ) );
        $wpdb->update( "{$wpdb->prefix}tt_teams", [ 'archived_at' => current_time( 'mysql' ) ], [ 'id' => $this->team ] );

        ScheduledReportsRunner::runPlayerReport( $this->repo, (array) $this->repo->findById( $id ), '2026-10-01 06:00:00' );

        $row = (array) $this->repo->findById( $id );
        $this->assertSame( [], $this->sent );
        $this->assertSame( ScheduledReportsRepository::STATUS_PAUSED, $row['status'] );
        $this->assertNotEmpty( $row['last_error'] );
    }

    public function test_an_owner_who_lost_access_stops_the_schedule(): void {
        $this->player( 'Kept', 4 );
        $schedule               = $this->schedule( true );
        $schedule['created_by'] = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );

        $plan = PlayerReportDelivery::plan( $schedule, '2026-10-01' );

        $this->assertFalse( $plan['ok'] );
        $this->assertTrue( $plan['stop'], 'lost access pauses rather than retries' );
        $this->assertTrue( PlayerReportDelivery::plan( $this->schedule( true ), '2026-10-01' )['ok'], 'precondition: the owner can' );
    }

    public function test_a_squad_over_the_cap_stops_rather_than_truncates(): void {
        for ( $i = 1; $i <= PlayerReportDelivery::MAX_BATCH + 1; $i++ ) {
            $this->player( 'P' . $i, $i );
        }

        $plan = PlayerReportDelivery::plan( $this->schedule( true ), '2026-10-01' );

        $this->assertFalse( $plan['ok'] );
        $this->assertTrue( $plan['stop'] );
        $this->assertStringContainsString( (string) PlayerReportDelivery::MAX_BATCH, $plan['error'] );
    }

    public function test_a_good_squad_run_mails_one_pdf_named_for_the_team(): void {
        if ( ! class_exists( \Dompdf\Dompdf::class ) ) {
            $this->markTestSkipped( 'DomPDF not installed.' );
        }
        $this->player( 'One', 1 );
        $this->player( 'Two', 2 );
        $id = $this->create( $this->schedule( true ) );

        ScheduledReportsRunner::runPlayerReport( $this->repo, (array) $this->repo->findById( $id ), gmdate( 'Y-m-01' ) . ' 06:00:00' );

        $this->assertCount( 1, $this->sent );
        $this->assertStringStartsWith( 'JO15-2-player-reports-', $this->sent[0] );
        $this->assertSame( ScheduledReportsRepository::STATUS_ACTIVE, ( (array) $this->repo->findById( $id ) )['status'] );
    }

    public function test_the_batch_starts_each_player_on_a_new_page(): void {
        $report = [ 'from' => '2026-08-01', 'to' => '2026-08-31', 'blocks' => [ 'letterhead' ], 'data' => [ 'letterhead' => [ 'name' => 'A' ] ] ];
        $html   = PlayerReportPdfDocument::batchHtml( [ $report, $report, $report ] );

        $this->assertSame( 2, substr_count( $html, 'class="player break"' ), 'every player after the first breaks the page' );
        $this->assertSame( 1, substr_count( $html, '<style>' ), 'one document, one stylesheet' );
    }

    private function player( string $last, ?int $jersey ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => CurrentClub::id(), 'team_id' => $this->team, 'first_name' => 'Round', 'last_name' => $last,
            'jersey_number' => $jersey, 'status' => 'active', 'wp_user_id' => null,
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @return array<string,mixed> */
    private function schedule( bool $squad, int $player_id = 0 ): array {
        $composition = PlayerReportComposition::normalise( [ 'player_id' => $player_id, 'layout' => 'A' ] );
        if ( $squad ) $composition['team_id'] = $this->team;
        return [
            'id'          => 0,
            'name'        => 'Player reports JO15-2',
            'report_key'  => ScheduledReportsRepository::REPORT_PLAYER,
            'composition' => $composition,
            'frequency'   => ScheduledReportsRepository::FREQUENCY_MONTHLY_FIRST,
            'recipients'  => [ 'staff@example.test' ],
            'format'      => 'pdf',
            'created_by'  => $this->owner,
        ];
    }

    /** @param array<string,mixed> $schedule */
    private function create( array $schedule ): int {
        $id = $this->repo->create( $schedule, (int) $schedule['created_by'] );
        $this->assertGreaterThan( 0, $id, 'the fixture wrote' );
        return $id;
    }
}
