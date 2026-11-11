<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #5074 — "open goal" has one rule: `GoalStatus::OPEN`, applied through
 * `GoalLifecycle` (or `GoalsRepository::activeStatusClause()`, which
 * delegates to it). There were seven copies, each with its own list, so the
 * profile, the dashboard widget, the alert and the KPI export could each
 * count a different number for the same player.
 *
 * This test fails on a new literal list of goal statuses in a PHP file that
 * deals with goals: two status literals (or `GoalStatus::` constants) side
 * by side, or the old `status NOT IN ( 'completed' …` shape. A list that is
 * not an open-goal rule is named below with its reason.
 */
final class GoalLifecycleGuardTest extends WP_UnitTestCase {

    /** The one place the open set is written down. */
    private const OWNER = 'src/Domain/Vocabularies/Lookups/GoalStatus.php';

    /**
     * Lists that are not an open-goal rule: file => a fragment of the line.
     *
     * @var array<string, list<string>>
     */
    private const NOT_AN_OPEN_RULE = [
        // The export's single-status filter values, one status at a time.
        'src/Modules/Export/Exporters/GoalsCsvExporter.php' => [ "'pending', 'in_progress', 'completed', 'archived', 'all'" ],
        // The seeded lookup vocabularies (plan states, approval decisions, tasks).
        'src/Modules/Configuration/LookupCanonicalSeeds.php' => [ "'draft', 'planned', 'scheduled', 'in_progress', 'completed'", "'goal_approval_decision'", "'open', 'in_progress', 'completed', 'overdue', 'skipped', 'cancelled'" ],
        // The open PDP files, not goals.
        'src/Modules/AdminCenterClient/PayloadBuilder.php' => [ 'tt_pdp_files' ],
    ];

    public function test_no_file_outside_goal_status_holds_a_literal_goal_status_list(): void {
        $root  = dirname( __DIR__, 2 );
        $found = [];
        $files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $files as $file ) {
            if ( ! $file instanceof \SplFileInfo || $file->getExtension() !== 'php' ) continue;
            $path = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
            if ( $path === self::OWNER ) continue;
            $code = (string) file_get_contents( $file->getPathname() );
            if ( preg_match( '/tt_goals|GoalStatus|goal_status|GoalsRepository/', $code ) !== 1 ) continue;
            foreach ( self::statusLists( $code, self::NOT_AN_OPEN_RULE[ $path ] ?? [] ) as $line ) {
                $found[] = $path . ':' . $line;
            }
        }
        $this->assertSame( [], $found, 'Decide "open goal" through GoalLifecycle::openClause() / notOpenClause() / isOpen() (GoalStatus::OPEN), not a list of your own.' );
    }

    public function test_the_guard_finds_a_planted_list(): void {
        $planted = "<?php\n"
            . "\$sql = \"SELECT COUNT(*) FROM tt_goals WHERE status IN ('pending', 'in_progress')\";\n"
            . "\$open = ! in_array( \$g->status, [ GoalStatus::COMPLETED, GoalStatus::CANCELLED ], true );\n"
            . "\$sql .= \" AND ( status IS NULL OR status NOT IN ( 'completed' ) )\";\n"
            . "// a comment that names 'completed', 'cancelled' is not code\n"
            . "\$fine = GoalLifecycle::openClause( 'g' );\n";
        $this->assertSame( [ 2, 3, 4 ], self::statusLists( $planted, [] ) );
    }

    /**
     * Line numbers of literal goal-status lists in code (comments skipped),
     * leaving out lines that carry one of the allowed fragments.
     *
     * @param list<string> $allowed
     * @return list<int>
     */
    private static function statusLists( string $code, array $allowed ): array {
        $status = '(?:pending|pending_approval|in_progress|completed|cancelled|on_hold|approved)';
        $item   = "(?:['\"]{$status}['\"]|GoalStatus::(?:PENDING|PENDING_APPROVAL|IN_PROGRESS|COMPLETED|CANCELLED|ON_HOLD))";
        $pair   = '/' . $item . '\s*,\s*' . $item . '/i';
        $not_in = '/status\s+NOT\s+IN\s*\(\s*[\'"](?:completed|cancelled)[\'"]/i';

        $out = [];
        foreach ( preg_split( '/\R/', $code ) ?: [] as $i => $line ) {
            $trim = ltrim( $line );
            if ( $trim === '' || str_starts_with( $trim, '*' ) || str_starts_with( $trim, '//' ) || str_starts_with( $trim, '/*' ) || str_starts_with( $trim, '#' ) ) continue;
            if ( preg_match( $pair, $line ) !== 1 && preg_match( $not_in, $line ) !== 1 ) continue;
            foreach ( $allowed as $fragment ) {
                if ( strpos( $line, $fragment ) !== false ) continue 2;
            }
            $out[] = $i + 1;
        }
        return $out;
    }
}
