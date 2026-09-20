<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Comms\Template\TemplateCatalog;
use TT\Modules\Comms\Template\TemplateGuide;

/**
 * #3814 — the guide's `triggered` flag must agree with the listeners
 * the module actually registers.
 *
 * `TemplateGuide` tells an operator, on the Messages settings page and
 * in the setup wizard, whether a message is sent automatically. When
 * that claim is wrong it is wrong in the direction that costs someone a
 * squad: the guide said the training-cancelled message was not wired,
 * a listener had been sending it since v3.110.18, and a coach reading
 * the page would reasonably conclude they still had to ring round.
 *
 * A flag maintained by hand drifts, so this pins the half of it a
 * machine can settle. Each `Send\*` class with an `init()` owns exactly
 * one template; whether that listener is hooked is decided in one place,
 * `CommsModule::boot()`. Registered there means the message sends;
 * absent means it does not.
 *
 * Deliberately not asserted here: templates fired from somewhere else
 * entirely — the daily cron (`goal_nudge` and friends), the generic
 * `tt_comms_dispatch` hook, and human-initiated senders such as
 * `DirectMessageSender`. Their triggers are not a registration line in
 * this file, so a scan of it can only guess at them.
 */
final class CommsTemplateGuideTriggeredTest extends WP_UnitTestCase {

    private static function sourceRoot(): string {
        return dirname( __DIR__, 2 ) . '/src/Modules/Comms';
    }

    private static function commsModuleSource(): string {
        return (string) file_get_contents( self::sourceRoot() . '/CommsModule.php' );
    }

    /**
     * Every `Send\*` class that hooks itself with an `init()`, mapped to
     * the template key it dispatches.
     *
     * @return array<string, string> class short name => template key
     */
    private static function listeners(): array {
        $keysByTemplateClass = [];
        foreach ( TemplateCatalog::shipped() as $template ) {
            $short = ( new \ReflectionClass( $template ) )->getShortName();
            $keysByTemplateClass[ $short ] = $template->key();
        }

        $out = [];
        foreach ( (array) glob( self::sourceRoot() . '/Send/*.php' ) as $path ) {
            $source = (string) file_get_contents( (string) $path );
            $class  = basename( (string) $path, '.php' );

            // No `init()` means nothing to register: the class is called
            // directly by a surface, so `CommsModule` says nothing about it.
            if ( ! preg_match( '/function\s+init\s*\(/', $source ) ) {
                continue;
            }

            if ( ! preg_match( '/(\w+Template)::KEY/', $source, $m ) ) {
                self::fail( "{$class} registers a listener but names no template key." );
            }

            $templateClass = $m[1];
            self::assertArrayHasKey(
                $templateClass,
                $keysByTemplateClass,
                "{$class} dispatches {$templateClass}, which TemplateCatalog does not ship."
            );

            $out[ $class ] = $keysByTemplateClass[ $templateClass ];
        }

        return $out;
    }

    /** Guards the scan itself: a regex that matches nothing proves nothing. */
    public function test_the_scan_finds_the_listeners(): void {
        $listeners = self::listeners();

        $this->assertNotEmpty( $listeners, 'No Send listener was found — the scan is broken, not the module.' );
        $this->assertArrayHasKey(
            'TrainingCancelledSend',
            $listeners,
            'TrainingCancelledSend is the case #3814 was filed for; losing it from the scan loses the test.'
        );
    }

    /**
     * A registered listener means the template sends, so the guide must
     * not tell an operator that ticking it changes nothing.
     */
    public function test_registered_listeners_are_marked_triggered(): void {
        $module = self::commsModuleSource();

        foreach ( self::listeners() as $class => $key ) {
            if ( ! preg_match( '/^\s*' . preg_quote( $class, '/' ) . '::init\(\s*\);/m', $module ) ) {
                continue;
            }

            $entry = TemplateGuide::forKey( $key );
            $this->assertNotNull( $entry, "TemplateGuide has no entry for '{$key}', which {$class} sends." );
            $this->assertTrue(
                $entry['triggered'],
                "{$class} is registered in CommsModule::boot(), so '{$key}' sends automatically, "
                . "but TemplateGuide marks it as not yet triggered."
            );
        }
    }

    /**
     * And the other way round, so removing a registration cannot leave
     * the page promising a message nobody sends.
     */
    public function test_unregistered_listeners_are_not_marked_triggered(): void {
        $module = self::commsModuleSource();

        foreach ( self::listeners() as $class => $key ) {
            if ( preg_match( '/^\s*' . preg_quote( $class, '/' ) . '::init\(\s*\);/m', $module ) ) {
                continue;
            }

            $entry = TemplateGuide::forKey( $key );
            if ( $entry === null ) {
                continue;
            }

            $this->assertFalse(
                $entry['triggered'],
                "{$class} is not registered in CommsModule::boot(), so nothing hooks '{$key}', "
                . "but TemplateGuide marks it as sent automatically."
            );
        }
    }
}
