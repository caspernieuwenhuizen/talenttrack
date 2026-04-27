<?php
namespace TT\Infrastructure\Audit;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AuditSubscriber — binds audit recording to existing TalentTrack hooks.
 *
 * This is purely additive. It does NOT modify any existing module code.
 * Instead, it hooks into:
 *   - tt_after_player_save         (existing, fired by Players module)
 *   - tt_before_save_evaluation    (existing, fired by Evaluations module)
 *   - WP admin-post actions        (hooked before the handler runs)
 *
 * Modules can add richer audit context by firing their own hooks in the
 * future; nothing in Phase 3 forces module-level changes.
 */
class AuditSubscriber {

    /** @var AuditService */
    private $audit;

    public function __construct( AuditService $audit ) {
        $this->audit = $audit;
    }

    public function register(): void {
        // Player save (existing hook — see PlayersPage::handle_save)
        add_action( 'tt_after_player_save', function ( $player_id, $data ) {
            $this->audit->record( 'player.saved', 'player', (int) $player_id, [
                'fields' => is_array( $data ) ? array_keys( $data ) : [],
            ]);
        }, 10, 2 );

        // Evaluation create (existing hook — see FrontendAjax & EvaluationsPage)
        add_action( 'tt_before_save_evaluation', function ( $player_id, $_coach_id_unused, $_eval_id_unused ) {
            $this->audit->record( 'evaluation.created', 'evaluation', 0, [
                'player_id' => (int) $player_id,
            ]);
        }, 10, 3 );

        // Generic catch-all for admin-post destructive verbs.
        // Uses WP's action loader to record deletions without touching handlers.
        $delete_actions = [
            'tt_delete_player'      => [ 'player',      'player.deleted' ],
            'tt_delete_team'        => [ 'team',        'team.deleted' ],
            'tt_delete_evaluation'  => [ 'evaluation',  'evaluation.deleted' ],
            'tt_delete_activity'     => [ 'activity',     'session.deleted' ],
            'tt_delete_goal'        => [ 'goal',        'goal.deleted' ],
            'tt_delete_lookup'      => [ 'lookup',      'lookup.deleted' ],
        ];
        foreach ( $delete_actions as $action_name => [ $entity_type, $audit_action ] ) {
            add_action( "admin_post_$action_name", function () use ( $entity_type, $audit_action ) {
                $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
                $this->audit->record( $audit_action, $entity_type, $id );
            }, 5 ); // Priority 5 = before the actual handler at priority 10
        }

        // Config changes
        add_action( 'admin_post_tt_save_config', function () {
            $tab     = isset( $_POST['tab'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['tab'] ) ) : '';
            $cfg     = isset( $_POST['cfg'] ) && is_array( $_POST['cfg'] ) ? $_POST['cfg'] : [];
            $this->audit->record( 'config.changed', 'config', 0, [
                'tab'    => $tab,
                'fields' => array_keys( $cfg ),
            ]);
        }, 5 );

        // Successful logins
        add_action( 'wp_login', function ( $user_login, $user ) {
            $this->audit->record( 'user.login', 'user', (int) ( $user->ID ?? 0 ), [
                'login' => (string) $user_login,
            ]);
        }, 10, 2 );
    }
}
