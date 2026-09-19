<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Shared\Dates\TTDate;

/**
 * #3448 — `TTDate::dateWithDay()`, the weekday in front of the academy's
 * own date notation.
 *
 * The point of the method is that it **composes**. Eight surfaces already
 * print a weekday and every one of them is a hand-rolled `wp_date()` call
 * that ignores the operator's configured preset; a ninth with a shape of
 * its own would have been the bug, not the fix. So the tests pin a
 * non-default preset and assert the weekday is prefixed to *that* shape.
 *
 * Expectations are composed from `wp_date()` rather than hardcoded
 * English, so they hold whatever locale the test install runs.
 */
final class TTDateWithDayTest extends WP_UnitTestCase {

    /** 2026-09-11 — a Friday. */
    private const ISO = '2026-09-11';

    private string $prev_preset = '';
    private string $prev_option = '';

    /** @var callable|null */
    private $translator = null;

    public function set_up(): void {
        parent::set_up();
        $this->prev_preset = ( new ConfigService() )->get( TTDate::FORMAT_KEY, 'system' );
        $this->prev_option = (string) get_option( 'date_format' );
    }

    public function tear_down(): void {
        $this->dropTranslator();
        $this->setPreset( $this->prev_preset );
        update_option( 'date_format', $this->prev_option );
        parent::tear_down();
    }

    /**
     * The preset is memoised per request, so a test that changes it has to
     * drop the memo the same way a fresh request would.
     */
    private function setPreset( string $slug ): void {
        ( new ConfigService() )->set( TTDate::FORMAT_KEY, $slug );
        $prop = new \ReflectionProperty( TTDate::class, 'preset_cache' );
        $prop->setAccessible( true );
        $prop->setValue( null, null );
    }

    private function ts(): int {
        $ts = TTDate::timestamp( self::ISO );
        $this->assertNotNull( $ts );
        return $ts;
    }

    // ---- composition with the configured preset ---------------------

    public function test_the_weekday_is_prefixed_to_the_configured_preset(): void {
        $this->setPreset( 'dmy_dash' );

        $this->assertSame( 'D d-m-Y', TTDate::dateWithDayFormat() );
        $this->assertSame(
            wp_date( 'D', $this->ts() ) . ' ' . wp_date( 'd-m-Y', $this->ts() ),
            TTDate::dateWithDay( self::ISO ),
            'the weekday is prefixed to the academy notation, not to a shape of its own'
        );
    }

    public function test_it_composes_with_every_other_preset_too(): void {
        foreach ( [ 'dmy_slash' => 'd/m/Y', 'mdy_slash' => 'm/d/Y', 'iso' => 'Y-m-d', 'long' => 'j F Y' ] as $slug => $fmt ) {
            $this->setPreset( $slug );
            $this->assertSame( 'D ' . $fmt, TTDate::dateWithDayFormat(), "preset {$slug}" );
            $this->assertSame(
                wp_date( 'D ' . $fmt, $this->ts() ),
                TTDate::dateWithDay( self::ISO ),
                "preset {$slug}"
            );
        }
    }

    /**
     * Scope is scheduled events. A player's date of birth and an audit
     * stamp gain no weekday, so `date()` itself must be untouched.
     */
    public function test_plain_date_is_unchanged(): void {
        $this->setPreset( 'dmy_dash' );

        $plain = TTDate::date( self::ISO );
        $this->assertSame( wp_date( 'd-m-Y', $this->ts() ), $plain );
        $this->assertSame( 'd-m-Y', TTDate::dateFormat() );
        $this->assertStringNotContainsString(
            wp_date( 'D', $this->ts() ),
            $plain,
            'a birthdate must not sprout a weekday'
        );
    }

    // ---- casing -----------------------------------------------------

    /**
     * `wp_date()` already returns the locale's own casing — Dutch `vr`,
     * English `Fri` — so an `ucfirst()` would be wrong in every locale
     * that does not capitalise its weekday names. Asserted by handing
     * WP_Locale a lowercase Dutch abbreviation and checking it survives
     * verbatim.
     */
    public function test_the_locales_own_casing_survives(): void {
        global $wp_locale;
        $this->setPreset( 'dmy_dash' );

        $friday = $wp_locale->get_weekday( 5 );
        $before = $wp_locale->weekday_abbrev;
        $wp_locale->weekday_abbrev[ $friday ] = 'vr';

        try {
            $this->assertSame( 'vr 11-09-2026', TTDate::dateWithDay( self::ISO ) );
        } finally {
            $wp_locale->weekday_abbrev = $before;
        }
    }

