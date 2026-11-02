<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Comms\Domain\CommsRequest;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\Template\AbstractTemplate;
use TT\Modules\Comms\Templates\ScheduledReportTemplate;

/**
 * #4531 — a message template translates in the recipient's language, not
 * the language of the request that happens to send it, and leaves the
 * request's language as it found it, also when the template throws.
 */
final class TemplateRecipientLocaleTest extends WP_UnitTestCase {

    private const PAYLOAD = [ 'schedule_name' => 'Weekly', 'kpi_label' => 'Attendance' ];

    /** @var list<string>|null */
    private $available_languages = null;

    public function set_up(): void {
        parent::set_up();
        // Let the switcher accept nl_NL and read the plugin's own catalogue.
        $switcher = $GLOBALS['wp_locale_switcher'];
        $prop     = new \ReflectionProperty( \WP_Locale_Switcher::class, 'available_languages' );
        $prop->setAccessible( true );
        $this->available_languages = (array) $prop->getValue( $switcher );
        $prop->setValue( $switcher, array_values( array_unique( array_merge( $this->available_languages, [ 'nl_NL' ] ) ) ) );
        $GLOBALS['wp_textdomain_registry']->set_custom_path( 'talenttrack', rtrim( TT_PLUGIN_DIR, '/\\' ) . '/languages' );
        unload_textdomain( 'talenttrack', true );
    }

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

    public function test_a_dutch_recipient_on_an_english_site_gets_dutch(): void {
        $this->assertSame( 'en_US', determine_locale(), 'fixture: an English site' );

        [ $subject, $body ] = $this->render( new ScheduledReportTemplate(), 'nl_NL' );

        $this->assertSame( 'Gepland rapport: Weekly (Attendance)', $subject );
        $this->assertStringNotContainsString( 'Your scheduled report', $body );
        $this->assertSame( 'en_US', determine_locale(), 'the site is English again afterwards' );
    }

    public function test_an_english_recipient_on_a_dutch_site_gets_english(): void {
        $this->assertTrue( switch_to_locale( 'nl_NL' ), 'fixture: a Dutch site' );
        $this->assertSame( 'nl_NL', determine_locale() );

        [ $subject, $body ] = $this->render( new ScheduledReportTemplate(), 'en_US' );

        $this->assertSame( 'Scheduled report: Weekly (Attendance)', $subject );
        $this->assertStringContainsString( 'Your scheduled report "Weekly" is attached.', $body );
        $this->assertSame( 'nl_NL', determine_locale(), 'the site is Dutch again afterwards' );
    }

    public function test_a_recipient_in_the_sites_language_needs_no_switch(): void {
        $switches = 0;
        $count    = static function () use ( &$switches ): void { $switches++; };
        add_action( 'change_locale', $count );
        try {
            [ $subject ] = $this->render( new ScheduledReportTemplate(), 'en_US' );
        } finally {
            remove_action( 'change_locale', $count );
        }

        $this->assertSame( 'Scheduled report: Weekly (Attendance)', $subject );
        $this->assertSame( 0, $switches );
    }

    public function test_the_locale_is_restored_when_a_template_throws(): void {
        $throwing = new class extends AbstractTemplate {
            public function key(): string { return 'tt_test_throwing'; }
            public function label(): string { return 'Throwing'; }
            public function supportedChannels(): array { return [ 'email' ]; }
            protected function defaultCopy( string $channelKey, string $locale ): array {
                throw new \RuntimeException( 'copy failed in ' . determine_locale() );
            }
        };

        try {
            $this->render( $throwing, 'nl_NL' );
            $this->fail( 'the template was expected to throw' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'copy failed in nl_NL', $e->getMessage(), 'the copy ran in the recipient\'s language' );
        }

        $this->assertSame( 'en_US', determine_locale() );
        $this->assertFalse( is_locale_switched() );
    }

    /** @return array{0:string,1:string} */
    private function render( AbstractTemplate $template, string $recipient_locale ): array {
        $recipient = new Recipient( 0, Recipient::KIND_SYSTEM, null, 'someone@example.test', '', $recipient_locale );
        $request   = new CommsRequest( $template->key(), 'scheduled_report', 1, 0, [ $recipient ], self::PAYLOAD );
        return $template->render( 'email', $request, $recipient, '' );
    }
}
