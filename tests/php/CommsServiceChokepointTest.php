<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Comms\Channel\Adapters\EmailChannelAdapter;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Send\DirectMessageSender;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;
use TT\Modules\Comms\Templates\DirectMessageTemplate;

/**
 * #4875: every send and every preflight builds its `CommsService` through
 * `CommsDispatcher::service()`.
 *
 * A caller that builds its own service skips whatever the dispatcher
 * provides, today the injectable clock, and drifts from the real send
 * path. Two classes may build one: the dispatcher itself, and the deferred
 * sweep, which sends what quiet hours held on a clock of its own.
 */
final class CommsServiceChokepointTest extends WP_UnitTestCase {

    use FrozenCommsClock;

    /** Repo-relative files allowed to build a `CommsService`. */
    private const ALLOWED = [
        'src/Modules/Comms/Dispatch/CommsDispatcher.php',
        'src/Modules/Comms/Queue/DeferredSendSweep.php',
    ];

    public function test_only_the_dispatcher_and_the_sweep_build_a_comms_service(): void {
        $root  = dirname( __DIR__, 2 );
        $found = [];

        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS )
        );
        foreach ( $rii as $file ) {
            if ( $file->isDir() || $file->getExtension() !== 'php' ) continue;
            $rel = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
            if ( in_array( $rel, self::ALLOWED, true ) ) continue;
            if ( self::buildsService( (string) file_get_contents( $file->getPathname() ) ) ) $found[] = $rel;
        }

        sort( $found );
        $this->assertSame( [], $found, 'Build the service with CommsDispatcher::service().' );
    }

    public function test_the_guard_catches_a_planted_construction(): void {
        $this->assertTrue( self::buildsService( '$results = ( new CommsService() )->send( $request );' ) );
        $this->assertTrue( self::buildsService( 'return ( new \\TT\\Modules\\Comms\\CommsService() )->preflight( $r );' ) );
        $this->assertTrue( self::buildsService( '$s = new  CommsService ( null, $quiet );' ) );
        $this->assertFalse( self::buildsService( 'return CommsDispatcher::service()->preflight( $r );' ) );
        $this->assertFalse( self::buildsService( 'private CommsService $service;' ) );
    }

    /**
     * A preflight now reads the dispatcher's clock: the same direct message
     * is held at 23:59:30 and would go out at 12:00.
     */
    public function test_a_frozen_dispatcher_clock_reaches_a_preflight(): void {
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        TemplateRegistry::register( new DirectMessageTemplate() );
        ChannelAdapterRegistry::register( new EmailChannelAdapter() );
        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Clock',
            'last_name'  => 'Contact',
            'email'      => 'clock.contact@example.test',
            'role_type'  => 'parent',
            'status'     => 'active',
        ] );
        $person = (int) $wpdb->insert_id;

        $this->freezeCommsClockAt( '23:59:30' );
        $held = ( new DirectMessageSender() )->preflightForPerson( $person );
        $this->assertCount( 1, $held );
        $this->assertSame( CommsResult::STATUS_QUIET_HOURS, $held[0]->status );

        $this->freezeCommsClockAt( '12:00:00' );
        $open = ( new DirectMessageSender() )->preflightForPerson( $person );
        $this->assertSame( CommsResult::STATUS_QUEUED, $open[0]->status );

        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
    }

    private static function buildsService( string $src ): bool {
        return preg_match( '/\bnew\s+(?:\\\\?TT\\\\Modules\\\\Comms\\\\)?CommsService\s*\(/', $src ) === 1;
    }
}
