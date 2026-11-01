<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Comms\Templates\AlertDigestTemplate;
use TT\Modules\Comms\Templates\DesktopLinkTemplate;
use TT\Modules\Comms\Templates\GoalNudgeTemplate;

/**
 * #4510 — known English leaks on Dutch screens, and Dutch msgids.
 *
 * The message templates carried an `nl_NL` arm written as Dutch msgids,
 * which no other language could translate. The English arm's msgids already
 * translate to that same Dutch, so the arm is gone: a Dutch reader gets the
 * catalogue's Dutch for the English msgid, word for word what they read
 * before. The two short messages whose Dutch wording differs keep an arm,
 * with English msgids distinguished by context.
 */
final class EnglishLeaksTest extends WP_UnitTestCase {

    /** @var list<string>|null */
    private $available_languages = null;

    public function tear_down(): void {
        while ( is_locale_switched() ) restore_previous_locale();
        if ( $this->available_languages !== null ) {
            $prop = new \ReflectionProperty( \WP_Locale_Switcher::class, 'available_languages' );
            $prop->setAccessible( true );
            $prop->setValue( $GLOBALS['wp_locale_switcher'], $this->available_languages );
            $this->available_languages = null;
        }
        unload_textdomain( 'talenttrack', true );
        parent::tear_down();
    }

    private function root(): string {
        return dirname( __DIR__, 2 );
    }

    /** Switch gettext to Dutch, reading the plugin's own catalogue. */
    private function inDutch(): void {
        $switcher = $GLOBALS['wp_locale_switcher'];
        $prop     = new \ReflectionProperty( \WP_Locale_Switcher::class, 'available_languages' );
        $prop->setAccessible( true );
        $this->available_languages = (array) $prop->getValue( $switcher );
        $prop->setValue( $switcher, array_values( array_unique( array_merge( $this->available_languages, [ 'nl_NL' ] ) ) ) );
        $GLOBALS['wp_textdomain_registry']->set_custom_path( 'talenttrack', rtrim( TT_PLUGIN_DIR, '/\\' ) . '/languages' );
        unload_textdomain( 'talenttrack', true );
        $this->assertTrue( switch_to_locale( 'nl_NL' ), 'fixture: the switcher accepts nl_NL' );
    }

    /** @return array{0:string,1:string} */
    private function copy( object $template, string $channel, string $locale ): array {
        $method = new \ReflectionMethod( $template, 'defaultCopy' );
        $method->setAccessible( true );
        return $method->invoke( $template, $channel, $locale );
    }

    /** @return array<string, array{0:object, 1:string, 2:array{0:string,1:string}, 3:array{0:string,1:string}}> */
    private function cases(): array {
        return [
            'alert digest' => [
                new AlertDigestTemplate(), 'email',
                [
                    '{alert_count} things need your attention',
                    "Hi {recipient_first_name},\n\nThese need a look:\n\n{alert_lines}\n\nEach one clears itself once the underlying thing is fixed — there is nothing to tick off.\n\nOpen TalentTrack: {deep_link}",
                ],
                [
                    '{alert_count} dingen vragen je aandacht',
                    "Hoi {recipient_first_name},\n\nHier moet even naar gekeken worden:\n\n{alert_lines}\n\nElke melding verdwijnt vanzelf zodra het onderliggende is opgelost — je hoeft niets af te vinken.\n\nOpen TalentTrack: {deep_link}",
                ],
            ],
            'desktop link' => [
                new DesktopLinkTemplate(), 'email',
                [
                    'Your {site_name} desktop link',
                    "You asked us to email you the link to a desktop-only TalentTrack page.\n\n{link}\n\nOpen this on a laptop or computer.",
                ],
                [
                    'Je desktoplink voor {site_name}',
                    "Je hebt ons gevraagd de link naar een TalentTrack-pagina voor desktop te mailen.\n\n{link}\n\nOpen deze op een laptop of computer.",
                ],
            ],
            'goal nudge' => [
                new GoalNudgeTemplate(), 'email',
                [
                    "How's your goal going, {recipient_first_name}?",
                    "You set the goal \"{goal_title}\" {weeks_since_creation} weeks ago. Tap to update your progress: {deep_link}",
                ],
                [
                    'Hoe gaat het met je doel, {recipient_first_name}?',
                    "Je hebt het doel \"{goal_title}\" {weeks_since_creation} weken geleden gesteld. Tik om je voortgang bij te werken: {deep_link}",
                ],
            ],
        ];
    }

