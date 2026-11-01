<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Backup\Admin\BackupSettingsPage;
use TT\Modules\Methodology\MethodologyEnums;

/**
 * #4563 — the short Dutch msgids left in the goal-intake print, the
 * methodology cards and vocabulary, the match-prep print and the football
 * actions page, and the wp-admin backups confirm words.
 *
 * In en_US a msgid is what prints, so "an English install prints English"
 * is: no gettext call in these files uses an old Dutch msgid. The fragment
 * carries the Dutch each one printed, so a Dutch install prints as before.
 */
final class ShortDutchMsgidsTest extends WP_UnitTestCase {

    private const FILES = [
        'src/Modules/Goals/Print/PlayerGoalIntakePrintRouter.php',
        'src/Modules/Methodology/Print/MethodologyReferencePrintRouter.php',
        'src/Modules/Methodology/MethodologyEnums.php',
        'src/Modules/MatchPrep/Print/MatchPrepPrintableRenderer.php',
        'src/Modules/Methodology/Admin/FootballActionsPage.php',
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
     * @return list<array{msgid:string, msgstr:string}>
     */
    private function fragment(): array {
        $path    = $this->root() . '/languages/pending/4563-short-dutch-msgids.po';
        $pending = is_readable( $path );
        if ( ! $pending ) {
            $path = __DIR__ . '/fixtures/i18n/4563-short-dutch-msgids.po';
            $shipped = [];
            foreach ( tt_po_parse( (string) file_get_contents( $this->root() . '/languages/talenttrack-nl_NL.po' ) )['entries'] as $e ) {
                if ( ! empty( $e['obsolete'] ) || $e['msgid'] === null ) continue;
                $shipped[ tt_po_key( (string) $e['msgctxt'], (string) $e['msgid'] ) ] = (string) ( $e['msgstr'][0] ?? '' );
            }
        }
        $out = [];
        foreach ( tt_po_parse( (string) file_get_contents( $path ) )['entries'] as $e ) {
            if ( $e['msgid'] === null || $e['msgid'] === '' ) continue;
            $msgstr = (string) ( $e['msgstr'][0] ?? '' );
            if ( ! $pending ) {
                $key = tt_po_key( (string) $e['msgctxt'], (string) $e['msgid'] );
                $this->assertArrayHasKey( $key, $shipped, "the catalogue carries the converted msgid: {$e['msgid']}" );
                $msgstr = $shipped[ $key ];
            }
            $out[] = [ 'msgid' => (string) $e['msgid'], 'msgstr' => $msgstr ];
        }
        return $out;
    }

    public function test_no_file_still_uses_an_old_dutch_msgid(): void {
        $dutch = [];
        foreach ( $this->fragment() as $entry ) {
            if ( $entry['msgid'] !== $entry['msgstr'] ) $dutch[ $entry['msgstr'] ] = true;
        }
        $this->assertGreaterThan( 100, count( $dutch ), 'fixture: the fragment carries the old Dutch' );

        foreach ( self::FILES as $relative ) {
            foreach ( tt_gettext_calls( (string) file_get_contents( $this->root() . '/' . $relative ) ) as $call ) {
                $this->assertArrayNotHasKey( (string) $call['msgid'], $dutch, "{$relative}:{$call['line']} prints Dutch on an English install" );
            }
        }
    }

    public function test_every_fragment_msgstr_is_dutch_the_catalogue_prints_today(): void {
        $catalogue = [];
        foreach ( tt_po_parse( (string) file_get_contents( $this->root() . '/languages/talenttrack-nl_NL.po' ) )['entries'] as $e ) {
            if ( ! empty( $e['obsolete'] ) || $e['msgid'] === null ) continue;
            $catalogue[ (string) ( $e['msgstr'][0] ?? '' ) ] = true;
        }
        foreach ( $this->fragment() as $entry ) {
            if ( strpos( $entry['msgid'], '%s to confirm before' ) !== false ) continue; // new sentence, written for this change
            $this->assertArrayHasKey( $entry['msgstr'], $catalogue, "a Dutch install prints this today: {$entry['msgstr']}" );
        }
    }

    public function test_the_methodology_vocabulary_reads_english_and_keeps_its_stored_keys(): void {
        $this->assertSame( [ 'aanvallen', 'omschakelen_naar_verdedigen', 'omschakelen_naar_aanvallen', 'verdedigen' ], array_keys( MethodologyEnums::teamFunctions() ) );
        $this->assertSame( [ 'opbouwen', 'scoren', 'overgang_balverlies', 'overgang_balwinst', 'storen', 'doelpunten_voorkomen' ], array_keys( MethodologyEnums::teamTasks() ) );
        $this->assertSame( [ 'aanvallers', 'middenvelders', 'verdedigers', 'keeper', 'algemeen' ], array_keys( MethodologyEnums::lines() ) );

        $this->assertSame( 'Attacking', MethodologyEnums::teamFunctions()['aanvallen'] );
        $this->assertSame( 'Building up', MethodologyEnums::teamTasks()['opbouwen'] );
        $this->assertSame( 'Throw-in', MethodologyEnums::setPieceKinds()['throw_in'] );
        $this->assertSame( 'Dominant possession', MethodologyEnums::stylesOfPlay()['dominant_balbezit'] );
        $this->assertSame( 'Goalkeeper', MethodologyEnums::lines()['keeper'] );
    }

    // wp-admin backups confirm words

    public function test_the_admin_confirm_words_follow_the_frontend_msgids(): void {
        $this->assertSame( 'RESTORE', BackupSettingsPage::restoreWord() );
        $this->assertSame( 'IMPORT', BackupSettingsPage::importWord() );
    }

    public function test_the_shown_word_maps_to_the_fixed_token_in_either_language(): void {
        // English install: the word is the token.
        $this->assertSame( 'RESTORE', BackupSettingsPage::confirmToken( ' RESTORE ', 'RESTORE', 'RESTORE' ) );
        $this->assertSame( 'IMPORT', BackupSettingsPage::confirmToken( 'IMPORT', 'IMPORT', 'IMPORT' ) );
        // Dutch install: the Dutch word maps to the token; the English one does not.
        $this->assertSame( 'RESTORE', BackupSettingsPage::confirmToken( 'HERSTELLEN', 'HERSTELLEN', 'RESTORE' ) );
        $this->assertSame( 'IMPORT', BackupSettingsPage::confirmToken( 'IMPORTEREN', 'IMPORTEREN', 'IMPORT' ) );
        $this->assertNull( BackupSettingsPage::confirmToken( 'RESTORE', 'HERSTELLEN', 'RESTORE' ) );
        $this->assertNull( BackupSettingsPage::confirmToken( 'herstellen', 'HERSTELLEN', 'RESTORE' ) );
        $this->assertNull( BackupSettingsPage::confirmToken( '', '', 'RESTORE' ) );
    }
}
