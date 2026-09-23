<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\Exporters\ActivityBriefPdfExporter;
use TT\Modules\Export\Exporters\GdprSubjectAccessZipExporter;
use TT\Modules\Export\Exporters\TeamIcalExporter;
use TT\Modules\Export\Exporters\TeamPlanningPdfExporter;
use TT\Modules\Goals\Print\PlayerGoalIntakePrintRouter;
use TT\Modules\MatchAnalysis\MatchAnalysisEnums;
use TT\Modules\MatchAnalysis\Print\MatchAnalysisPrintRouter;
use TT\Modules\MatchAnalysis\Repositories\MatchAnalysisRepository;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisWriter;
use TT\Modules\MatchPrep\Print\MatchPrepPrintRouter;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;
use TT\Modules\Methodology\MethodologyEnums;
use TT\Modules\Planning\Print\TeamPlannerWeeklyPrintRouter;
use TT\Modules\Training\Print\TrainingPlanPrintRouter;

/**
 * #4000 — a print router or a file exporter that takes a record id checks
 * which record it was asked for.
 *
 * Every capability these surfaces guard themselves with is held club-wide,
 * so it answers whether the caller prints team sheets, never whose. What is
 * frozen here is that each surface serves a record inside the caller's scope
 * and answers one outside it exactly as it answers an id that does not
 * exist — the grant assertion beside every refusal, because a guard that
 * refused the caller's own team would be the worse bug.
 */
final class RecordScopePrintExportTest extends WP_UnitTestCase {

    private int $admin        = 0;
    private int $coach        = 0;
    private int $mineTeam     = 0;
    private int $otherTeam    = 0;
    private int $mineActivity = 0;
    private int $otherActivity = 0;
    private int $minePlayer   = 0;
    private int $otherPlayer  = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Scope team mine' ] );
        $this->mineTeam = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Scope team theirs' ] );
        $this->otherTeam = (int) $wpdb->insert_id;

        $this->minePlayer  = $this->makePlayer( 'Scopealpha', $this->mineTeam );
        $this->otherPlayer = $this->makePlayer( 'Scopebravo', $this->otherTeam );

        $this->mineActivity  = $this->makeMatch( $this->mineTeam, 'Scope match mine', 'Ajax U17', $this->minePlayer );
        $this->otherActivity = $this->makeMatch( $this->otherTeam, 'Scope match theirs', 'Feyenoord U17', $this->otherPlayer );

        $this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->coach = $this->makeHeadCoach( $this->mineTeam );