    public function test_the_templates_render_english_in_en_us(): void {
        foreach ( $this->cases() as $name => [ $template, $channel, $english ] ) {
            $this->assertSame( $english, $this->copy( $template, $channel, 'en_US' ), $name );
        }
    }

    public function test_the_templates_render_the_same_dutch_as_before_in_nl_nl(): void {
        $this->inDutch();
        foreach ( $this->cases() as $name => [ $template, $channel, , $dutch ] ) {
            $this->assertSame( $dutch, $this->copy( $template, $channel, 'nl_NL' ), $name );
        }
    }

    /**
     * Only the two short messages whose Dutch differs keep an nl_NL arm,
     * and those arms translate English msgids by context.
     */
    public function test_no_template_carries_a_dutch_msgid_arm(): void {
        $with_arm = [];
        foreach ( (array) glob( $this->root() . '/src/Modules/Comms/Templates/*.php' ) as $file ) {
            $source = (string) file_get_contents( (string) $file );
            if ( strpos( $source, "'nl_NL'" ) !== false ) {
                $with_arm[] = basename( (string) $file );
                $this->assertStringContainsString( 'Dutch-language recipient', $source, basename( (string) $file ) );
            }
            foreach ( [ 'Hoi {', 'Je hebt', ' afgelast', 'dingen vragen' ] as $dutch ) {
                $this->assertStringNotContainsString( $dutch, $source, basename( (string) $file ) . ' still carries Dutch copy' );
            }
        }
        sort( $with_arm );
        $this->assertSame( [ 'PdpReadyTemplate.php', 'TrainingCancelledTemplate.php' ], $with_arm );
    }

    /**
     * The fragment these entries shipped in has been consolidated into the
     * catalogue by a release, so the catalogue is the record.
     */
    public function test_the_catalogue_keeps_the_short_dutch_wording(): void {
        require_once $this->root() . '/tools/lib/po.php';
        $dutch = [];
        foreach ( tt_po_parse( (string) file_get_contents( $this->root() . '/languages/talenttrack-nl_NL.po' ) )['entries'] as $entry ) {
            if ( ! empty( $entry['obsolete'] ) || $entry['msgid'] === null ) continue;
            $dutch[ tt_po_key( (string) $entry['msgctxt'], (string) $entry['msgid'] ) ] = (string) ( $entry['msgstr'][0] ?? '' );
        }

        $this->assertSame(
            'Plan klaar',
            $dutch[ tt_po_key( 'short notification title, Dutch-language recipient', 'PDP ready' ) ] ?? null
        );
        $this->assertSame(
            '{activity_title} op {date} is afgelast. We laten je horen over een vervangende sessie.',
            $dutch[ tt_po_key( 'short message, Dutch-language recipient', '{activity_title} on {date} is cancelled. We will follow up about a replacement.' ) ] ?? null
        );
    }

    // The custom widget builder

    public function test_the_widget_builder_writes_no_english_literal_into_the_page(): void {
        $js = (string) file_get_contents( $this->root() . '/assets/js/custom-widgets-builder.js' );

        $this->assertSame(
            0,
            preg_match( "/\\.(textContent|placeholder|title)\\s*=\\s*'[^']*[A-Za-z]/", $js ),
            'every letter-bearing text in the builder comes from boot.i18n'
        );
    }

    public function test_every_builder_i18n_key_is_supplied_by_the_page(): void {
        $js  = (string) file_get_contents( $this->root() . '/assets/js/custom-widgets-builder.js' );
        $php = (string) file_get_contents( $this->root() . '/src/Modules/CustomWidgets/Admin/CustomWidgetsAdminPage.php' );

        preg_match_all( '/boot\.i18n\.([A-Za-z]+)/', $js, $m );
        foreach ( array_unique( $m[1] ) as $key ) {
            $this->assertMatchesRegularExpression( "/'" . preg_quote( $key, '/' ) . "'\\s*=>\\s*__\\(/", $php, "boot.i18n.{$key} is localized" );
        }
    }
}
