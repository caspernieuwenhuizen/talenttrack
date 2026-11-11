<?php
namespace TT\Modules\ReportStudio\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\ReportReader;
use TT\Modules\ReportStudio\Domain\ReportStartRules;
use TT\Modules\ReportStudio\Domain\ReportSubjectOptions;
use TT\Shared\Wizards\ConditionalStepInterface;
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
final class SubjectStep implements WizardStepInterface, ConditionalStepInterface {

    public function slug(): string  { return 'subject'; }
    public function label(): string { return __( 'The subject', 'talenttrack' ); }

    /** @param array<string,mixed> $state */
    public function notApplicableFor( array $state ): bool {
        $scope = (string) ( $state['scope'] ?? '' );
        if ( $scope === ReportContext::SCOPE_ME || $scope === ReportContext::SCOPE_ACADEMY ) return true;
        if ( $scope === ReportContext::SCOPE_GROUP ) return false;
        if ( (string) ( $state['binding'] ?? '' ) !== ReportStartRules::BINDING_FIXED ) return true;
        return $this->subjectFromPage( $state );
    }

    /**
     * #4711 / #4744 — before the readers step is answered it is not known
     * whether a player or team report stores a subject, so the step still
     * counts and the total can only shrink once the binding is chosen.
     *
     * @param array<string,mixed> $state
     */
    public function applicability( array $state ): string {
        $scope = (string) ( $state['scope'] ?? '' );
        $known = $scope === ReportContext::SCOPE_ME || $scope === ReportContext::SCOPE_ACADEMY
            || $scope === ReportContext::SCOPE_GROUP || $this->subjectFromPage( $state );
        if ( ! $known && (string) ( $state['binding'] ?? '' ) === '' ) return ConditionalStepInterface::UNKNOWN;
        return $this->notApplicableFor( $state ) ? ConditionalStepInterface::SKIPPED : ConditionalStepInterface::APPLIES;
    }

    /**
     * Opened from the player's or team's own page, with that subject set.
     *
     * @param array<string,mixed> $state
     */
    private function subjectFromPage( array $state ): bool {
        if ( empty( $state['subject_from_page'] ) ) return false;
        $scope = (string) ( $state['scope'] ?? '' );
        if ( $scope === ReportContext::SCOPE_PLAYER && (int) ( $state['player_id'] ?? 0 ) > 0 ) return true;
        return $scope === ReportContext::SCOPE_TEAM && (int) ( $state['team_id'] ?? 0 ) > 0;
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
                $this->renderGroup( $teams, $chosen, (string) ( $state['cohort_age_group'] ?? '' ), ! empty( $state['cohort_all_teams'] ), (string) ( $state['cohort_position'] ?? '' ) );
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
                // #4964 — "All my teams" is every team the builder builds
                // for, as they stand now; the ticked teams are then ignored.
                $all    = ! empty( $post['cohort_all_teams'] );
                $chosen = [];
                if ( $all ) {
                    foreach ( array_keys( $teams ) as $tid ) $chosen[ $tid ] = (int) $tid;
                } else {
                    foreach ( is_array( $post['cohort_team_ids'] ?? null ) ? $post['cohort_team_ids'] : [] as $raw ) {
                        $tid = absint( $raw );
                        if ( ! isset( $teams[ $tid ] ) ) return new \WP_Error( 'tt_rsw_group', __( 'Choose one of your teams.', 'talenttrack' ) );
                        $chosen[ $tid ] = $tid;
                    }
                }
                if ( $chosen === [] ) return new \WP_Error( 'tt_rsw_group', __( 'Choose at least one team for the group.', 'talenttrack' ) );
                $age = isset( $post['cohort_age_group'] ) ? sanitize_text_field( (string) $post['cohort_age_group'] ) : '';
                if ( $age !== '' && ! in_array( $age, array_column( $teams, 'age_group' ), true ) ) $age = '';
                $position = isset( $post['cohort_position'] ) ? strtoupper( trim( sanitize_text_field( (string) $post['cohort_position'] ) ) ) : '';
                if ( $position !== '' && ! isset( ReportSubjectOptions::positions()[ $position ] ) ) {
                    return new \WP_Error( 'tt_rsw_group', __( 'Choose one of the academy\'s positions for the group.', 'talenttrack' ) );
                }
                return [
                    'cohort_team_ids'  => array_values( $chosen ),
                    'cohort_all_teams' => $all,
                    'cohort_age_group' => $age,
                    'cohort_position'  => $position,
                ];
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
    private function renderGroup( array $teams, array $chosen, string $age, bool $all, string $position ): void {
        echo '<fieldset class="tt-rsw-choices">';
        echo '<legend>' . esc_html__( 'Which teams make up the group?', 'talenttrack' ) . '</legend>';
        // #4964 — every team the builder builds for, in one tap.
        echo '<label class="tt-rsw-option" for="tt-rsw-group-all">';
        echo '<input type="checkbox" id="tt-rsw-group-all" name="cohort_all_teams" value="1"' . checked( $all, true, false ) . ' aria-describedby="tt-rsw-group-all-why" />';
        echo '<span class="tt-rsw-option-text"><span class="tt-rsw-option-label">' . esc_html__( 'All my teams', 'talenttrack' ) . '</span>';
        echo '<span class="tt-rsw-option-hint" id="tt-rsw-group-all-why">' . esc_html__( 'Every team you build reports for. The teams ticked below are then not needed.', 'talenttrack' ) . '</span></span>';
        echo '</label>';
        foreach ( $teams as $tid => $team ) {
            $id = 'tt-rsw-group-' . $tid;
            echo '<label class="tt-rsw-option" for="' . esc_attr( $id ) . '">';
            echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="cohort_team_ids[]" value="' . esc_attr( (string) $tid ) . '"' . checked( in_array( $tid, $chosen, true ), true, false ) . ' />';
            echo '<span class="tt-rsw-option-text"><span class="tt-rsw-option-label">' . esc_html( $team['name'] ) . '</span></span>';
            echo '</label>';
        }
        echo '</fieldset>';

        // #4964 — narrowed to the players whose primary position this is.
        $positions = ReportSubjectOptions::positions();
        if ( $positions !== [] ) {
            echo '<div class="tt-field">';
            echo '<label class="tt-field-label" for="tt-rsw-position">' . esc_html__( 'Only one position (optional)', 'talenttrack' ) . '</label>';
            echo '<select id="tt-rsw-position" class="tt-input" name="cohort_position" aria-describedby="tt-rsw-position-hint">';
            echo '<option value="">' . esc_html__( 'Every position', 'talenttrack' ) . '</option>';
            foreach ( $positions as $code => $label ) {
                echo '<option value="' . esc_attr( $code ) . '"' . selected( $position, $code, false ) . '>' . esc_html( $label ) . '</option>';
            }
            echo '</select>';
            echo '<p class="tt-field-hint" id="tt-rsw-position-hint">' . esc_html__( 'A player counts by their first position, so each player is in one position group.', 'talenttrack' ) . '</p>';
            echo '</div>';
        }

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
