<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Trials\Letters\LetterTemplateEngine;

/**
 * #4533 — Dutch msgids in the printed goal intake, the methodology cards,
 * the match-prep print, the trial acceptance slip and two admin pages.
 *
 * Each became an English msgid whose fragment entry carries the Dutch the
 * page printed before. The compiled catalogue in CI predates the fragment,
 * so "Dutch output is unchanged" is proved from the files: every fragment
 * msgstr is exactly an old msgid the catalogue ships, translated to itself.
 */
final class DutchMsgidPrintsTest extends WP_UnitTestCase {

    private const FILES = [
        'src/Modules/Goals/Print/PlayerGoalIntakePrintRouter.php',
        'src/Modules/Methodology/Print/MethodologyReferencePrintRouter.php',
        'src/Modules/Methodology/MethodologyEnums.php',
        'src/Modules/Methodology/Admin/FootballActionsPage.php',
        'src/Modules/MatchPrep/Print/MatchPrepPrintRouter.php',
        'src/Modules/MatchPrep/Print/MatchPrepPrintableRenderer.php',
        'src/Modules/Trials/Letters/LetterTemplateEngine.php',
        'src/Modules/Evaluations/Admin/EvalTypeCategoriesPage.php',
    ];

    private function root(): string {
        return dirname( __DIR__, 2 );
    }

    public function set_up(): void {
        parent::set_up();
        require_once $this->root() . '/tools/lib/po.php';
        require_once $this->root() . '/tools/lib/gettext-calls.php';
    }

    /**
     * The converted entries. While the fragment is pending it is the record.
     * A release consolidates it into the catalogue and deletes it; from then
     * on the fixture copy of the fragment as it shipped names the entries,
     * and the catalogue supplies their Dutch.
     *
     * @return list<array{msgctxt:string, msgid:string, msgstr:string}>
     */
    private function fragment(): array {
        $path    = $this->root() . '/languages/pending/4533-dutch-msgids.po';
        $pending = is_readable( $path );
        if ( ! $pending ) {
            $path = __DIR__ . '/fixtures/i18n/4533-dutch-msgids.po';
            $shipped = [];
            foreach ( tt_po_parse( (string) file_get_contents( $this->root() . '/languages/talenttrack-nl_NL.po' ) )['entries'] as $entry ) {
                if ( ! empty( $entry['obsolete'] ) || $entry['msgid'] === null ) continue;
                $shipped[ tt_po_key( (string) $entry['msgctxt'], (string) $entry['msgid'] ) ] = (string) ( $entry['msgstr'][0] ?? '' );
            }
        }
        $parsed = tt_po_parse( (string) file_get_contents( $path ) );
        $out    = [];
        foreach ( $parsed['entries'] as $entry ) {
            if ( $entry['msgid'] === null || $entry['msgid'] === '' ) continue;
            $msgstr = (string) ( $entry['msgstr'][0] ?? '' );
            if ( ! $pending ) {
                $key = tt_po_key( (string) $entry['msgctxt'], (string) $entry['msgid'] );
                $this->assertArrayHasKey( $key, $shipped, "the catalogue carries the converted msgid: {$entry['msgid']}" );
                $msgstr = $shipped[ $key ];
            }
            $out[] = [
                'msgctxt' => (string) $entry['msgctxt'],
                'msgid'   => (string) $entry['msgid'],
                'msgstr'  => $msgstr,
            ];
        }
        return $out;
    }

    /** @return array<string, string> msgid => msgstr, no context */
    private function catalogue(): array {
        $parsed = tt_po_parse( (string) file_get_contents( $this->root() . '/languages/talenttrack-nl_NL.po' ) );
        $out    = [];
        foreach ( $parsed['entries'] as $entry ) {
            if ( ! empty( $entry['obsolete'] ) || $entry['msgid'] === null || (string) $entry['msgctxt'] !== '' ) continue;
            $out[ (string) $entry['msgid'] ] = (string) ( $entry['msgstr'][0] ?? '' );
        }
        return $out;
    }

    /**
     * The Dutch printed today is the catalogue's msgstr for the old Dutch
     * msgid. Usually that is the msgid itself; once (the eval-type
     * categories notice) the catalogue had corrected it, and the corrected
     * wording is what an install shows.
     */
    public function test_every_fragment_msgstr_is_the_dutch_printed_today(): void {
        $printed  = array_flip( array_values( $this->catalogue() ) );
        $fragment = $this->fragment();
        $this->assertCount( 28, $fragment, 'one entry per converted msgid' );

        foreach ( $fragment as $entry ) {
            $this->assertArrayHasKey( $entry['msgstr'], $printed, "a Dutch install prints this today: {$entry['msgstr']}" );
            $this->assertNotSame( $entry['msgstr'], $entry['msgid'], 'the new msgid is English' );
        }
    }

    public function test_no_converted_file_still_uses_an_old_dutch_msgid(): void {
        $catalogue = $this->catalogue();
        $old       = [];
        foreach ( $this->fragment() as $entry ) {
            // The old msgid: the catalogue entry that prints this Dutch.
            foreach ( array_keys( $catalogue, $entry['msgstr'], true ) as $msgid ) {
                $msgid = (string) $msgid;
                // A Dutch msgid is the Dutch itself, or an earlier wording of
                // it; an English msgid that also translates to it is not one.
                similar_text( $msgid, $entry['msgstr'], $percent );
                if ( $msgid !== $entry['msgid'] && $percent >= 90 ) $old[] = $msgid;
            }
        }
        $this->assertNotEmpty( $old );

        foreach ( self::FILES as $relative ) {
            foreach ( tt_gettext_calls( (string) file_get_contents( $this->root() . '/' . $relative ) ) as $call ) {
                $this->assertNotContains( (string) $call['msgid'], $old, "{$relative}:{$call['line']} still uses a Dutch msgid" );
            }
        }
    }

    public function test_the_trial_acceptance_slip_reads_english_on_an_english_install(): void {
        $method = new \ReflectionMethod( LetterTemplateEngine::class, 'acceptanceSlipPage' );
        $method->setAccessible( true );
        $html = (string) $method->invoke( null, [
            'player_full_name'  => 'Sam Speler',
            'next_season'       => '2027/2028',
            'club_address'      => '',
            'response_deadline' => '1 June',
        ], 'en_US' );

        $this->assertStringContainsString( 'Acceptance of trial offer for Sam Speler', $html );
        $this->assertStringContainsString( 'the club office', $html );
        $this->assertStringContainsString( 'Parent/guardian name:', $html );
        $this->assertStringNotContainsString( 'Acceptatie', $html );
    }
}
