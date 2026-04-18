<?php
namespace TT\Modules\Documentation\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

class DocumentationPage {
    public static function init(): void {}

    public static function render_page(): void {
        $role = isset( $_GET['role'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['role'] ) ) : 'admin';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'TalentTrack — Help & Documentation', 'talenttrack' ); ?></h1>
            <nav class="nav-tab-wrapper">
                <a href="?page=tt-docs&role=admin" class="nav-tab <?php echo $role === 'admin' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Admin', 'talenttrack' ); ?></a>
                <a href="?page=tt-docs&role=coach" class="nav-tab <?php echo $role === 'coach' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Coach', 'talenttrack' ); ?></a>
                <a href="?page=tt-docs&role=player" class="nav-tab <?php echo $role === 'player' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Player', 'talenttrack' ); ?></a>
            </nav>
            <div style="max-width:800px;margin-top:20px;line-height:1.8;">
                <?php if ( $role === 'admin' ) : ?>
                    <h2><?php esc_html_e( 'Administrator Guide', 'talenttrack' ); ?></h2>
                    <p><?php esc_html_e( 'Set up your academy by visiting Configuration, adding Teams, creating Players, and linking WP user accounts to players.', 'talenttrack' ); ?></p>
                <?php elseif ( $role === 'coach' ) : ?>
                    <h2><?php esc_html_e( 'Coach Guide', 'talenttrack' ); ?></h2>
                    <p><?php esc_html_e( 'Submit evaluations, manage goals, and record attendance from the frontend dashboard.', 'talenttrack' ); ?></p>
                <?php else : ?>
                    <h2><?php esc_html_e( 'Player Guide', 'talenttrack' ); ?></h2>
                    <p><?php esc_html_e( 'View your profile, evaluations, goals, and progress from the dashboard.', 'talenttrack' ); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
