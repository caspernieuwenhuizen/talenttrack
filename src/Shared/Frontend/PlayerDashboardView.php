<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\CustomFields\CustomFieldsRepository;
use TT\Infrastructure\CustomFields\CustomValuesRepository;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Infrastructure\Query\QueryHelpers;

class PlayerDashboardView {

    public function render( object $player ): void {
        global $wpdb; $p = $wpdb->prefix;
        $max  = QueryHelpers::get_config( 'rating_max', '5' );
        $view = isset( $_GET['tt_view'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tt_view'] ) ) : 'overview';

        // v2.15.0: enqueue the player-card stylesheet for this request.
        // The card is used on Overview (right side) and Mijn team (own card).
        \TT\Modules\Stats\Admin\PlayerCardView::enqueueStyles();

        echo '<div class="tt-tabs">';
        foreach ( [
            'overview'    => __( 'Overview', 'talenttrack' ),
            'my_team'     => __( 'My team', 'talenttrack' ),
            'evaluations' => __( 'Evaluations', 'talenttrack' ),
            'goals'       => __( 'Goals', 'talenttrack' ),
            'attendance'  => __( 'Attendance', 'talenttrack' ),
            'progress'    => __( 'Progress', 'talenttrack' ),
            'help'        => __( 'Help', 'talenttrack' ),
        ] as $k => $l ) {
            echo '<button class="tt-tab' . ( $view === $k ? ' tt-tab-active' : '' ) . '" data-tab="' . esc_attr( $k ) . '">' . esc_html( $l ) . '</button>';
        }
        echo '</div>';

        // Overview — v2.15.0 layout: existing info on the left, FIFA-style
        // card on the right. On narrow screens the card drops below.
        echo '<div class="tt-tab-content' . ( $view === 'overview' ? ' tt-tab-content-active' : '' ) . '" data-tab="overview">';
        echo '<div class="tt-overview-grid" style="display:grid;grid-template-columns:minmax(0,1fr) auto;gap:30px;align-items:start;">';
        echo '<div class="tt-overview-main">';
        $this->renderPlayerCard( $player );
        $this->renderCustomFields( (int) $player->id );
        $radar = QueryHelpers::player_radar_datasets( (int) $player->id, 3 );
        if ( ! empty( $radar['datasets'] ) ) {
            echo '<div class="tt-radar-wrap">' . QueryHelpers::radar_chart_svg( $radar['labels'], $radar['datasets'], (float) $max ) . '</div>';
        }
        echo '</div>';
        echo '<div class="tt-overview-card" style="flex-shrink:0;">';
        \TT\Modules\Stats\Admin\PlayerCardView::renderCard( (int) $player->id, 'md', true );
        echo '</div>';
        echo '</div>';
        echo '<style>@media (max-width:820px){.tt-overview-grid{grid-template-columns:minmax(0,1fr) !important;}.tt-overview-card{display:flex;justify-content:center;}}</style>';
        echo '</div>';

        // Mijn team — player's own card centered, plus roster of teammates
        // (names + photos only, no ratings — per Sprint 2B design decision).
        echo '<div class="tt-tab-content' . ( $view === 'my_team' ? ' tt-tab-content-active' : '' ) . '" data-tab="my_team">';
        $this->renderMyTeamTab( (int) $player->id, $player );
        echo '</div>';

        // Evaluations
        echo '<div class="tt-tab-content' . ( $view === 'evaluations' ? ' tt-tab-content-active' : '' ) . '" data-tab="evaluations">';
        $evals = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.*, lt.name AS type_name, u.display_name AS coach_name FROM {$p}tt_evaluations e
             LEFT JOIN {$p}tt_lookups lt ON e.eval_type_id=lt.id LEFT JOIN {$wpdb->users} u ON e.coach_id=u.ID
             WHERE e.player_id=%d ORDER BY e.eval_date DESC", $player->id
        ));
        if ( empty( $evals ) ) {
            echo '<p>' . esc_html__( 'No evaluations yet.', 'talenttrack' ) . '</p>';
        } else {
            echo '<table class="tt-table"><thead><tr>'
                . '<th>' . esc_html__( 'Date', 'talenttrack' ) . '</th>'
                . '<th>' . esc_html__( 'Type', 'talenttrack' ) . '</th>'
                . '<th>' . esc_html__( 'Coach', 'talenttrack' ) . '</th>'
                . '<th>' . esc_html__( 'Ratings', 'talenttrack' ) . '</th>'
                . '</tr></thead><tbody>';
            foreach ( $evals as $ev ) {
                $full = QueryHelpers::get_evaluation( (int) $ev->id );
                echo '<tr><td>' . esc_html( $ev->eval_date ) . '</td><td>' . esc_html( $ev->type_name ?: '—' ) . '</td><td>' . esc_html( $ev->coach_name ) . '</td><td>';
                if ( $ev->opponent ) {
                    echo '<small>' . esc_html( sprintf( __( 'vs %s (%s)', 'talenttrack' ), $ev->opponent, $ev->match_result ?: '—' ) ) . '</small><br/>';
                }
                if ( $full && ! empty( $full->ratings ) ) foreach ( $full->ratings as $r ) echo '<span class="tt-rating-pill">' . esc_html( $r->category_name ) . ': ' . esc_html( (string) $r->rating ) . '</span> ';
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';

        // Goals
        echo '<div class="tt-tab-content' . ( $view === 'goals' ? ' tt-tab-content-active' : '' ) . '" data-tab="goals">';
        $goals = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}tt_goals WHERE player_id=%d ORDER BY created_at DESC", $player->id ) );
        if ( empty( $goals ) ) {
            echo '<p>' . esc_html__( 'No goals assigned.', 'talenttrack' ) . '</p>';
        } else {
            echo '<div class="tt-goals-list">';
            foreach ( $goals as $g ) {
                echo '<div class="tt-goal-item tt-status-' . esc_attr( $g->status ) . '"><h4>' . esc_html( $g->title ) . '</h4>';
                if ( $g->description ) echo '<p>' . esc_html( $g->description ) . '</p>';
                echo '<span class="tt-status-badge">' . esc_html( LabelTranslator::goalStatus( (string) $g->status ) ) . '</span>';
                if ( $g->due_date ) echo ' <small>' . esc_html__( 'Due:', 'talenttrack' ) . ' ' . esc_html( $g->due_date ) . '</small>';
                echo '</div>';
            }
            echo '</div>';
        }
        echo '</div>';

        // Attendance
        echo '<div class="tt-tab-content' . ( $view === 'attendance' ? ' tt-tab-content-active' : '' ) . '" data-tab="attendance">';
        $att = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.*, s.title AS session_title, s.session_date FROM {$p}tt_attendance a
             LEFT JOIN {$p}tt_sessions s ON a.session_id=s.id WHERE a.player_id=%d ORDER BY s.session_date DESC", $player->id
        ));
        if ( empty( $att ) ) {
            echo '<p>' . esc_html__( 'No attendance records.', 'talenttrack' ) . '</p>';
        } else {
            echo '<table class="tt-table"><thead><tr>'
                . '<th>' . esc_html__( 'Date', 'talenttrack' ) . '</th>'
                . '<th>' . esc_html__( 'Session', 'talenttrack' ) . '</th>'
                . '<th>' . esc_html__( 'Status', 'talenttrack' ) . '</th>'
                . '<th>' . esc_html__( 'Notes', 'talenttrack' ) . '</th>'
                . '</tr></thead><tbody>';
            foreach ( $att as $a ) {
                $status_lower = strtolower( (string) $a->status );
                $cls = $status_lower === 'present' ? 'tt-att-present' : ( $status_lower === 'absent' ? 'tt-att-absent' : 'tt-att-other' );
                echo '<tr class="' . esc_attr( $cls ) . '">'
                    . '<td>' . esc_html( (string) $a->session_date ) . '</td>'
                    . '<td>' . esc_html( (string) $a->session_title ) . '</td>'
                    . '<td>' . esc_html( LabelTranslator::attendanceStatus( (string) $a->status ) ) . '</td>'
                    . '<td>' . esc_html( $a->notes ?: '—' ) . '</td>'
                    . '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';

        // Progress
        echo '<div class="tt-tab-content' . ( $view === 'progress' ? ' tt-tab-content-active' : '' ) . '" data-tab="progress">';
        $r5 = QueryHelpers::player_radar_datasets( (int) $player->id, 5 );
        if ( ! empty( $r5['datasets'] ) ) {
            echo '<h3>' . esc_html__( 'Development Over Time', 'talenttrack' ) . '</h3>';
            echo '<div class="tt-radar-wrap">' . QueryHelpers::radar_chart_svg( $r5['labels'], $r5['datasets'], (float) $max ) . '</div>';
        } else {
            echo '<p>' . esc_html__( 'Not enough data yet.', 'talenttrack' ) . '</p>';
        }
        echo '</div>';

        // Help
        echo '<div class="tt-tab-content' . ( $view === 'help' ? ' tt-tab-content-active' : '' ) . '" data-tab="help">';
        echo '<h3>' . esc_html__( 'How to use your dashboard', 'talenttrack' ) . '</h3>';
        echo '<p>' . esc_html__( 'Overview shows your profile and latest radar chart. Evaluations lists every evaluation your coaches have recorded. Goals shows your development goals. Attendance tracks your sessions. Progress shows your trajectory.', 'talenttrack' ) . '</p>';
        echo '</div>';
    }

    /**
     * v2.15.0 — "Mijn team" tab. Shows the logged-in player's own card
     * centered, plus a simple roster list of teammates (names + photos
     * only, no ratings — per Sprint 2B decision to protect players who
     * don't make the top 3). Below the roster, the team's top-3 podium
     * surfaces the strongest current players for motivation.
     */
    private function renderMyTeamTab( int $player_id, object $player ): void {
        $team_id = isset( $player->team_id ) ? (int) $player->team_id : 0;
        if ( $team_id <= 0 ) {
            echo '<p>' . esc_html__( 'You are not on a team yet.', 'talenttrack' ) . '</p>';
            return;
        }
        $team = QueryHelpers::get_team( $team_id );
        $team_name = $team ? (string) $team->name : '';

        // Own card — centered.
        echo '<div style="display:flex;justify-content:center;padding:20px 0;">';
        \TT\Modules\Stats\Admin\PlayerCardView::renderCard( $player_id, 'md', true );
        echo '</div>';

        // Team podium — top 3 of the team.
        $team_svc = new \TT\Infrastructure\Stats\TeamStatsService();
        $top      = $team_svc->getTopPlayersForTeam( $team_id, 3, 5 );
        if ( ! empty( $top ) ) {
            echo '<h3 style="text-align:center;margin-top:10px;">' . esc_html__( 'Top players on the team', 'talenttrack' ) . '</h3>';
            \TT\Modules\Stats\Admin\PlayerCardView::renderPodium( $top );
        }

        // Teammate roster — names + photos only, no ratings.
        $teammates = $team_svc->getTeammatesOfPlayer( $player_id );
        if ( ! empty( $teammates ) ) {
            echo '<h3 style="text-align:center;margin-top:30px;">';
            printf(
                /* translators: %s is the team name. */
                esc_html__( 'Teammates on %s', 'talenttrack' ),
                esc_html( $team_name )
            );
            echo '</h3>';
            echo '<div class="tt-teammates" style="display:flex;flex-wrap:wrap;gap:18px;justify-content:center;padding:10px 0 30px;">';
            foreach ( $teammates as $mate ) {
                $photo_url = '';
                if ( isset( $mate->photo_id ) && (int) $mate->photo_id > 0 ) {
                    $photo_url = (string) wp_get_attachment_image_url( (int) $mate->photo_id, 'thumbnail' );
                } elseif ( ! empty( $mate->photo_url ) ) {
                    $photo_url = (string) $mate->photo_url;
                }
                $initials = strtoupper(
                    mb_substr( (string) ( $mate->first_name ?? '' ), 0, 1 )
                    . mb_substr( (string) ( $mate->last_name ?? '' ), 0, 1 )
                );
                ?>
                <div style="display:flex;flex-direction:column;align-items:center;gap:6px;width:90px;text-align:center;">
                    <div style="width:72px;height:72px;border-radius:50%;overflow:hidden;background:linear-gradient(135deg,#d0d3d8,#8a8d93);display:flex;align-items:center;justify-content:center;border:2px solid #e5e7ea;">
                        <?php if ( $photo_url ) : ?>
                            <img src="<?php echo esc_url( $photo_url ); ?>" alt="" style="width:100%;height:100%;object-fit:cover;" />
                        <?php else : ?>
                            <span style="font-family:'Oswald',sans-serif;font-weight:700;font-size:22px;color:#fff;"><?php echo esc_html( $initials !== '' ? $initials : '?' ); ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:12px;color:#333;line-height:1.2;">
                        <?php echo esc_html( QueryHelpers::player_display_name( $mate ) ); ?>
                    </div>
                </div>
                <?php
            }
            echo '</div>';
        }
    }

    private function renderPlayerCard( object $player ): void {
        $pos  = json_decode( (string) $player->preferred_positions, true );
        $team = $player->team_id ? QueryHelpers::get_team( (int) $player->team_id ) : null;
        echo '<div class="tt-card">';
        if ( $player->photo_url ) echo '<div class="tt-card-thumb"><img src="' . esc_url( (string) $player->photo_url ) . '" alt="" /></div>';
        echo '<div class="tt-card-body">';
        echo '<h3>' . esc_html( QueryHelpers::player_display_name( $player ) ) . '</h3>';
        if ( $team ) echo '<p><strong>' . esc_html__( 'Team:', 'talenttrack' ) . '</strong> ' . esc_html( (string) $team->name ) . '</p>';
        if ( is_array( $pos ) && $pos ) echo '<p><strong>' . esc_html__( 'Pos:', 'talenttrack' ) . '</strong> ' . esc_html( implode( ', ', $pos ) ) . '</p>';
        if ( $player->preferred_foot ) echo '<p><strong>' . esc_html__( 'Foot:', 'talenttrack' ) . '</strong> ' . esc_html( (string) $player->preferred_foot ) . '</p>';
        if ( $player->jersey_number ) echo '<p><strong>#</strong>' . esc_html( (string) $player->jersey_number ) . '</p>';
        echo '</div></div>';
    }

    private function renderCustomFields( int $player_id ): void {
        $fields = ( new CustomFieldsRepository() )->getActive( CustomFieldsRepository::ENTITY_PLAYER );
        if ( empty( $fields ) ) return;
        $values = ( new CustomValuesRepository() )->getByEntityKeyed( CustomFieldsRepository::ENTITY_PLAYER, $player_id );

        // Only render the section if at least one field actually has a value.
        $has_any = false;
        foreach ( $fields as $f ) {
            $v = $values[ (string) $f->field_key ] ?? null;
            if ( $v !== null && $v !== '' ) { $has_any = true; break; }
        }
        if ( ! $has_any ) return;

        echo '<div class="tt-custom-fields">';
        echo '<h4>' . esc_html__( 'Additional Information', 'talenttrack' ) . '</h4>';
        echo '<dl class="tt-custom-fields-list">';
        foreach ( $fields as $f ) {
            $v = $values[ (string) $f->field_key ] ?? null;
            if ( $v === null || $v === '' ) continue;
            echo '<dt>' . esc_html( (string) $f->label ) . '</dt>';
            echo '<dd>' . CustomFieldRenderer::display( $f, $v ) . '</dd>';
        }
        echo '</dl>';
        echo '</div>';
    }
}
