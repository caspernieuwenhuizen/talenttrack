<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #3741 — the Injuries help page must name the role that carries the
 * injury log.
 *
 * The gate itself is correct and asserted elsewhere
 * (`FunctionalRoleAccessTest`, `ManagerFunctionalRoleTest`). What went
 * wrong is documentation drift: the "Who can do what" table was written
 * before functional roles existed, so it named WordPress personas only
 * and left the one row that decides a team manager's case — the Physio
 * functional role — off the page entirely. The person pitchside when a
 * knock happens read the page, found no row for themselves, and the
 * injury went onto a paper first-aid sheet.
 *
 * So this test does not re-check the gate. It checks that the page and
 * `config/functional_role_grants.php` still say the same thing, in both
 * languages, which is the failure this issue is named for.
 */
final class InjuryDocPermissionTableTest extends WP_UnitTestCase {

    /**
     * Per-language vocabulary for the same table.
     *
     * @var array<string, array<string, string>>
     */
    private const PAGES = [
        'docs/injuries.md' => [
            'heading' => '## Who can do what',
            'physio'  => 'Physio',
            'other'   => 'any other functional role',
            'no'      => 'No',
            'route'   => 'second functional role',
        ],
        'docs/nl_NL/injuries.md' => [
            'heading' => '## Wie mag wat',
            'physio'  => 'Fysio',
            'other'   => 'andere functionele rol',
            'no'      => 'Nee',
            'route'   => 'tweede functionele rol',
        ],
    ];

    // ── the grant config, which the page has to match ──────────────────

    /**
     * @return array<string, array<string, array<int, string>>>
     */
    private function grants(): array {
        /** @var array<string, mixed> $config */
        $config = require TT_PLUGIN_DIR . 'config/functional_role_grants.php';

        $grants = isset( $config['grants'] ) && is_array( $config['grants'] )
            ? $config['grants']
            : [];

        /** @var array<string, array<string, array<int, string>>> $grants */
        return $grants;
    }

    /**
     * The closed list of functional roles that reach an injury record.
     *
     * If a future decision puts `player_injuries` on a second role, this
     * fails and the page has to gain a row — which is the whole point.
     *
     * @return list<string>
     */
    private function rolesWithInjuryAccess(): array {
        $keys = [];

        foreach ( $this->grants() as $role_key => $entities ) {
            if ( isset( $entities['player_injuries'] ) ) {
                $keys[] = (string) $role_key;
            }
        }

        sort( $keys );

        return array_values( $keys );
    }

    public function test_only_the_physio_functional_role_carries_the_injury_log(): void {
        $this->assertSame(
            [ 'physio' ],
            $this->rolesWithInjuryAccess(),
            'The help page names Physio as the only functional role with injury access. '
                . 'If that changed, docs/injuries.md and docs/nl_NL/injuries.md need a row for the new role.'
        );
    }

    public function test_no_functional_role_may_delete_an_injury(): void {
        $grants     = $this->grants();
        $activities = isset( $grants['physio']['player_injuries'][0] )
            ? (string) $grants['physio']['player_injuries'][0]
            : '';

        $this->assertNotSame( '', $activities, 'the physio grant on player_injuries went missing' );
        $this->assertStringContainsString( 'r', $activities, 'a physio reads the injury records of their squads' );
        $this->assertStringContainsString( 'c', $activities, 'recording the injury is the job' );
        $this->assertStringNotContainsString(
            'd',
            $activities,
            'Both help pages print "No" in the Delete column for the physio row. '
                . 'Deleting a minor\'s medical record stays with head of development and academy admin.'
        );
    }

    // ── the pages ──────────────────────────────────────────────────────

