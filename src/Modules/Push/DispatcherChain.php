<?php
namespace TT\Modules\Push;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Kernel;
use TT\Infrastructure\Audit\AuditService;
use TT\Modules\Push\Dispatchers\DispatcherInterface;
use TT\Modules\Push\Dispatchers\EmailDispatcher;
use TT\Modules\Push\Dispatchers\ParentEmailDispatcher;
use TT\Modules\Push\Dispatchers\PushDispatcher;

/**
 * DispatcherChain — runs an ordered list of channels until one
 * claims the message (#0042). The chain string is parsed from the
 * workflow template's `dispatcher_chain` config column; defaults
 * are wired in `PRESETS` below.
 *
 * Failure mode: when every dispatcher returns false (no push, no
 * parent on file, no own email) we record a `dispatch_dropped`
 * audit row tagged with the user id and event so coaches can spot
 * the drop in the diagnostic view. Notification dispatch is
 * best-effort by design — never blocking the workflow run.
 */
final class DispatcherChain {

    public const PRESET_EMAIL_ONLY        = 'email';
    public const PRESET_PUSH_PARENT_EMAIL = 'push_parent_email';
    public const PRESET_PUSH_OWN_EMAIL    = 'push_own_email';
    public const PRESET_PUSH_ONLY         = 'push_only';

    /** @var array<string, list<string>> */
    private const PRESETS = [
        self::PRESET_EMAIL_ONLY        => [ 'email' ],
        self::PRESET_PUSH_PARENT_EMAIL => [ 'push', 'parent_email' ],
        self::PRESET_PUSH_OWN_EMAIL    => [ 'push', 'email' ],
        self::PRESET_PUSH_ONLY         => [ 'push' ],
    ];

    /**
     * Resolve a preset key (or raw chain spec) to the list of
     * dispatcher instances to run, in order. Unknown / empty chains
     * collapse to the email-only preset for safety.
     *
     * @return list<DispatcherInterface>
     */
    public static function resolve( ?string $preset ): array {
        $key = (string) ( $preset ?: self::PRESET_EMAIL_ONLY );
        $keys = self::PRESETS[ $key ] ?? self::PRESETS[ self::PRESET_EMAIL_ONLY ];
        $instances = [];
        foreach ( $keys as $k ) {
            $instances[] = self::factory( $k );
        }
        return $instances;
    }

    /**
     * @return array<string,string> machine key => translated label
     */
    public static function presetLabels(): array {
        return [
            self::PRESET_EMAIL_ONLY        => __( 'Email only',                                        'talenttrack' ),
            self::PRESET_PUSH_PARENT_EMAIL => __( 'Push if available, fall back to parent email',      'talenttrack' ),
            self::PRESET_PUSH_OWN_EMAIL    => __( 'Push if available, fall back to own email',         'talenttrack' ),
            self::PRESET_PUSH_ONLY         => __( 'Push only',                                         'talenttrack' ),
        ];
    }

    public static function isValidPreset( string $key ): bool {
        return isset( self::PRESETS[ $key ] );
    }

    private static function factory( string $key ): DispatcherInterface {
        switch ( $key ) {
            case 'push':         return new PushDispatcher();
            case 'parent_email': return new ParentEmailDispatcher();
            case 'email':
            default:             return new EmailDispatcher();
        }
    }

    /**
     * Run the chain. Returns the dispatcher key that claimed the
     * message, or empty string if every link declined. Drops are
     * audit-logged so diagnostic UIs can spot silently-undelivered
     * notifications.
     *
     * @param array{
     *   user_id:int,
     *   title:string,
     *   body:string,
     *   url?:string,
     *   tag?:string,
     *   data?:array<string,mixed>,
     *   event?:string
     * } $context
     */
    public static function run( ?string $preset, array $context ): string {
        $chain = self::resolve( $preset );
        foreach ( $chain as $dispatcher ) {
            if ( ! $dispatcher->applicableTo( $context ) ) continue;
            if ( $dispatcher->deliver( $context ) ) {
                return $dispatcher->key();
            }
        }
        self::recordDrop( $preset, $context );
        return '';
    }

    private static function recordDrop( ?string $preset, array $context ): void {
        if ( ! class_exists( AuditService::class ) ) return;
        try {
            $audit = Kernel::instance()->container()->get( 'audit' );
            if ( $audit instanceof AuditService ) {
                $user_id = (int) ( $context['user_id'] ?? 0 );
                $audit->record(
                    'dispatch_dropped',
                    'user',
                    $user_id,
                    [
                        'event'  => (string) ( $context['event'] ?? 'unknown' ),
                        'preset' => (string) ( $preset ?: 'default' ),
                        'tag'    => (string) ( $context['tag'] ?? '' ),
                    ]
                );
            }
        } catch ( \Throwable $e ) {
            // Never let a logging failure break the dispatch path.
        }
    }
}
