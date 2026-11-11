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
 * by side, in either spelling (`in_progress` or `In Progress`), on one line
 * or one per line; or the old `status NOT IN ( 'completed' …` shape. A list
 * that is not an open-goal rule is named below with its reason.
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
        // The seeded lookup vocabularies (goal statuses, plan states,
        // approval decisions, tasks): the rows the lookup is created with.
        'src/Modules/Configuration/LookupCanonicalSeeds.php' => [ "'goal_status'   =>", "'draft', 'planned', 'scheduled', 'in_progress', 'completed'", "'goal_approval_decision'", "'open', 'in_progress', 'completed', 'overdue', 'skipped', 'cancelled'" ],
        'src/Core/Activator.php' => [ "[ 'Pending','In Progress','Completed','On Hold','Cancelled' ]" ],
        // Demo data picking which lookup spelling exists to write.
        'src/Modules/DemoData/Generators/GoalGenerator.php' => [ 'firstMatching(' ],
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
            . "\$fine = GoalLifecycle::openClause( 'g' );\n"
            . "\$sql = \"WHERE status IN ('In Progress', 'Pending')\";\n"
            . "\$open = [\n"
            . "    'on hold',\n"
            . "    GoalStatus::IN_PROGRESS,\n"
            . "];\n"
            . "\$one = 'completed';\n";
        $this->assertSame( [ 2, 3, 4, 7, 9 ], self::statusLists( $planted, [] ), 'one line, TitleCase, and one item per line (reported where it starts)' );
    }

    /**
     * Line numbers of literal goal-status lists in code (comments skipped),
     * leaving out lines that carry one of the allowed fragments.
     *
     * @param list<string> $allowed
     * @return list<int>
     */
    private static function statusLists( string $code, array $allowed ): array {
        // Both spellings: the snake_case codes and the TitleCase lookup names.
        $status = '(?:pending|pending[ _]approval|in[ _]progress|completed|cancelled|on[ _]hold|approved)';
        $item   = "(?:['\"]{$status}['\"]|GoalStatus::(?:PENDING|PENDING_APPROVAL|IN_PROGRESS|COMPLETED|CANCELLED|ON_HOLD))";
        $pair   = '/' . $item . '\s*,\s*' . $item . '/i';
        // A list written one item per line: an item alone on its line, after
        // another such line.
        $alone  = '/^\s*' . $item . '\s*,?\s*$/i';
        $not_in = '/status\s+NOT\s+IN\s*\(\s*[\'"](?:completed|cancelled)[\'"]/i';

        $out      = [];
        $previous = null;
        foreach ( preg_split( '/\R/', $code ) ?: [] as $i => $line ) {
            $trim = ltrim( $line );
            if ( $trim === '' ) continue;
            if ( str_starts_with( $trim, '*' ) || str_starts_with( $trim, '//' ) || str_starts_with( $trim, '/*' ) || str_starts_with( $trim, '#' ) ) {
                $previous = null;
                continue;
            }
            $is_alone    = preg_match( $alone, $line ) === 1;
            $after_alone = $previous;
            $previous    = $is_alone ? $i + 1 : null;

            $hit = null;
            if ( preg_match( $pair, $line ) === 1 || preg_match( $not_in, $line ) === 1 ) {
                $hit = $i + 1;
            } elseif ( $is_alone && $after_alone !== null ) {
                // Report the list where it starts, once.
                $hit = $after_alone;
            }
            if ( $hit === null || in_array( $hit, $out, true ) ) continue;
            foreach ( $allowed as $fragment ) {
                if ( strpos( $line, $fragment ) !== false ) continue 2;
            }
            $out[] = $hit;
        }
        return $out;
    }
}