    /**
     * The rows of the permission table under the "who can do what"
     * heading, header and separator dropped.
     *
     * @return list<string>
     */
    private function permissionRows( string $relative_path, string $heading ): array {
        $source = (string) file_get_contents( TT_PLUGIN_DIR . $relative_path );
        $this->assertNotSame( '', $source, $relative_path . ' is missing or empty' );

        $offset = strpos( $source, $heading );
        $this->assertNotFalse( $offset, $relative_path . ' has no "' . $heading . '" section' );

        $rows = [];

        foreach ( explode( "\n", substr( $source, (int) $offset ) ) as $line ) {
            $line = trim( $line );

            if ( '' === $line || 0 !== strpos( $line, '|' ) ) {
                if ( $rows !== [] ) {
                    break; // past the table
                }
                continue;
            }

            if ( false !== strpos( $line, '---' ) ) {
                continue; // separator
            }

            $rows[] = $line;
        }

        // The first pipe line is the header ("| | See injuries | …").
        array_shift( $rows );

        $this->assertNotEmpty( $rows, $relative_path . ' has no permission table under ' . $heading );

        return array_values( $rows );
    }

    /**
     * @param list<string> $rows
     * @return list<string>
     */
    private function rowMatching( array $rows, string $needle ): array {
        $cells = [];

        foreach ( $rows as $row ) {
            if ( false === stripos( $row, $needle ) ) {
                continue;
            }

            $this->assertSame( [], $cells, 'more than one row mentions "' . $needle . '"' );

            $parts = array_map( 'trim', explode( '|', trim( $row, '|' ) ) );
            $cells = array_values( $parts );
        }

        $this->assertNotSame( [], $cells, 'no row mentions "' . $needle . '"' );

        return $cells;
    }

    public function test_both_pages_give_the_physio_functional_role_a_row(): void {
        foreach ( self::PAGES as $path => $words ) {
            $rows  = $this->permissionRows( (string) $path, $words['heading'] );
            $cells = $this->rowMatching( $rows, $words['physio'] );

            $this->assertCount( 4, $cells, $path . ': the physio row needs label + see + record + delete' );

            $this->assertNotSame(
                $words['no'],
                $cells[1],
                $path . ': a physio sees the injuries of the teams they are the physio of'
            );
            $this->assertNotSame(
                $words['no'],
                $cells[2],
                $path . ': recording the injury is the job'
            );
            $this->assertSame(
                $words['no'],
                $cells[3],
                $path . ': a physio may not delete an injury record'
            );
        }
    }

    public function test_both_pages_say_other_functional_roles_get_nothing(): void {
        foreach ( self::PAGES as $path => $words ) {
            $rows  = $this->permissionRows( (string) $path, $words['heading'] );
            $cells = $this->rowMatching( $rows, $words['other'] );

            $this->assertCount( 4, $cells, $path . ': the row needs label + see + record + delete' );

            $this->assertSame( $words['no'], $cells[1], $path . ': a manager sees no injuries' );
            $this->assertSame( $words['no'], $cells[2], $path . ': a manager records no injuries' );
            $this->assertSame( $words['no'], $cells[3], $path . ': a manager deletes no injuries' );
        }
    }

    /**
     * The row alone would leave the reader refused with no way forward.
     * The page has to say what to ask for.
     */
    public function test_both_pages_name_the_second_functional_role_route(): void {
        foreach ( self::PAGES as $path => $words ) {
            $source = (string) file_get_contents( TT_PLUGIN_DIR . (string) $path );

            $this->assertStringContainsString(
                $words['route'],
                $source,
                $path . ': a first-aider refused the injury log needs to read what to ask their admin for'
            );
        }
    }

    /** The Dutch page is the one the pilot reads; it must not fall behind. */
    public function test_the_two_pages_carry_the_same_number_of_rows(): void {
        $counts = [];

        foreach ( self::PAGES as $path => $words ) {
            $counts[ (string) $path ] = count( $this->permissionRows( (string) $path, $words['heading'] ) );
        }

        $this->assertSame(
            $counts['docs/injuries.md'],
            $counts['docs/nl_NL/injuries.md'],
            'the English and Dutch permission tables have drifted apart'
        );
    }
}
