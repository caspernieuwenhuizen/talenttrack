<?php
namespace TT\Modules\ReportStudio\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\ReportReader;
use TT\Modules\ReportStudio\Domain\ReportStartRules;
use TT\Modules\ReportStudio\Domain\ReportSubjectOptions;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 3 — the subject: which player, which team, or which teams make up the
 * group. Only subjects the builder builds reports about are offered.
 *
 * Asked after the binding, because only a report that always shows the same
 * player or team stores one: one chosen when opened or following the viewer
 * has no subject to pick, so the step is skipped. A group is a rule and is
 * always asked. Also skipped for a report about me or the academy, and when
 * the wizard was opened from the player's or the team's page.
 */
final class SubjectStep implements WizardStepInterface {

    public function slug(): string  { return 'subject'; }
    public function label(): string { return __( 'The subject', 'talenttrack' ); }

    /** @param array<string,mixed> $state */
    public function notApplicableFor( array $state ): bool {
        $scope = (string) ( $state['scope'] ?? '' );
        if ( $scope === ReportContext::SCOPE_ME || $scope === ReportContext::SCOPE_ACADEMY ) return true;
        if ( $scope === ReportContext::SCOPE_GROUP ) return false;
        // #4711 — skipped only once the binding is known not to be fixed.
        // Before the readers step is answered the step still counts, so the
        // step total can shrink after that choice but never grow.
        $binding = (string) ( $state['binding'] ?? '' );
        if ( $binding !== '' && $binding !== ReportStartRules::BINDING_FIXED ) return true;
        if ( ! empty( $state['subject_from_page'] ) ) {
            if ( $scope === ReportContext::SCOPE_PLAYER && (int) ( $state['player_id'] ?? 0 ) > 0 ) return true;
            if ( $scope === ReportContext::SCOPE_TEAM && (int) ( $state['team_id'] ?? 0 ) > 0 ) return true;
        }
        return false;
    }

    public function render( array $state ): void {
        StartChoices::enqueue();
        $reader = ReportReader::for( get_current_user_id() );
        $teams  = ReportSubjectOptions::teams( $reader );
        if ( $teams === [] ) {
            echo '<p class="tt-notice">' . esc_html__( 'There are no teams you build reports for.', 'talenttrack' ) . '</p>';
            return;
        }

        switch ( (string) ( $state['scope'] ?? '' ) ) {
            case ReportContext::SCOPE_PLAYER:
                $this->renderPlayer( $teams, ReportSubjectOptions::players( $reader, $teams ), (int) ( $state['player_id'] ?? 0 ) );
                return;
            case ReportContext::SCOPE_TEAM:
                $this->renderTeam( $teams, (int) ( $state['team_id'] ?? 0 ) );
                return;
            case ReportContext::SCOPE_GROUP:
                $chosen = array_values( array_map( 'intval', is_array( $state['cohort_team_ids'] ?? null ) ? $state['cohort_team_ids'] : [] ) );
                $this->renderGroup( $teams, $chosen, (string) ( $state['cohort_age_group'] ?? '' ) );
                return;
        }
    }

    public function validate( array $post, array $state ) {
        $reader = ReportReader::for( get_current_user_id() );
        $teams  = ReportSubjectOptions::teams( $reader );

        switch ( (string) ( $state['scope'] ?? '' ) ) {
            case ReportContext::SCOPE_PLAYER:
                $pid     = isset( $post['player_id'] ) ? absint( $post['player_id'] ) : 0;
                $offered = false;
                foreach ( ReportSubjectOptions::players( $reader, $teams ) as $players ) {
                    if ( isset( $players[ $pid ] ) ) $offered = true;
                }
                if ( ! $offered ) return new \WP_Error( 'tt_rsw_player', __( 'Choose one of your players.', 'talenttrack' ) );
                return [ 'player_id' => $pid ];

            case ReportContext::SCOPE_TEAM:
                $tid = isset( $post['team_id'] ) ? absint( $post['team_id'] ) : 0;
                if ( ! isset( $teams[ $tid ] ) ) return new \WP_Error( 'tt_rsw_team', __( 'Choose one of your teams.', 'talenttrack' ) );
                return [ 'team_id' => $tid ];

            case ReportContext::SCOPE_GROUP:
                $chosen = [];
                foreach ( is_array( $post['cohort_team_ids'] ?? null ) ? $post['cohort_team_ids'] : [] as $raw ) {
                    $tid = absint( $raw );
                    if ( ! isset( $teams[ $tid ] ) ) return new \WP_Error( 'tt_rsw_group', __( 'Choose one of your teams.', 'talenttrack' ) );
                    $chosen[ $tid ] = $tid;
                }
                if ( $chosen === [] ) return new \WP_Error( 'tt_rsw_group', __( 'Choose at least one team for the group.', 'talenttrack' ) );
                $age = isset( $post['cohort_age_group'] ) ? sanitize_text_field( (string) $post['cohort_age_group'] ) : '';
                if ( $age !== '' && ! in_array( $age, array_column( $teams, 'age_group' ), true ) ) $age = '';
                return [ 'cohort_team_ids' => array_values( $chosen ), 'cohort_age_group' => $age ];
        }
        return [];
    }

