<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\PlayerReportBlock;
use TT\Modules\Analytics\Reports\PlayerReportSnapshotRepository;
use TT\Modules\Analytics\Reports\PlayerReportSnapshots;
use TT\Modules\Analytics\Reports\StudioSnapshot;
use TT\Modules\Analytics\Reports\TeamReportSnapshotRepository;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\LicenseMode;
use TT\Modules\ReportStudio\Domain\BlockRegistry;
use TT\Modules\ReportStudio\Domain\FamilySection;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\ReportSnapshots;
use TT\Modules\ReportStudio\Domain\SharedReads;
use TT\Modules\ReportStudio\Repositories\ReportsRepository;
use TT\Shared\Frontend\DashboardShortcode;
use TT\Shared\Tiles\TileRegistry;

/**
 * #5021 — who opens and who writes on a Report Studio snapshot.
 *
 * - a frozen copy is shown under the reader's rights, not the taker's: a
 *   block the reader could not read live is left out, and so is a row about a
 *   player they may not see;
 * - a copy for staff opens only for a reader who reads as staff; a scout is
 *   refused both an internal and a scout copy;
 * - refusals: a parent of another child, a coach of another team, an unknown
 *   uuid, a read-only observer writing a note, a recipient without the right
 *   to take the snapshot, a team reader without it writing a note;
 * - the notes PUT requires `body`: leaving it out changes nothing;
 * - every block a family may receive names the section of the child's file it
 *   is made of, and only those; a stored family definition holding a block
 *   that left the family list reads as not available;
 * - the lookups that read `report_id` still answer before 0327 has run.
 */
final class ReportStudioSnapshotAccessTest extends WP_UnitTestCase {

    // Publishing commits, so every test cleans up what it wrote.
    use CommitsAfterCascade;

    private const PERIOD = '2026-08-01/2026-08-31';
    private const NOTE   = 'Spoke about the role in the press.';

    private int $team_a = 0;
    private int $team_b = 0;
    private int $child = 0;
    private int $other_child = 0;
    private int $hod = 0;
    private int $coach_a = 0;
    private int $coach_b = 0;
    private int $manager = 0;
    private int $observer = 0;
    private int $scout = 0;
    private int $parent = 0;
    private int $other_parent = 0;
    private string $matrix_was = '';

    /** @var array<string,ReportBlock> */
    private array $real_blocks = [];

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        if ( get_role( 'tt_team_manager' ) === null ) {
            add_role( 'tt_team_manager', 'Team Manager', [ 'read' => true ] );
        }
        $config           = new ConfigService();
        $this->matrix_was = (string) $config->get( 'tt_authorization_active', '' );
        $config->set( 'tt_authorization_active', '1' );
        $this->resetMatrixActiveCaches();

        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Access U13' ] );
        $this->team_a = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Access U15' ] );
        $this->team_b = (int) $wpdb->insert_id;
        $this->child       = $this->player( $this->team_a, 'Child' );
        $this->other_child = $this->player( $this->team_b, 'Other' );

        $this->hod      = $this->persona( 'tt_head_dev', 'head_of_development', 0 );
        $this->coach_a  = $this->persona( 'tt_coach', 'head_coach', $this->team_a );
        $this->coach_b  = $this->persona( 'tt_coach', 'head_coach', $this->team_b );
        $this->manager  = $this->persona( 'tt_team_manager', 'team_manager', $this->team_a );
        $this->observer = $this->persona( 'tt_readonly_observer', 'other', 0 );

        $this->scout = (int) self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        update_user_meta( $this->scout, 'tt_scout_player_ids', wp_json_encode( [ $this->child ] ) );
        $this->parent       = $this->parentOf( $this->child );
        $this->other_parent = $this->parentOf( $this->other_child );

        $this->real_blocks = BlockRegistry::all();