        wp_set_current_user( $this->admin );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        $_GET = [];
        parent::tear_down();
    }

    // -----------------------------------------------------------------
    // Print routers
    // -----------------------------------------------------------------

    public function test_the_match_prep_sheet_prints_the_coachs_own_match_and_not_another_teams(): void {
        $repo = new MatchPrepRepository();
        $repo->ensureForActivity( $this->mineActivity );
        $repo->ensureForActivity( $this->otherActivity );

        $this->asCoach();

        $mine = MatchPrepPrintRouter::renderHtml( $this->mineActivity );
        $this->assertStringContainsString( 'Ajax U17', $mine, 'the grant: the squad coach prints their own match plan' );

        $theirs  = MatchPrepPrintRouter::renderHtml( $this->otherActivity );
        $missing = MatchPrepPrintRouter::renderHtml( $this->otherActivity + 100000 );
        $this->assertStringNotContainsString( 'Feyenoord U17', $theirs );
        $this->assertStringContainsString( 'Geen wedstrijdvoorbereiding gevonden voor deze activiteit.', $theirs, 'answered as not found' );
        $this->assertStringContainsString( 'Geen wedstrijdvoorbereiding gevonden voor deze activiteit.', $missing, 'and a missing id says the same thing' );
    }

    public function test_the_team_sheet_prints_the_coachs_own_match_and_not_another_teams(): void {
        $this->asCoach();

        $mine = MatchPrepPrintRouter::renderTeamSheetHtml( $this->mineActivity );
        $this->assertStringContainsString( 'Ajax U17', $mine, 'the grant: the squad coach prints their own team sheet' );

        $theirs  = MatchPrepPrintRouter::renderTeamSheetHtml( $this->otherActivity );
        $missing = MatchPrepPrintRouter::renderTeamSheetHtml( $this->otherActivity + 100000 );
        $this->assertStringNotContainsString( 'Feyenoord U17', $theirs );
        $this->assertStringNotContainsString( 'Scopebravo', $theirs, 'and no squad list leaks with it' );
        $this->assertStringContainsString( 'No team sheet found for this activity.', $theirs );
        $this->assertStringContainsString( 'No team sheet found for this activity.', $missing );
    }

    public function test_the_match_analysis_prints_the_coachs_own_match_and_not_another_teams(): void {
        $this->writeAnalysis( $this->mineActivity, 'Rustig opgebouwd langs de zijkant.' );
        $this->writeAnalysis( $this->otherActivity, 'Tweede bal na hun corners.' );

        $this->asCoach();

        $mine = MatchAnalysisPrintRouter::renderHtml( $this->mineActivity );
        $this->assertStringContainsString( 'Rustig opgebouwd langs de zijkant.', $mine, 'the grant: the squad coach prints their own analysis' );

        $theirs  = MatchAnalysisPrintRouter::renderHtml( $this->otherActivity );
        $missing = MatchAnalysisPrintRouter::renderHtml( $this->otherActivity + 100000 );
        $this->assertStringNotContainsString( 'Tweede bal na hun corners.', $theirs );
        $this->assertStringContainsString( 'Nothing has been written for this match yet.', $theirs );
        $this->assertStringContainsString( 'Nothing has been written for this match yet.', $missing );
    }

    public function test_the_weekly_planner_sheet_is_built_for_a_team_the_caller_may_read(): void {
        $this->asCoach();

        $this->assertSame( $this->mineTeam, TeamPlannerWeeklyPrintRouter::scopedTeamId( $this->mineTeam ), 'the grant: the coach prints their own week' );
        $this->assertSame( 0, TeamPlannerWeeklyPrintRouter::scopedTeamId( $this->otherTeam ) );
        $this->assertSame( 0, TeamPlannerWeeklyPrintRouter::scopedTeamId( $this->otherTeam + 100000 ), 'a refused team reads as one that is not there' );
    }

    public function test_the_training_plan_sheet_is_built_for_a_plan_whose_team_the_caller_may_read(): void {
        $mine     = $this->makeTrainingPlan( $this->mineTeam, 'Scope plan mine' );
        $theirs   = $this->makeTrainingPlan( $this->otherTeam, 'Scope plan theirs' );
        $clubWide = $this->makeTrainingPlan( 0, 'Scope plan template' );

        $this->asCoach();

        $this->assertSame( $mine, TrainingPlanPrintRouter::scopedPlanId( $mine ), 'the grant: the coach prints their own plan' );
        $this->assertSame( $clubWide, TrainingPlanPrintRouter::scopedPlanId( $clubWide ), 'a plan with no team is a club-wide template and stays open' );
        $this->assertSame( 0, TrainingPlanPrintRouter::scopedPlanId( $theirs ) );
    }

    public function test_the_goal_intake_batch_refuses_a_squad_the_caller_may_not_read(): void {
        $this->asCoach();

        $this->assertSame( 'rendered', $this->batchOutcome( $this->mineTeam ), 'the grant: the coach prints their own squad batch' );
        $this->assertSame( 'Team not found.', $this->batchOutcome( $this->otherTeam ) );
        $this->assertSame( 'Team not found.', $this->batchOutcome( $this->otherTeam + 100000 ), 'a refused squad reads as one that is not there' );
    }

    // -----------------------------------------------------------------
    // Exporters — collect() is the authoritative check on the pipeline
    // -----------------------------------------------------------------

    public function test_the_activity_brief_export_refuses_another_teams_activity(): void {
        $exporter = new ActivityBriefPdfExporter();

        $mine = $exporter->collect( $this->exportRequest( 'activity_brief_pdf', 'pdf', $this->coach, null, [ 'activity_id' => $this->mineActivity ] ) );
        $this->assertStringContainsString( 'Scope match mine', (string) $mine['html'], 'the grant: the coach exports their own brief' );

        $theirs  = $this->refusal( fn (): array => $exporter->collect( $this->exportRequest( 'activity_brief_pdf', 'pdf', $this->coach, null, [ 'activity_id' => $this->otherActivity ] ) ) );
        $missing = $this->refusal( fn (): array => $exporter->collect( $this->exportRequest( 'activity_brief_pdf', 'pdf', $this->coach, null, [ 'activity_id' => $this->otherActivity + 100000 ] ) ) );
        $this->assertSame( [ 'forbidden', "You do not coach this activity's team." ], $theirs );
        $this->assertSame( $theirs, $missing, 'a refusal reads exactly as a missing activity' );

        $asAdmin = $exporter->collect( $this->exportRequest( 'activity_brief_pdf', 'pdf', $this->admin, null, [ 'activity_id' => $this->otherActivity ] ) );
        $this->assertStringContainsString( 'Scope match theirs', (string) $asAdmin['html'], 'and the academy admin still reaches every team' );
    }

    public function test_the_team_calendar_feed_refuses_another_teams_activities(): void {
        $exporter = new TeamIcalExporter();

        $mine = $exporter->collect( $this->exportRequest( 'team_ical', 'ics', $this->coach, $this->mineTeam ) );
        $this->assertNotSame( [], $mine['events'], 'the grant: the coach subscribes to their own team' );

        $theirs  = $this->refusal( fn (): array => $exporter->collect( $this->exportRequest( 'team_ical', 'ics', $this->coach, $this->otherTeam ) ) );
        $missing = $this->refusal( fn (): array => $exporter->collect( $this->exportRequest( 'team_ical', 'ics', $this->coach, $this->otherTeam + 100000 ) ) );
        $this->assertSame( [ 'forbidden', 'You do not have access to this team.' ], $theirs, 'a refused feed is a message, never an empty calendar' );
        $this->assertSame( $theirs, $missing, 'a refusal reads exactly as a team that is not there' );
    }

    public function test_the_team_planning_pdf_refuses_another_teams_schedule(): void {
        $exporter = new TeamPlanningPdfExporter();
        $filters  = [ 'date_from' => '2000-01-01', 'date_to' => '2099-12-31', 'layout' => 'table' ];

        $mine = $exporter->collect( $this->exportRequest(
            'team_planning', 'pdf', $this->coach, null, [ 'team_id' => $this->mineTeam ] + $filters
        ) );
        $this->assertStringContainsString( 'Scope match mine', (string) $mine['html'], 'the grant: the coach prints their own schedule' );

        $theirs = $this->refusal( fn (): array => $exporter->collect( $this->exportRequest(
            'team_planning', 'pdf', $this->coach, null, [ 'team_id' => $this->otherTeam ] + $filters
        ) ) );
        $missing = $this->refusal( fn (): array => $exporter->collect( $this->exportRequest(
            'team_planning', 'pdf', $this->coach, null, [ 'team_id' => $this->otherTeam + 100000 ] + $filters
        ) ) );
        $this->assertSame( [ 'forbidden', 'You do not have access to this team.' ], $theirs );
        $this->assertSame( $theirs, $missing, 'a refusal reads exactly as a team that is not there' );
    }

    public function test_the_gdpr_archive_refuses_a_player_outside_the_callers_scope_and_records_it(): void {
        $exporter = new GdprSubjectAccessZipExporter();

        $mine = $exporter->collect( $this->exportRequest( 'gdpr_subject_access_zip', 'zip', $this->coach, null, [ 'player_id' => $this->minePlayer ] ) );
        $this->assertArrayHasKey( 'profile.json', $mine['entries'], 'the grant: a caller within scope gets the archive' );

        $theirs  = $exporter->collect( $this->exportRequest( 'gdpr_subject_access_zip', 'zip', $this->coach, null, [ 'player_id' => $this->otherPlayer ] ) );
        $missing = $exporter->collect( $this->exportRequest( 'gdpr_subject_access_zip', 'zip', $this->coach, null, [ 'player_id' => $this->otherPlayer + 100000 ] ) );
        $this->assertArrayNotHasKey( 'profile.json', $theirs['entries'] );
        $this->assertSame( (string) wp_json_encode( $missing ), (string) wp_json_encode( $theirs ), 'a refusal reads exactly as a player who is not there' );

        $this->assertSame(
            1,
            $this->auditCount( 'gdpr.subject_access_export', $this->minePlayer ),
            'the delivered archive names who exported which player'
        );
        $this->assertSame(
            1,
            $this->auditCount( 'gdpr.subject_access_refused', $this->otherPlayer ),
            'and so does the refusal — an attempt on a child\'s file belongs in the trail'
        );
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function asCoach(): void {
        wp_set_current_user( $this->coach );
        AuthorizationService::flushCache();
        $this->assertTrue( current_user_can( 'tt_view_activities' ), 'the coach holds the club-wide capability, so a refusal comes from the per-record check' );
    }

    /**
     * The `[ errorKey, message ]` a `collect()` refuses with. Fails the test
     * when the call succeeds, so a guard that stopped refusing cannot pass
     * here quietly.
     *
     * @param callable():array<mixed> $call
     * @return array{0:string,1:string}
     */
    private function refusal( callable $call ): array {
        try {
            $call();
        } catch ( \TT\Modules\Export\ExportException $e ) {
            return [ $e->errorKey, $e->getMessage() ];
        }
        $this->fail( 'the exporter was expected to refuse' );
    }

    /** The die message a team batch answers with, or 'rendered' when it produced a document. */
    private function batchOutcome( int $team_id ): string {
        $level = ob_get_level();
        try {
            PlayerGoalIntakePrintRouter::renderTeamBatch( $team_id, '2026/27', $this->allBlocks() );
            return 'rendered';
        } catch ( \WPDieException $e ) {
            return $e->getMessage();
        } finally {
            while ( ob_get_level() > $level ) ob_end_clean();
        }
    }

    /** @return array<string,bool> */
    private function allBlocks(): array {
        return array_fill_keys(
            [ 'snapshot', 'doel1', 'doel2', 'doel3', 'afsluiting', 'handtekeningen', 'reminder' ],
            true
        );
    }

    /** @param array<string,mixed> $filters */
    private function exportRequest( string $key, string $format, int $user_id, ?int $entity_id = null, array $filters = [] ): ExportRequest {
        return new ExportRequest( $key, $format, (int) CurrentClub::id(), $user_id, $entity_id, $filters );
    }

    private function writeAnalysis( int $activity_id, string $note ): void {
        $analysis_id = ( new MatchAnalysisRepository() )->ensureForActivity( $activity_id );
        ( new MatchAnalysisWriter() )->apply( $analysis_id, [
            'sections' => [
                MethodologyEnums::FUNCTION_AANVALLEN => [
                    'rating' => MatchAnalysisEnums::RATING_WENT_WELL,
                    'notes'  => [ $note ],
                ],
            ],
        ], [] );
    }

    /**
     * Today, so the iCal feed's default window (a month back, a year ahead
     * of the moment the suite runs) contains the fixture whatever the date.
     */
    private function matchDate(): string {
        return (string) current_time( 'Y-m-d' );
    }

    private function makeMatch( int $team_id, string $title, string $opponent, int $player_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'           => (int) CurrentClub::id(),
            'team_id'           => $team_id,
            'title'             => $title,
            'session_date'      => $this->matchDate(),
            'activity_type_key' => 'match',
            'opponent'          => $opponent,
            'home_away'         => 'home',
        ] );
        $activity_id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $activity_id, 'the fixture must write the activity' );

        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
            'club_id'        => (int) CurrentClub::id(),
            'activity_id'    => $activity_id,
            'player_id'      => $player_id,
            'status'         => 'present',
            'record_type'    => 'actual',
            'minutes_played' => 70,
            'is_guest'       => 0,
        ] );
        return $activity_id;
    }

    private function makeTrainingPlan( int $team_id, string $title ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_training_plans", [
            'club_id'        => (int) CurrentClub::id(),
            'uuid'           => wp_generate_uuid4(),
            'team_id'        => $team_id > 0 ? $team_id : null,
            'title'          => $title,
            'author_user_id' => $this->admin,
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'the fixture must write the training plan' );
        return $id;
    }

    private function makePlayer( string $last, int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'       => (int) CurrentClub::id(),
            'first_name'    => 'Scope',
            'last_name'     => $last,
            'team_id'       => $team_id,
            'status'        => 'active',
            'date_of_birth' => '2011-04-02',
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'the fixture must write the player' );
        return $id;
    }

    private function makeHeadCoach( int $team_id ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => (int) CurrentClub::id(),
            'first_name' => 'Hoofd',
            'last_name'  => 'Trainer',
            'role_type'  => 'head_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $person_id, 'the fixture must write the person' );

        $wpdb->insert( "{$p}tt_team_people", [
            'team_id'       => $team_id,
            'person_id'     => $person_id,
            'role_in_team'  => 'head_coach',
            'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        AuthorizationService::flushCache();
        $this->assertTrue( AuthorizationService::canViewPlayer( $uid, $this->minePlayer ), 'the coach must reach their own squad, or every refusal below is vacuous' );
        $this->assertFalse( AuthorizationService::canViewPlayer( $uid, $this->otherPlayer ), 'the other squad is outside the coach\'s scope' );
        return $uid;
    }

    private function auditCount( string $action, int $entity_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_audit_log WHERE action = %s AND entity_type = %s AND entity_id = %d",
            $action,
            'player',
            $entity_id
        ) );
    }
}
