<?php
namespace TT\Modules\Tournaments\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 4 — Matches. Repeatable mini-form. The coach fills as many
 * match cards as they need; empty cards drop on validate.
 *
 * Substitution windows render as a comma-separated input ("10" /
 * "20, 40, 60") rather than a chip editor — keeps the v1 wizard
 * lightweight. The planner detail page (chunk 5+) will expose a
 * richer chip-style editor for post-creation tweaks.
 */
final class MatchesStep implements WizardStepInterface {

    public function slug(): string { return 'matches'; }
    public function label(): string { return __( 'Matches', 'talenttrack' ); }

    public function render( array $state ): void {
        $matches = is_array( $state['matches'] ?? null ) ? $state['matches'] : [];
        // Show at least 3 rows on first visit; otherwise show as many
        // as the state has, plus one extra empty row.
        $rows = $matches;
        if ( ! $rows ) {
            $rows = [
                self::blankRow(),
                self::blankRow(),
                self::blankRow(),
            ];
        } else {
            $rows[] = self::blankRow();
        }

        $levels = QueryHelpers::get_lookup_names( 'tournament_opponent_level' );
        $formations = QueryHelpers::get_lookup_names( 'tournament_formation' );

        echo '<p>' . esc_html__( 'Add at least one match. Leave a row blank to skip it. You can add more matches from the planner after creating the tournament.', 'talenttrack' ) . '</p>';

        echo '<ol class="tt-wizard-match-list" style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:12px;" data-tt-match-list="1">';
        foreach ( $rows as $i => $m ) {
            self::renderRow( $i, $m, $levels, $formations );
        }
        echo '</ol>';

        echo '<p style="margin-top:10px;"><button type="button" class="tt-button tt-button-secondary" data-tt-match-add="1">+ ' . esc_html__( 'Add another match', 'talenttrack' ) . '</button></p>';

        // Tiny inline JS: clone the LAST .tt-wizard-match-row, increment
        // its data-row index, append. The framework will validate every
        // row at submit time and drop the empties.
        ?>
        <script>
        (function () {
            var list = document.querySelector('[data-tt-match-list="1"]');
            var add  = document.querySelector('[data-tt-match-add="1"]');
            if (!list || !add) return;
            add.addEventListener('click', function () {
                var rows = list.querySelectorAll('.tt-wizard-match-row');
                if (!rows.length) return;
                var last = rows[rows.length - 1];
                var clone = last.cloneNode(true);
                var newIdx = rows.length;
                clone.querySelectorAll('input,select,textarea').forEach(function (el) {
                    if (el.name) {
                        el.name = el.name.replace(/matches\[\d+\]/, 'matches[' + newIdx + ']');
                    }
                    if (el.type !== 'button') el.value = '';
                });
                clone.setAttribute('data-row', String(newIdx));
                list.appendChild(clone);
            });
        })();
        </script>
        <?php
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

            // Skip wholly-empty rows.
            if ( $label === '' && $opp === '' && $duration === 0 && $windows_raw === '' && $formation === '' && $level === '' ) {
                continue;
            }

            // At least one of (label, opponent_name) must be set so a row
            // has a headline. duration_min defaults to 20 when left blank
            // on an otherwise-filled row.
            if ( $label === '' && $opp === '' ) {
                return new \WP_Error(
                    'match_headline_required',
                    sprintf( __( 'Match #%d needs either a label or an opponent name.', 'talenttrack' ), (int) $idx + 1 )
                );
            }
            if ( $duration === 0 ) $duration = 20;

            // Parse "10" / "10, 20" / "10 20" — all valid.
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
     * @param array<int,string> $levels
     * @param array<int,string> $formations
     */
    private static function renderRow( int $i, array $m, array $levels, array $formations ): void {
        $windows_str = '';
        if ( ! empty( $m['substitution_windows'] ) && is_array( $m['substitution_windows'] ) ) {
            $windows_str = implode( ', ', array_map( 'intval', $m['substitution_windows'] ) );
        }
        ?>
        <li class="tt-wizard-match-row" data-row="<?php echo (int) $i; ?>" style="padding:10px;border:1px solid var(--tt-line, #e2e8f0);border-radius:6px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;">
            <label><span><?php esc_html_e( 'Label (optional)', 'talenttrack' ); ?></span>
                <input type="text" name="matches[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( (string) $m['label'] ); ?>">
            </label>
            <label><span><?php esc_html_e( 'Opponent name', 'talenttrack' ); ?></span>
                <input type="text" name="matches[<?php echo (int) $i; ?>][opponent_name]" value="<?php echo esc_attr( (string) $m['opponent_name'] ); ?>">
            </label>
            <label><span><?php esc_html_e( 'Opponent level', 'talenttrack' ); ?></span>
                <select name="matches[<?php echo (int) $i; ?>][opponent_level]">
                    <option value=""><?php esc_html_e( '— pick one —', 'talenttrack' ); ?></option>
                    <?php foreach ( $levels as $lv ) : ?>
                        <option value="<?php echo esc_attr( (string) $lv ); ?>" <?php selected( (string) $m['opponent_level'], (string) $lv ); ?>><?php echo esc_html( (string) $lv ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><span><?php esc_html_e( 'Formation override (optional)', 'talenttrack' ); ?></span>
                <select name="matches[<?php echo (int) $i; ?>][formation]">
                    <option value=""><?php esc_html_e( '— use default —', 'talenttrack' ); ?></option>
                    <?php foreach ( $formations as $f ) : ?>
                        <option value="<?php echo esc_attr( (string) $f ); ?>" <?php selected( (string) $m['formation'], (string) $f ); ?>><?php echo esc_html( (string) $f ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><span><?php esc_html_e( 'Duration (minutes)', 'talenttrack' ); ?></span>
                <input type="number" inputmode="numeric" min="1" max="240" name="matches[<?php echo (int) $i; ?>][duration_min]" value="<?php echo esc_attr( (string) ( $m['duration_min'] ?: 20 ) ); ?>">
            </label>
            <label><span><?php esc_html_e( 'Substitution windows (e.g. "10" or "20, 40, 60")', 'talenttrack' ); ?></span>
                <input type="text" inputmode="numeric" name="matches[<?php echo (int) $i; ?>][substitution_windows]" placeholder="10" value="<?php echo esc_attr( $windows_str ); ?>">
            </label>
        </li>
        <?php
    }
}