    public function nextStep( array $state ): string { return 'when'; }

    /** @return array<string,mixed> */
    public function submit( array $state ): array { return []; }

    /**
     * @param array<int, array{name:string, age_group:string}> $teams
     * @param array<int, array<int,string>>                   $players
     */
    private function renderPlayer( array $teams, array $players, int $current ): void {
        echo '<div class="tt-field">';
        echo '<label class="tt-field-label" for="tt-rsw-player">' . esc_html__( 'Which player is the report about?', 'talenttrack' ) . '</label>';
        echo '<select id="tt-rsw-player" class="tt-input" name="player_id" required>';
        echo '<option value="">' . esc_html__( '— Pick a player —', 'talenttrack' ) . '</option>';
        foreach ( $teams as $tid => $team ) {
            if ( empty( $players[ $tid ] ) ) continue;
            echo '<optgroup label="' . esc_attr( $team['name'] ) . '">';
            foreach ( $players[ $tid ] as $pid => $name ) {
                echo '<option value="' . esc_attr( (string) $pid ) . '"' . selected( $current, $pid, false ) . '>' . esc_html( $name ) . '</option>';
            }
            echo '</optgroup>';
        }
        echo '</select>';
        echo '</div>';
    }

    /** @param array<int, array{name:string, age_group:string}> $teams */
    private function renderTeam( array $teams, int $current ): void {
        echo '<div class="tt-field">';
        echo '<label class="tt-field-label" for="tt-rsw-team">' . esc_html__( 'Which team is the report about?', 'talenttrack' ) . '</label>';
        echo '<select id="tt-rsw-team" class="tt-input" name="team_id" required>';
        echo '<option value="">' . esc_html__( '— Pick a team —', 'talenttrack' ) . '</option>';
        foreach ( $teams as $tid => $team ) {
            echo '<option value="' . esc_attr( (string) $tid ) . '"' . selected( $current, $tid, false ) . '>' . esc_html( $team['name'] ) . '</option>';
        }
        echo '</select>';
        echo '</div>';
    }

    /**
     * @param array<int, array{name:string, age_group:string}> $teams
     * @param list<int>                                         $chosen
     */
    private function renderGroup( array $teams, array $chosen, string $age ): void {
        echo '<fieldset class="tt-rsw-choices">';
        echo '<legend>' . esc_html__( 'Which teams make up the group?', 'talenttrack' ) . '</legend>';
        foreach ( $teams as $tid => $team ) {
            $id = 'tt-rsw-group-' . $tid;
            echo '<label class="tt-rsw-option" for="' . esc_attr( $id ) . '">';
            echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="cohort_team_ids[]" value="' . esc_attr( (string) $tid ) . '"' . checked( in_array( $tid, $chosen, true ), true, false ) . ' />';
            echo '<span class="tt-rsw-option-text"><span class="tt-rsw-option-label">' . esc_html( $team['name'] ) . '</span></span>';
            echo '</label>';
        }
        echo '</fieldset>';

        $ages = array_values( array_unique( array_filter( array_column( $teams, 'age_group' ) ) ) );
        if ( $ages === [] ) return;
        sort( $ages );
        echo '<div class="tt-field">';
        echo '<label class="tt-field-label" for="tt-rsw-age">' . esc_html__( 'Only one age group (optional)', 'talenttrack' ) . '</label>';
        echo '<select id="tt-rsw-age" class="tt-input" name="cohort_age_group">';
        echo '<option value="">' . esc_html__( 'Every age group', 'talenttrack' ) . '</option>';
        foreach ( $ages as $value ) {
            echo '<option value="' . esc_attr( $value ) . '"' . selected( $age, $value, false ) . '>' . esc_html( $value ) . '</option>';
        }
        echo '</select>';
        echo '</div>';
    }
}
