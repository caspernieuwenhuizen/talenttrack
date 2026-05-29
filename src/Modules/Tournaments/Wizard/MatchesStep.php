<?php
namespace TT\Modules\Tournaments\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 4 — Matches. One card per match. Each carries:
 *
 *   - Sequence circle + live-updating headline ("vs <opponent>" / label).
 *   - Per-row Remove button.
 *   - Two-column field grid: Label, Opponent, Opponent level,
 *     Formation override, Duration, Substitution windows.
 *   - Chip-editor for substitution windows (Enter / comma to add,
 *     Backspace pops, × removes). Max-value hint live-updates from
 *     duration_min.
 *
 * Empty cards drop silently on submit (#975 spec preservation). A card
 * with a missing headline (neither label nor opponent) but other
 * fields set still raises an inline error per the v1 contract.
 *
 * Substitution windows still POST as a comma-separated CSV in a
 * hidden field — the JS keeps the visible chip set in sync. Server
 * parser is unchanged from v1.
 */
final class MatchesStep implements WizardStepInterface {

    public function slug(): string { return 'matches'; }
    public function label(): string { return __( 'Matches', 'talenttrack' ); }

    public function render( array $state ): void {
        WizardAssets::enqueue();

        $matches = is_array( $state['matches'] ?? null ) ? $state['matches'] : [];
        $rows = $matches;
        if ( ! $rows ) {
            $rows = [ self::blankRow(), self::blankRow(), self::blankRow() ];
        }

        $levels     = QueryHelpers::get_lookup_names( 'tournament_opponent_level' );
        $formations = QueryHelpers::get_lookup_names( 'tournament_formation' );
        $default_formation = (string) ( $state['default_formation'] ?? '' );

        echo '<div class="tt-tournament-wizard">';
        echo '<p class="ttw-step-desc">' . esc_html__( 'Add at least one match. Each card is a separate match — leave a card blank to skip it. You can add more matches from the planner after the tournament is created.', 'talenttrack' ) . '</p>';

        echo '<ol class="ttw-match-list" data-ttw-match-list>';
        foreach ( $rows as $i => $m ) {
            self::renderRow( (int) $i, (array) $m, $levels, $formations, $default_formation );
        }
        echo '</ol>';

        echo '<button type="button" class="ttw-match-add" data-ttw-match-add>+ ' . esc_html__( 'Add another match', 'talenttrack' ) . '</button>';
        echo '</div>';
    }

    public function validate( array $post, array $state ) {
        $raw = isset( $post['matches'] ) && is_array( $post['matches'] ) ? $post['matches'] : [];
        $out = [];
        foreach ( $raw as $idx => $row ) {
            $label   = isset( $row['label'] )         ? sanitize_text_field( wp_unslash( (string) $row['label'] ) )         : '';
            $opp     = isset( $row['opponent_name'] ) ? sanitize_text_field( wp_unslash( (string) $row['opponent_name'] ) ) : '';
            $level   = isset( $row['opponent_level'] ) ? sanitize_text_field( wp_unslash( (string) $row['opponent_level'] ) ) : '';
            $duration_raw = isset( $row['duration_min'] ) ? (int) $row['duration_min'] : 0;
            $duration = $duration_raw > 0 ? min( 240, $duration_raw ) : 0;
            $windows_raw = isset( $row['substitution_windows'] ) ? (string) $row['substitution_windows'] : '';
            $formation = isset( $row['formation'] ) ? sanitize_text_field( wp_unslash( (string) $row['formation'] ) ) : '';

            if ( $label === '' && $opp === '' && $duration === 0 && $windows_raw === '' && $formation === '' && $level === '' ) {
                continue;
            }
            if ( $label === '' && $opp === '' ) {
                return new \WP_Error(
                    'match_headline_required',
                    sprintf( __( 'Match #%d needs either a label or an opponent name.', 'talenttrack' ), (int) $idx + 1 )
                );
            }
            if ( $duration === 0 ) $duration = 20;

            $windows = [];
            foreach ( preg_split( '/[\s,]+/', $windows_raw ) as $token ) {
                $w = (int) trim( $token );
                if ( $w > 0 && $w < $duration ) $windows[] = $w;
            }
            $windows = array_values( array_unique( $windows ) );
            sort( $windows );

            $out[] = [
                'label'                => $label,
                'opponent_name'        => $opp,
                'opponent_level'       => $level,
                'formation'            => $formation,
                'duration_min'         => $duration,
                'substitution_windows' => $windows,
            ];
        }

        if ( ! $out ) {
            return new \WP_Error( 'matches_empty', __( 'Add at least one match.', 'talenttrack' ) );
        }

        return [ 'matches' => $out ];
    }

    public function nextStep( array $state ): ?string { return 'review'; }
    public function submit( array $state ) { return null; }

    private static function blankRow(): array {
        return [
            'label'                => '',
            'opponent_name'        => '',
            'opponent_level'       => '',
            'formation'            => '',
            'duration_min'         => 20,
            'substitution_windows' => [],
        ];
    }

    /**
     * Render a single match card.
     *
     * @param array<int,string> $levels
     * @param array<int,string> $formations
     */
    private static function renderRow( int $i, array $m, array $levels, array $formations, string $default_formation ): void {
        $label_val = (string) ( $m['label'] ?? '' );
        $opp_val   = (string) ( $m['opponent_name'] ?? '' );
        $level_val = (string) ( $m['opponent_level'] ?? '' );
        $form_val  = (string) ( $m['formation'] ?? '' );
        $dur_val   = (int) ( $m['duration_min'] ?? 0 );
        if ( $dur_val <= 0 ) $dur_val = 20;
        $windows = is_array( $m['substitution_windows'] ?? null ) ? $m['substitution_windows'] : [];
        $windows_csv = implode( ',', array_map( 'intval', $windows ) );

        $headline = $label_val !== ''
            ? $label_val
            : ( $opp_val !== '' ? sprintf( __( 'vs %s', 'talenttrack' ), $opp_val ) : '' );
        $head_empty_cls = $headline === '' ? ' is-empty' : '';
        $head_text      = $headline === '' ? __( 'New match — fill in opponent below', 'talenttrack' ) : $headline;

        $default_form_label = $default_formation !== ''
            ? sprintf( __( '— use default (%s) —', 'talenttrack' ), $default_formation )
            : __( '— use default —', 'talenttrack' );

        $dur_max = max( 0, $dur_val - 1 );

        ?>
        <li class="ttw-match-card" data-row="<?php echo (int) $i; ?>">
            <div class="ttw-match-head">
                <span class="ttw-seq" aria-hidden="true"><?php echo (int) $i + 1; ?></span>
                <span class="ttw-headline<?php echo esc_attr( $head_empty_cls ); ?>" data-ttw-headline><?php echo esc_html( $head_text ); ?></span>
                <button type="button" class="ttw-remove" data-ttw-match-remove><?php esc_html_e( 'Remove', 'talenttrack' ); ?></button>
            </div>
            <div class="ttw-field-grid">
                <div class="ttw-field">
                    <label for="ttw-m-<?php echo (int) $i; ?>-label"><?php esc_html_e( 'Label (optional)', 'talenttrack' ); ?></label>
                    <input type="text" id="ttw-m-<?php echo (int) $i; ?>-label" data-ttw-field="label" name="matches[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $label_val ); ?>" placeholder="<?php esc_attr_e( 'e.g. Final, Round 1, Pool A…', 'talenttrack' ); ?>">
                    <span class="ttw-hint"><?php esc_html_e( 'Used as the headline when set; otherwise shows "vs <opponent>".', 'talenttrack' ); ?></span>
                </div>
                <div class="ttw-field">
                    <label for="ttw-m-<?php echo (int) $i; ?>-opp"><?php esc_html_e( 'Opponent', 'talenttrack' ); ?> <span class="ttw-req">*</span></label>
                    <input type="text" id="ttw-m-<?php echo (int) $i; ?>-opp" data-ttw-field="opponent_name" name="matches[<?php echo (int) $i; ?>][opponent_name]" value="<?php echo esc_attr( $opp_val ); ?>">
                </div>
                <div class="ttw-field">
                    <label for="ttw-m-<?php echo (int) $i; ?>-level"><?php esc_html_e( 'Opponent level', 'talenttrack' ); ?></label>
                    <select id="ttw-m-<?php echo (int) $i; ?>-level" data-ttw-field="opponent_level" name="matches[<?php echo (int) $i; ?>][opponent_level]">
                        <option value=""><?php esc_html_e( '— pick one —', 'talenttrack' ); ?></option>
                        <?php foreach ( $levels as $lv ) : ?>
                            <option value="<?php echo esc_attr( (string) $lv ); ?>" <?php selected( $level_val, (string) $lv ); ?>><?php echo esc_html( (string) $lv ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ttw-field">
                    <label for="ttw-m-<?php echo (int) $i; ?>-form"><?php esc_html_e( 'Formation override', 'talenttrack' ); ?></label>
                    <select id="ttw-m-<?php echo (int) $i; ?>-form" data-ttw-field="formation" name="matches[<?php echo (int) $i; ?>][formation]">
                        <option value=""><?php echo esc_html( $default_form_label ); ?></option>
                        <?php foreach ( $formations as $f ) : ?>
                            <option value="<?php echo esc_attr( (string) $f ); ?>" <?php selected( $form_val, (string) $f ); ?>><?php echo esc_html( (string) $f ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ttw-field">
                    <label for="ttw-m-<?php echo (int) $i; ?>-dur"><?php esc_html_e( 'Duration (minutes)', 'talenttrack' ); ?> <span class="ttw-req">*</span></label>
                    <input type="number" id="ttw-m-<?php echo (int) $i; ?>-dur" data-ttw-field="duration_min" name="matches[<?php echo (int) $i; ?>][duration_min]" min="1" max="240" inputmode="numeric" value="<?php echo esc_attr( (string) $dur_val ); ?>">
                </div>
                <div class="ttw-field">
                    <label><?php esc_html_e( 'Substitution windows (minutes from kickoff)', 'talenttrack' ); ?></label>
                    <div class="ttw-chip-editor" data-ttw-chip-editor>
                        <?php foreach ( $windows as $w ) : $w = (int) $w; if ( $w <= 0 ) continue; ?>
                            <span class="ttw-chip" data-value="<?php echo (int) $w; ?>"><?php echo (int) $w; ?>' <button type="button" class="ttw-chip-x" aria-label="<?php echo esc_attr( sprintf( __( 'Remove %d', 'talenttrack' ), $w ) ); ?>">&times;</button></span>
                        <?php endforeach; ?>
                        <input type="text" placeholder="<?php esc_attr_e( 'Add a minute and press Enter', 'talenttrack' ); ?>" inputmode="numeric" data-max="<?php echo esc_attr( (string) $dur_max ); ?>">
                        <input type="hidden" name="matches[<?php echo (int) $i; ?>][substitution_windows]" value="<?php echo esc_attr( $windows_csv ); ?>">
                        <span class="ttw-hint"><?php echo esc_html__( 'Press Enter or comma to add. Values must be 1–', 'talenttrack' ); ?><span data-ttw-dur-max><?php echo (int) $dur_max; ?></span>.</span>
                    </div>
                </div>
            </div>
        </li>
        <?php
    }
}
