<?php
namespace TT\Modules\PersonaDashboard\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Kernel;
use TT\Infrastructure\Audit\AuditService;

/**
 * AuditSubscriber — records persona-dashboard layout writes (#0060 sprint 2).
 *
 * Three event types feed into tt_audit_log:
 *
 *   persona_template_published — draft promoted to live for a persona.
 *   persona_template_draft     — draft saved (lower-priority signal).
 *   persona_template_reset     — override removed, ship default restored.
 *
 * Each entry carries the persona slug + club_id + actor user_id in the
 * payload so the audit-log viewer can render "Petra J. published the
 * Coach layout for FCV" without joining anything else.
 */
final class AuditSubscriber {

    public static function init(): void {
        add_action( 'tt_persona_template_published', [ self::class, 'onPublished' ], 10, 3 );
        add_action( 'tt_persona_template_draft_saved', [ self::class, 'onDraftSaved' ], 10, 3 );
        add_action( 'tt_persona_template_reset', [ self::class, 'onReset' ], 10, 3 );
    }

    public static function onPublished( string $persona_slug, int $club_id, int $user_id ): void {
        self::record( 'persona_template_published', $persona_slug, $club_id, $user_id );
    }

    public static function onDraftSaved( string $persona_slug, int $club_id, int $user_id ): void {
        self::record( 'persona_template_draft', $persona_slug, $club_id, $user_id );
    }

    public static function onReset( string $persona_slug, int $club_id, int $user_id ): void {
        self::record( 'persona_template_reset', $persona_slug, $club_id, $user_id );
    }

    private static function record( string $action, string $persona_slug, int $club_id, int $user_id ): void {
        $service = self::auditService();
        if ( $service === null ) return;
        $service->record(
            $action,
            'persona_dashboard_template',
            0,
            [
                'persona_slug' => $persona_slug,
                'club_id'      => $club_id,
                'actor_user'   => $user_id,
            ]
        );
    }

    private static function auditService(): ?AuditService {
        try {
            $svc = Kernel::instance()->container()->get( 'audit' );
            return $svc instanceof AuditService ? $svc : null;
        } catch ( \Throwable $e ) {
            return null;
        }
    }
}