    // ---- the system preset's escape hatch ---------------------------

    /**
     * `system` defers to the WordPress date-format option, which an
     * operator can set to anything — including a format that already
     * names the weekday. Printing it twice would be worse than not
     * printing it at all.
     */
    public function test_a_format_that_already_names_the_weekday_is_not_doubled(): void {
        $this->setPreset( 'system' );
        update_option( 'date_format', 'D, j M Y' );

        $this->assertSame( 'D, j M Y', TTDate::dateWithDayFormat() );

        update_option( 'date_format', 'l j F Y' );
        $this->assertSame( 'l j F Y', TTDate::dateWithDayFormat() );

        // An escaped D is a literal letter, not a weekday.
        update_option( 'date_format', '\\D j M Y' );
        $this->assertSame( 'D \\D j M Y', TTDate::dateWithDayFormat() );
    }

    public function test_unparseable_input_renders_nothing(): void {
        $this->assertSame( '', TTDate::dateWithDay( '' ) );
        $this->assertSame( '', TTDate::dateWithDay( 'not a date' ) );
        $this->assertSame( '', TTDate::dateWithDay( null ) );
    }

    // ---- the system preset follows the site language (#3680) --------

    /**
     * Stand in for a Dutch install: core's own translation of its install
     * default, which is `j F Y` in nl_NL. Only that one string is touched,
     * so month and weekday names keep the test locale's values.
     */
    private function translateCoreDefaultToDutch(): void {
        $this->translator = static function ( $translation, $text, $domain ) {
            return ( $domain === 'default' && $text === 'F j, Y' ) ? 'j F Y' : $translation;
        };
        add_filter( 'gettext', $this->translator, 10, 3 );
    }

    private function dropTranslator(): void {
        if ( $this->translator !== null ) {
            remove_filter( 'gettext', $this->translator, 10 );
            $this->translator = null;
        }
    }

    /**
     * WordPress writes `date_format` once, at install, in the install
     * language — so an academy installed in English and switched to Dutch
     * kept `F j, Y` and read "oktober 6, 2026". The untouched default now
     * resolves through core's translation of it.
     */
    public function test_the_untouched_english_default_follows_the_site_language(): void {
        $this->setPreset( 'system' );
        update_option( 'date_format', 'F j, Y' );
        $this->translateCoreDefaultToDutch();

        try {
            $this->assertSame( 'j F Y', TTDate::dateFormat() );
            $this->assertSame( wp_date( 'j F Y', $this->ts() ), TTDate::date( self::ISO ) );
            $this->assertSame( 'D j F Y', TTDate::dateWithDayFormat() );
            $this->assertSame(
                wp_date( TTDate::dateFormat(), time() ),
                TTDate::presetSamples()['system'],
                'the settings preview must agree with the rendered dates'
            );
        } finally {
            $this->dropTranslator();
        }
    }

    /** An English install has nothing to translate, so nothing moves. */
    public function test_an_english_install_renders_exactly_as_before(): void {
        $this->setPreset( 'system' );
        update_option( 'date_format', 'F j, Y' );

        $this->assertSame( 'F j, Y', TTDate::dateFormat() );
        $this->assertSame( wp_date( 'F j, Y', $this->ts() ), TTDate::date( self::ISO ) );
        $this->assertSame(
            wp_date( TTDate::dateFormat(), time() ),
            TTDate::presetSamples()['system']
        );
    }

    /**
     * Any other stored format is a deliberate choice by the operator and
     * is used as stored, Dutch site or not.
     */
    public function test_a_deliberate_format_is_kept(): void {
        $this->setPreset( 'system' );
        update_option( 'date_format', 'd/m/Y' );
        $this->translateCoreDefaultToDutch();

        try {
            $this->assertSame( 'd/m/Y', TTDate::dateFormat() );
            $this->assertSame(
                wp_date( TTDate::dateFormat(), time() ),
                TTDate::presetSamples()['system']
            );
        } finally {
            $this->dropTranslator();
        }
    }

    /** An empty option still falls back to ISO, as before. */
    public function test_an_empty_option_still_falls_back_to_iso(): void {
        $this->setPreset( 'system' );
        update_option( 'date_format', '' );

        $this->assertSame( 'Y-m-d', TTDate::dateFormat() );
    }
}
