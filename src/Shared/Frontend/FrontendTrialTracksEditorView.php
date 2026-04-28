<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Trials\Repositories\TrialTracksRepository;

/**
 * FrontendTrialTracksEditorView (#0017 Sprint 6) — track-template
 * editor.
 *
 *   ?tt_view=trial-tracks-editor                   list of tracks
 *   ?tt_view=trial-tracks-editor&action=new        create form
 *   ?tt_view=trial-tracks-editor&id=N              edit form
 */
class FrontendTrialTracksEditorView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_manage_trials' ) ) {
            self::renderHeader( __( 'Trial tracks', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to edit trial tracks.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        self::handlePost( $user_id );

        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
        $id     = isset( $_GET['id'] )     ? absint( $_GET['id'] )                    : 0;
        $repo   = new TrialTracksRepository();

        if ( $action === 'new' ) {
            self::renderHeader( __( 'New trial track', 'talenttrack' ) );
            self::renderForm( null );
            return;
        }
        if ( $id > 0 ) {
            $track = $repo->find( $id );
            if ( ! $track ) {
                self::renderHeader( __( 'Track not found', 'talenttrack' ) );
                return;
            }
            self::renderHeader( sprintf( __( 'Edit track — %s', 'talenttrack' ), (string) $track->name ) );
            self::renderForm( $track );
            return;
        }

        self::renderHeader( __( 'Trial tracks', 'talenttrack' ) );
        self::renderList( $repo );
    }

    private static function renderList( TrialTracksRepository $repo ): void {
        $tracks = $repo->listAll( true );
        $base   = remove_query_arg( [ 'action', 'id' ] );
        $new    = add_query_arg( [ 'tt_view' => 'trial-tracks-editor', 'action' => 'new' ], $base );
        echo '<div class="tt-toolbar"><a class="tt-button tt-button-primary" href="' . esc_url( $new ) . '">' . esc_html__( 'New track', 'talenttrack' ) . '</a></div>';

        if ( ! $tracks ) {
            echo '<p>' . esc_html__( 'No tracks exist yet.', 'talenttrack' ) . '</p>';
            return;
        }
        echo '<table class="tt-table"><thead><tr><th>' . esc_html__( 'Name', 'talenttrack' ) . '</th><th>' . esc_html__( 'Slug', 'talenttrack' ) . '</th><th>' . esc_html__( 'Default duration', 'talenttrack' ) . '</th><th>' . esc_html__( 'Seeded?', 'talenttrack' ) . '</th><th>' . esc_html__( 'Status', 'talenttrack' ) . '</th><th></th></tr></thead><tbody>';
        foreach ( $tracks as $t ) {
            $url = add_query_arg( [ 'tt_view' => 'trial-tracks-editor', 'id' => (int) $t->id ], $base );
            $status = $t->archived_at ? __( 'Archived', 'talenttrack' ) : __( 'Active', 'talenttrack' );
            $seeded = (int) $t->is_seeded ? __( 'Yes', 'talenttrack' ) : __( 'No', 'talenttrack' );
            echo '<tr>';
            echo '<td><a href="' . esc_url( $url ) . '">' . esc_html( (string) $t->name ) . '</a></td>';
            echo '<td>' . esc_html( (string) $t->slug ) . '</td>';
            echo '<td>' . (int) $t->default_duration_days . '</td>';
            echo '<td>' . esc_html( $seeded ) . '</td>';
            echo '<td>' . esc_html( $status ) . '</td>';
            echo '<td><a class="tt-button tt-button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Edit', 'talenttrack' ) . '</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function renderForm( ?object $track ): void {
        $is_seeded = $track && (int) $track->is_seeded;
        echo '<form method="post" class="tt-form tt-trial-track-form">';
        wp_nonce_field( 'tt_trial_track_save', 'tt_trial_track_nonce' );
        echo '<input type="hidden" name="tt_trial_track_action" value="save">';
        if ( $track ) echo '<input type="hidden" name="id" value="' . esc_attr( (string) $track->id ) . '">';
        echo '<label>' . esc_html__( 'Name', 'talenttrack' ) . ' <input type="text" name="name" value="' . esc_attr( $track ? (string) $track->name : '' ) . '" required></label>';
        echo '<label>' . esc_html__( 'Slug', 'talenttrack' ) . ' <input type="text" name="slug" value="' . esc_attr( $track ? (string) $track->slug : '' ) . '" ' . ( $is_seeded ? 'readonly' : '' ) . '></label>';
        if ( $is_seeded ) echo '<p class="tt-meta">' . esc_html__( 'Seeded tracks have a locked slug.', 'talenttrack' ) . '</p>';
        echo '<label>' . esc_html__( 'Description', 'talenttrack' ) . ' <textarea name="description" rows="3">' . esc_textarea( $track ? (string) $track->description : '' ) . '</textarea></label>';
        echo '<label>' . esc_html__( 'Default duration in days', 'talenttrack' ) . ' <input type="number" inputmode="numeric" min="1" max="365" name="default_duration_days" value="' . esc_attr( $track ? (string) $track->default_duration_days : '28' ) . '" required></label>';

        echo '<div class="tt-form-actions">';
        echo '<button type="submit" class="tt-button tt-button-primary">' . esc_html__( 'Save track', 'talenttrack' ) . '</button>';
        if ( $track ) {
            echo ' <button type="submit" formaction="' . esc_attr( add_query_arg( [ 'tt_view' => 'trial-tracks-editor', 'id' => (int) $track->id ] ) ) . '" name="tt_trial_track_action" value="archive" class="tt-button tt-button-danger" onclick="return confirm(\'' . esc_js( __( 'Archive this track?', 'talenttrack' ) ) . '\');">' . esc_html__( 'Archive', 'talenttrack' ) . '</button>';
        }
        echo '</div>';
        echo '</form>';
    }

    private static function handlePost( int $user_id ): void {
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) return;
        if ( ! isset( $_POST['tt_trial_track_action'] ) ) return;
        if ( ! isset( $_POST['tt_trial_track_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tt_trial_track_nonce'] ) ), 'tt_trial_track_save' ) ) return;

        $repo = new TrialTracksRepository();
        $id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $act  = sanitize_key( (string) $_POST['tt_trial_track_action'] );

        if ( $act === 'archive' && $id > 0 ) {
            $repo->archive( $id );
            wp_safe_redirect( add_query_arg( [ 'tt_view' => 'trial-tracks-editor' ], home_url( '/' ) ) );
            exit;
        }

        if ( $act === 'save' ) {
            $payload = [
                'name'                  => sanitize_text_field( wp_unslash( (string) ( $_POST['name'] ?? '' ) ) ),
                'slug'                  => sanitize_title( (string) ( $_POST['slug'] ?? '' ) ),
                'description'           => sanitize_textarea_field( wp_unslash( (string) ( $_POST['description'] ?? '' ) ) ),
                'default_duration_days' => absint( $_POST['default_duration_days'] ?? 28 ),
            ];
            if ( $id > 0 ) {
                $repo->update( $id, $payload );
            } else {
                $repo->create( $payload );
            }
            wp_safe_redirect( add_query_arg( [ 'tt_view' => 'trial-tracks-editor' ], home_url( '/' ) ) );
            exit;
        }
    }
}
