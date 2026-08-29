<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\PdpVerdictDecision;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Comms\Channel\Adapters\EmailChannelAdapter;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\Send\PdpReadySend;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;
use TT\Modules\Comms\Templates\PdpReadyTemplate;
use TT\Modules\Invitations\PlayerParentsRepository;
use TT\Modules\Pdp\Repositories\PdpVerdictsRepository;

/**
 * #2605 Gate D — the `pdp_ready` trigger.
 *
 * The template says "when a development plan is published" and a PDP has
 * no published state: `PdpStatus` is open / completed / archived and
 * nothing in the module means "the family may read this now". Sign-off is
 * the moment the plan stops being a working draft, so that is the moment
 * this listens for, and the tests pin the two things that follow from
 * choosing it: it fires on the transition and not on a re-save, and the
 * family it reaches is the one the youth-contact rules pick.
 */
final class PdpReadyTriggerTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private string $log_table;

    /** @var array<string,mixed>|null */
    private ?array $captured = null;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p         = $wpdb->prefix;
        $this->club      = (int) CurrentClub::id();
        $this->log_table = $wpdb->prefix . 'tt_comms_log';

        $this->captured = null;
        add_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10, 2 );

        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        TemplateRegistry::register( new PdpReadyTemplate() );
        ChannelAdapterRegistry::register( new EmailChannelAdapter() );

        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );
        // Pin the quiet-hours window shut: `pdp_ready` does not bypass it,
        // and a test that passes only outside office hours is not a test.
        QueryHelpers::set_config( 'comms_quiet_hours_start', '03:00' );
        QueryHelpers::set_config( 'comms_quiet_hours_end', '03:01' );

        $wpdb->query( "DELETE FROM {$this->log_table}" );
    }

    public function tear_down(): void {
        remove_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10 );
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        parent::tear_down();
    }

    /**
     * @param mixed               $accepted
     * @param array<string,mixed> $args
     */
    public function captureSend( $accepted, $args ): bool {
        $this->captured = $args;
        return true;
    }

    public function test_sign_off_tells_the_family_the_plan_is_ready(): void {
        $player = $this->insertPlayer( 9 );
        $parent = self::factory()->user->create( [ 'user_email' => 'dad@example.test' ] );
        ( new PlayerParentsRepository() )->link( $player, $parent, true );
        $file = $this->insertPdpFile( $player, $this->insertSeason( '2026/27' ) );

        $results = PdpReadySend::send( $file );

        $this->assertCount( 1, $results );
        $this->assertSame( CommsResult::STATUS_SENT, $results[0]->status );
        $this->assertSame( Recipient::KIND_PARENT, $results[0]->recipient->kind );
        $this->assertSame( 'dad@example.test', $this->captured['to'] );

        // The season is what tells a parent which plan this is; an empty
        // token would leave the subject reading like a template.
        $this->assertStringContainsString( '2026/27', (string) $this->captured['body'] );

        $rows = $this->logRows();
        $this->assertCount( 1, $rows );
        $this->assertSame( MessageType::PDP_READY, $rows[0]->message_type );
    }

    public function test_the_hook_fires_once_on_sign_off_and_not_on_a_re_save(): void {
        // Correcting a typo in a signed-off summary must not tell the
        // family their plan is ready a second time.
        $file  = $this->insertPdpFile( $this->insertPlayer( 9 ), $this->insertSeason( '2026/27' ) );
        $fired = [];
        $spy   = static function ( int $verdict_id, int $file_id ) use ( &$fired ): void {
            $fired[] = $file_id;
        };
        add_action( 'tt_pdp_verdict_signed_off', $spy, 10, 2 );

        $repo = new PdpVerdictsRepository();
        $repo->upsertForFile( $file, [ 'decision' => PdpVerdictDecision::RETAIN, 'signed_off_at' => null ] );
        $this->assertSame( [], $fired, 'an unsigned verdict is still a draft' );

        $repo->upsertForFile( $file, [
            'decision'      => PdpVerdictDecision::RETAIN,
            'signed_off_at' => current_time( 'mysql' ),
        ] );
        $repo->upsertForFile( $file, [
            'decision'      => PdpVerdictDecision::RETAIN,
            'summary'       => 'Corrected a typo.',
            'signed_off_at' => current_time( 'mysql' ),
        ] );

        remove_action( 'tt_pdp_verdict_signed_off', $spy, 10 );
        $this->assertSame( [ $file ], $fired );
    }

    public function test_a_plan_with_no_reachable_family_still_leaves_a_row(): void {
        $file = $this->insertPdpFile( $this->insertPlayer( 9 ), $this->insertSeason( '2026/27' ) );

        $results = PdpReadySend::send( $file );

        $this->assertSame( CommsResult::STATUS_NO_RECIPIENTS, $results[0]->status );
        $this->assertCount( 1, $this->logRows() );
    }

    public function test_an_unknown_file_sends_nothing(): void {
        $this->assertSame( [], PdpReadySend::send( 999999 ) );
        $this->assertSame( [], $this->logRows() );
    }

    // --- fixtures -------------------------------------------------------

    private function insertSeason( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_seasons", [
            'club_id'    => $this->club,
            'name'       => $name,
            'start_date' => '2026-07-01',
            'end_date'   => '2027-06-30',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $age ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'       => $this->club,
            'first_name'    => 'Pdp',
            'last_name'     => 'Player',
            'status'        => 'active',
            'date_of_birth' => gmdate( 'Y-m-d', strtotime( "-{$age} years" ) ),
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPdpFile( int $player_id, int $season_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_pdp_files", [
            'club_id'   => $this->club,
            'player_id' => $player_id,
            'season_id' => $season_id,
            'status'    => 'open',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @return object[] */
    private function logRows(): array {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM {$this->log_table}" );
    }
}