        FeatureRegistry::setEnabled( 'report_studio', true );
        LicenseMode::overrideForTests( true );
        LicenseGate::overrideTierForTests( FeatureMap::TIER_PRO );

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        $this->flush();
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        BlockRegistry::clear();
        foreach ( $this->real_blocks as $block ) BlockRegistry::register( $block );
        StudioSnapshot::flushColumnCache();
        LicenseMode::overrideForTests( null );
        LicenseGate::overrideTierForTests( null );
        wp_set_current_user( 0 );
        ( new ConfigService() )->set( 'tt_authorization_active', $this->matrix_was );
        $this->resetMatrixActiveCaches();
        $this->cleanUpAndCommit();
        $this->flush();
        parent::tear_down();
    }

    // ---- shown under the reader's rights ------------------------------------------

    public function test_a_block_the_reader_cannot_read_live_is_left_out(): void {
        BlockRegistry::register( $this->block( 'test_admin_only', 'manage_options', [] ) );
        $admin = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        $doc   = $this->document( $this->team_a, [
            [ 'id' => 'b1', 'key' => 'heading', 'width' => 'full', 'data' => [ 'heading' => 'Kept' ] ],
            [ 'id' => 'b2', 'key' => 'test_admin_only', 'width' => 'full', 'data' => [ 'value' => 7 ] ],
        ] );

        $this->assertSame( [ 'b1', 'b2' ], array_column( ReportSnapshots::forReader( $doc, $admin )['blocks'], 'id' ), 'the taker reads both' );
        $this->assertSame( [ 'b1' ], array_column( ReportSnapshots::forReader( $doc, $this->hod )['blocks'], 'id' ), 'staff without the block\'s capability do not' );
    }

    public function test_a_team_narrowed_coach_reads_only_their_own_players_rows(): void {
        BlockRegistry::register( $this->block( 'test_rows', 'tt_view_reports', [ ReportContext::SCOPE_TEAM ] ) );
        $doc = $this->document( $this->team_a, [
            [ 'id' => 'b1', 'key' => 'test_rows', 'width' => 'full', 'data' => [ 'breakdown' => 'player_id', 'rows' => [
                [ 'player_id' => $this->child, 'name' => 'Child', 'value' => 1 ],
                [ 'player_id' => $this->other_child, 'name' => 'Other', 'value' => 2 ],
            ] ] ],
        ] );

        $hod   = ReportSnapshots::forReader( $doc, $this->hod )['blocks'][0]['data']['rows'];
        $coach = ReportSnapshots::forReader( $doc, $this->coach_a )['blocks'][0]['data']['rows'];
        $this->assertSame( [ $this->child, $this->other_child ], array_column( $hod, 'player_id' ), 'the taker\'s rows' );
        $this->assertSame( [ $this->child ], array_column( $coach, 'player_id' ), 'a coach of the team reads only their own players' );
    }

    // ---- refusals ------------------------------------------------------------------

    public function test_a_scout_is_refused_a_staff_and_a_scout_copy(): void {
        $internal = $this->snapshot( $this->published( $this->playerChoices( 'internal' ), [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'Staff' ] ] ] ) );
        $scout    = $this->snapshot( $this->published( $this->playerChoices( 'scout' ), [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'Scout' ] ] ] ) );

        $this->assertTrue( \TT\Modules\Analytics\Reports\PlayerReportAccess::canRead( $this->scout, $this->child ), 'precondition: the linked scout reads the player\'s reports' );
        $this->assertSame( PlayerReportAudience::SCOUT, PlayerReportAudience::forReader( $this->scout ), 'precondition: the scout reads as a scout' );
        wp_set_current_user( $this->scout );
        foreach ( [ 'internal' => $internal, 'scout' => $scout ] as $audience => $uuid ) {
            $this->assertNull( ReportSnapshots::read( $uuid, $this->scout ), $audience );
            $this->assertNull( PlayerReportSnapshots::read( $uuid, $this->scout ), "{$audience}, on the player snapshot route too" );
            $this->assertSame( 403, $this->send( 'GET', "report-snapshots/{$uuid}" )->get_status(), $audience );
            $this->assertSame( 403, $this->send( 'GET', "player-report-snapshots/{$uuid}" )->get_status(), $audience );
        }
    }

    public function test_a_parent_of_another_child_and_an_unknown_uuid_are_the_same_403(): void {
        $uuid = $this->snapshot( $this->published( $this->playerChoices( 'family' ) + [ 'sharing' => 'family' ], [ [ 'id' => 'b1', 'key' => 'text', 'options' => [ 'heading' => 'For you', 'text' => 'Well done.' ] ] ] ) );

        wp_set_current_user( $this->parent );
        $this->assertSame( 200, $this->send( 'GET', "report-snapshots/{$uuid}" )->get_status(), 'precondition: the child\'s own parent reads it' );

        wp_set_current_user( $this->other_parent );
        $other   = $this->send( 'GET', "report-snapshots/{$uuid}" );
        $unknown = $this->send( 'GET', 'report-snapshots/' . wp_generate_uuid4() );
        $this->assertSame( 403, $other->get_status() );
        $this->assertSame( 403, $unknown->get_status() );
        $this->assertSame( $this->code( $other ), $this->code( $unknown ), 'a uuid cannot be probed' );
    }

    public function test_a_coach_of_another_team_is_refused_a_team_copy(): void {
        $uuid = $this->snapshot( $this->published( $this->teamChoices(), [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'Team' ] ] ] ) );

        wp_set_current_user( $this->coach_a );
        $this->assertSame( 200, $this->send( 'GET', "report-snapshots/{$uuid}" )->get_status(), 'precondition: the team\'s own coach reads it' );
        wp_set_current_user( $this->coach_b );
        $this->assertSame( 403, $this->send( 'GET', "report-snapshots/{$uuid}" )->get_status() );
    }

    public function test_a_read_only_observer_writes_no_note(): void {
        $uuid = $this->snapshot( $this->published( $this->playerChoices( 'internal' ), [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'Staff' ] ] ] ) );

        wp_set_current_user( $this->observer );
        $this->assertSame( 403, $this->send( 'PUT', "report-snapshots/{$uuid}/notes/b1", [ 'body' => self::NOTE ] )->get_status() );
        $this->assertStringNotContainsString( self::NOTE, $this->notesJson( 'tt_player_report_snapshots', $uuid ) );
    }

    public function test_a_recipient_without_the_right_to_take_it_is_refused(): void {
        $id = $this->published( $this->playerChoices( 'internal' ) + [ 'sharing' => 'role' ], [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'Shared' ] ] ], [ 'team_manager' ] );

        wp_set_current_user( $this->manager );
        $this->assertSame( 200, $this->send( 'GET', "reports/{$id}/data" )->get_status(), 'precondition: the team manager reads the report' );
        $document = $this->send( 'GET', "reports/{$id}/document" );
        $this->assertFalse( $document->get_data()['data']['context']['can_snapshot'], 'the control is not offered' );

        $res = $this->send( 'POST', "reports/{$id}/snapshots" );
        $this->assertSame( 403, $res->get_status() );
        $this->assertSame( 'forbidden_player', $this->code( $res ) );

        wp_set_current_user( $this->hod );
        $this->assertTrue( $this->send( 'GET', "reports/{$id}/document" )->get_data()['data']['context']['can_snapshot'], 'the builder is offered it' );
    }

    public function test_a_team_note_needs_the_right_to_take_the_snapshot_and_is_audited(): void {
        $uuid = $this->snapshot( $this->published( $this->teamChoices(), [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'Team' ] ] ] ) );

        wp_set_current_user( $this->manager );
        $read = $this->send( 'GET', "report-snapshots/{$uuid}" );
        $this->assertSame( 200, $read->get_status(), 'precondition: the team manager reads it' );
        $this->assertFalse( $read->get_data()['data']['can_note'] );
        $this->assertSame( 403, $this->send( 'PUT', "report-snapshots/{$uuid}/notes/b1", [ 'body' => self::NOTE ] )->get_status() );

        $before = $this->auditRows( 'team_report.snapshot_noted', $this->team_a );
        wp_set_current_user( $this->hod );
        $this->assertSame( 200, $this->send( 'PUT', "report-snapshots/{$uuid}/notes/b1", [ 'body' => self::NOTE ] )->get_status() );
        $this->assertSame( $before + 1, $this->auditRows( 'team_report.snapshot_noted', $this->team_a ) );
        $this->assertStringContainsString( self::NOTE, $this->notesJson( 'tt_team_report_snapshots', $uuid ) );
    }

    // ---- partial update --------------------------------------------------------------

    public function test_a_note_put_without_a_body_changes_nothing(): void {
        $uuid = $this->snapshot( $this->published( $this->playerChoices( 'internal' ), [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'Staff' ] ] ] ) );

        wp_set_current_user( $this->hod );
        $this->assertSame( 200, $this->send( 'PUT', "report-snapshots/{$uuid}/notes/b1", [ 'body' => self::NOTE ] )->get_status() );

        $omitted = $this->send( 'PUT', "report-snapshots/{$uuid}/notes/b1" );
        $this->assertSame( 400, $omitted->get_status() );
        $this->assertSame( 'missing_fields', $this->code( $omitted ) );
        $this->assertStringContainsString( self::NOTE, $this->notesJson( 'tt_player_report_snapshots', $uuid ), 'an omitted body leaves the note alone' );

        $this->assertSame( 200, $this->send( 'PUT', "report-snapshots/{$uuid}/notes/b1", [ 'body' => '' ] )->get_status() );
        $this->assertStringNotContainsString( self::NOTE, $this->notesJson( 'tt_player_report_snapshots', $uuid ), 'an empty body removes it' );
    }

    // ---- the family list -----------------------------------------------------------

    public function test_every_family_block_names_its_section_and_only_those(): void {
        $sections = array_values( array_diff( PlayerReportAudience::FAMILY_BLOCKS, [ PlayerReportBlock::LETTERHEAD ] ) );
        foreach ( BlockRegistry::all() as $key => $block ) {
            $family = in_array( PlayerReportAudience::FAMILY, $block->audiences(), true );
            $this->assertSame( $family, $block instanceof FamilySection, "{$key}: a family block names its section, and only a family block does" );
            if ( $block instanceof FamilySection ) {
                $section = $block->familySection();
                $this->assertTrue( $section === FamilySection::DOCUMENT || in_array( $section, $sections, true ), "{$key}: {$section} is a section a family report carries" );
            }
        }
        foreach ( [ 'goals_assists', 'own_question' ] as $key ) {
            $block = BlockRegistry::find( $key );
            $this->assertNotNull( $block );
            $this->assertNotContains( PlayerReportAudience::FAMILY, $block->audiences(), "{$key} is not sent to a family" );
        }
    }

    public function test_a_stored_family_definition_with_goals_and_assists_reads_as_not_available(): void {
        $id = $this->published( $this->playerChoices( 'family' ) + [ 'sharing' => 'family' ], [ [ 'id' => 'b1', 'key' => 'letterhead' ] ] );

        // A definition saved before goals and assists left the family list.
        $repo  = new ReportsRepository();
        $draft = $repo->draft( $id, $this->hod );
        $this->assertNotNull( $draft );
        $this->assertNotNull( $repo->saveDraft( $id, $this->hod, [ 'blocks' => [
            [ 'id' => 'b1', 'key' => 'letterhead', 'options' => [], 'width' => 'full' ],
            [ 'id' => 'b2', 'key' => 'goals_assists', 'options' => [], 'width' => 'full' ],
        ] ], $draft['revision'] ) );

        wp_set_current_user( $this->hod );
        $res = $this->send( 'GET', "reports/{$id}/data", [ 'source' => 'draft' ] );
        $this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        $by_id = array_column( $res->get_data()['data']['blocks'], null, 'id' );
        $this->assertArrayHasKey( 'data', $by_id['b1'] );
        $this->assertArrayHasKey( 'error', $by_id['b2'], 'the block reads as not available, the report still reads' );
    }

    // ---- before 0327 -----------------------------------------------------------------

    public function test_the_lookups_answer_without_the_column(): void {
        $family = ( new PlayerReportSnapshotRepository() )->create(
            $this->child,
            [ 'player_id' => $this->child, 'audience' => PlayerReportAudience::FAMILY ],
            [ 'player_id' => $this->child, 'from' => '2026-07-01', 'to' => '2026-07-31', 'blocks' => [ 'letterhead' ], 'data' => [] ],
            'July report',
            $this->hod
        );
        $monthly = ( new TeamReportSnapshotRepository() )->create(
            $this->team_a,
            [ 'team_id' => $this->team_a ],
            [ 'team_id' => $this->team_a, 'from' => '2026-07-01', 'to' => '2026-07-31', 'blocks' => [], 'data' => [] ],
            'July pack',
            $this->hod
        );

        StudioSnapshot::flushColumnCache( false );
        $this->assertContains( $family, array_column( PlayerReportSnapshots::sharedWithFamily( $this->child, $this->parent ), 'uuid' ), 'the family list still answers' );
        $this->assertNotNull( ( new TeamReportSnapshotRepository() )->find( $monthly ), 'a monthly snapshot still opens' );
        $this->assertContains( $monthly, array_column( ( new TeamReportSnapshotRepository() )->listForTeam( $this->team_a ), 'uuid' ) );
        $this->assertNull( ( new TeamReportSnapshotRepository() )->findStudio( $monthly ) );
        $this->assertSame( [], ( new PlayerReportSnapshotRepository() )->listForReport( 1 ) );
        StudioSnapshot::flushColumnCache();
    }

    // ---- helpers ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function playerChoices( string $audience ): array {
        return [ 'kind' => 'report', 'scope' => 'player', 'audience' => $audience, 'binding' => 'fixed', 'player_id' => $this->child, 'period_mode' => 'fixed', 'period_value' => self::PERIOD ];
    }

    /** @return array<string,mixed> */
    private function teamChoices(): array {
        return [ 'kind' => 'report', 'scope' => 'team', 'binding' => 'fixed', 'team_id' => $this->team_a, 'period_mode' => 'fixed', 'period_value' => self::PERIOD ];
    }

    /**
     * A report by the head of development, published for these personas.
     *
     * @param array<string,mixed>       $choices
     * @param list<array<string,mixed>> $blocks
     * @param list<string>              $personas
     */
    private function published( array $choices, array $blocks, array $personas = [] ): int {
        wp_set_current_user( $this->hod );
        $res = $this->send( 'POST', 'reports', $choices );
        $this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        $id  = (int) $res->get_data()['data']['id'];
        $res = $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => $blocks ] ] );
        $this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        $res = $this->send( 'POST', "reports/{$id}/versions", [ 'note' => 'First', 'persona_keys' => $personas ] );
        $this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        wp_set_current_user( 0 );
        return $id;
    }

    private function snapshot( int $id ): string {
        wp_set_current_user( $this->hod );
        $res = $this->send( 'POST', "reports/{$id}/snapshots" );
        $this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        wp_set_current_user( 0 );
        return (string) $res->get_data()['data']['uuid'];
    }

    /**
     * A frozen team document, as a snapshot stores it.
     *
     * @param list<array<string,mixed>> $blocks
     * @return array{report_id:int, version_id:int, name:string, context:array<string,mixed>, blocks:list<array<string,mixed>>, page_fit:array<string,mixed>}
     */
    private function document( int $team, array $blocks ): array {
        return [
            'report_id'  => 1,
            'version_id' => 1,
            'name'       => 'Frozen',
            'context'    => [ 'scope' => 'team', 'team_id' => $team, 'player_id' => null, 'player_ids' => [], 'from' => '2026-08-01', 'to' => '2026-08-31', 'audience' => 'internal' ],
            'blocks'     => $blocks,
            'page_fit'   => [ 'pages' => 1, 'fill' => [ 10 ], 'breaks' => [] ],
        ];
    }

    /** @param list<string> $scopes */
    private function block( string $key, string $capability, array $scopes ): ReportBlock {
        return new class( $key, $capability, $scopes ) implements ReportBlock {
            /** @param list<string> $scopes */
            public function __construct( private string $k, private string $cap, private array $scopes ) {}
            public function key(): string { return $this->k; }
            public function label(): string { return 'Test block'; }
            public function scopes(): array { return $this->scopes !== [] ? $this->scopes : ReportContext::SCOPES; }
            public function audiences(): array { return [ PlayerReportAudience::INTERNAL ]; }
            public function capability(): string { return $this->cap; }
            public function options( ReportContext $context ): array { return []; }
            public function available( ReportContext $context ): bool { return true; }
            public function externalKeys(): array { return []; }
            public function render( ReportContext $context, array $options ): array { return []; }
        };
    }

    private function player( int $team, string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => CurrentClub::id(), 'team_id' => $team, 'first_name' => 'Access', 'last_name' => $last, 'status' => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function persona( string $role, string $role_type, int $team ): int {
        global $wpdb;
        $club = (int) CurrentClub::id();
        $uid  = (int) self::factory()->user->create( [ 'role' => $role ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $club, 'first_name' => 'Access', 'last_name' => 'Persona' . $uid,
            'role_type' => $role_type, 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        if ( $team > 0 ) {
            $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
                'club_id' => $club, 'team_id' => $team, 'person_id' => $person,
                'role_in_team' => $role_type, 'is_head_coach' => $role_type === 'head_coach' ? 1 : 0,
            ] );
            $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
                'club_id' => $club, 'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team,
            ] );
        }
        $this->flush();
        return $uid;
    }

    private function parentOf( int $player ): int {
        global $wpdb;
        $uid = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [ 'club_id' => CurrentClub::id(), 'player_id' => $player, 'parent_user_id' => $uid ] );
        $this->flush();
        return $uid;
    }

    private function notesJson( string $table, string $uuid ): string {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare( "SELECT notes_json FROM {$wpdb->prefix}{$table} WHERE uuid = %s", $uuid ) );
    }

    private function auditRows( string $action, int $team ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_audit_log WHERE action = %s AND entity_type = 'team' AND entity_id = %d",
            $action,
            $team
        ) );
    }

    /** @param array<string,mixed> $params */
    private function send( string $method, string $route, array $params = [] ): WP_REST_Response {
        $req = new WP_REST_Request( $method, '/talenttrack/v1/' . $route );
        if ( $method === 'GET' ) {
            if ( $params !== [] ) $req->set_query_params( $params );
        } else {
            $req->set_header( 'Content-Type', 'application/json' );
            $req->set_body( (string) wp_json_encode( (object) $params ) );
        }
        $this->flush();
        return rest_do_request( $req );
    }

    private function code( WP_REST_Response $res ): string {
        return (string) ( $res->get_data()['errors'][0]['code'] ?? '' );
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        \TT\Modules\Authorization\MatrixGate::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        FactScope::flush();
        SharedReads::flush();
        wp_cache_flush();
    }

    private function resetMatrixActiveCaches(): void {
        foreach ( [ TileRegistry::class, DashboardShortcode::class ] as $class ) {
            $prop = new \ReflectionProperty( $class, 'matrix_active_cache' );
            $prop->setAccessible( true );
            $prop->setValue( null, null );
        }
    }
}
