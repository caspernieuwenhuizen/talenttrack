<?php
namespace TT\Modules\Wizards\Goal;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 2 — link a methodology entity. Polymorphic by `link_type`:
 * principle / football_action / position / value (or skip = no link).
 *
 * Picking a type changes the candidate list for the second select.
 * The framework re-renders on each step submission so we don't need
 * client-side JS for the cascade — the user picks a type, hits Next,
 * the step re-renders showing the candidates of that type, the user
 * picks one and hits Next again.
 */
final class LinkStep implements WizardStepInterface {

    public function slug(): string { return 'link'; }
    public function label(): string { return __( 'Methodology link', 'talenttrack' ); }

    public function render( array $state ): void {
        $type = (string) ( $state['link_type'] ?? '' );
        echo '<p>' . esc_html__( 'Optionally link this goal to a methodology entity. Pick a type and click Next to choose the specific entity, or leave on "— no link —" to skip.', 'talenttrack' ) . '</p>';

        // v3.85.3 — was auto-clicking Next on type change, which made
        // the cascade impossible to use: picking a type immediately
        // advanced to the Details step before the operator could pick
        // the candidate from the second select. Plain select now;
        // operator clicks Next themselves and nextStep() decides
        // whether to stay on this step (type set, link_id still 0)
        // or advance to details.
        echo '<label><span>' . esc_html__( 'Link type', 'talenttrack' ) . '</span><select name="link_type">';
        echo '<option value="">' . esc_html__( '— no link —', 'talenttrack' ) . '</option>';
        foreach ( self::types() as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '" ' . selected( $type, $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></label>';

        if ( $type === '' ) return;

        $candidates = self::candidates( $type );
        $current_id = (int) ( $state['link_id'] ?? 0 );
        // v3.110.x — context-driven label per chosen type instead of
        // generic "Pick the entity to link". The label maps to the
        // type the operator just chose so the second select reads as
        // a coherent question ("Position" / "Principle" / etc.).
        echo '<label><span>' . esc_html( self::secondSelectLabel( $type ) ) . '</span><select name="link_id">';
        echo '<option value="0">' . esc_html__( '— pick one —', 'talenttrack' ) . '</option>';
        if ( empty( $candidates ) ) {
            echo '<option value="0" disabled>' . esc_html__( '(no entries configured for this type)', 'talenttrack' ) . '</option>';
        }
        foreach ( $candidates as $row ) {
            echo '<option value="' . esc_attr( (string) $row['id'] ) . '" ' . selected( $current_id, (int) $row['id'], false ) . '>' . esc_html( (string) $row['label'] ) . '</option>';
        }
        echo '</select></label>';
    }

    /**
     * v3.110.x — context-driven label for the second-select per
     * chosen link type. Reuses the same translatable strings as the
     * first-select option labels in {@see self::types()}.
     */
    private static function secondSelectLabel( string $type ): string {
        switch ( $type ) {
            case 'principle':       return __( 'Principle', 'talenttrack' );
            case 'football_action': return __( 'Football action', 'talenttrack' );
            case 'position':        return __( 'Position', 'talenttrack' );
            case 'value':           return __( 'Value', 'talenttrack' );
        }
        return __( 'Pick the entity to link', 'talenttrack' );
    }

    public function validate( array $post, array $state ) {
        $type = isset( $post['link_type'] ) ? sanitize_key( (string) $post['link_type'] ) : '';
        $id   = isset( $post['link_id'] ) ? absint( $post['link_id'] ) : 0;
        if ( ! in_array( $type, array_keys( self::types() ), true ) ) $type = '';
        return [ 'link_type' => $type, 'link_id' => $id ];
    }

    /**
     * v3.85.3 — stay on this step until either type is empty (skip
     * the link entirely → advance) or both type AND link_id are set
     * (operator picked the candidate → advance). Returning self::slug
     * makes the framework re-render this step so the candidate picker
     * appears for the chosen type.
     */
    public function nextStep( array $state ): ?string {
        $type = (string) ( $state['link_type'] ?? '' );
        $id   = (int) ( $state['link_id'] ?? 0 );
        if ( $type !== '' && $id <= 0 ) {
            return $this->slug();
        }
        return 'details';
    }
    public function submit( array $state ) { return null; }

    /** @return array<string,string> */
    private static function types(): array {
        return [
            'principle'       => __( 'Principle', 'talenttrack' ),
            'football_action' => __( 'Football action', 'talenttrack' ),
            'position'        => __( 'Position', 'talenttrack' ),
            'value'           => __( 'Value', 'talenttrack' ),
        ];
    }

    /**
     * @return array<int,array{id:int,label:string}>
     */
    private static function candidates( string $type ): array {
        global $wpdb;
        $rows = [];
        switch ( $type ) {
            case 'principle':
                // v3.85.3 — was selecting `name` column which doesn't
                // exist on tt_principles. The schema (migration 0015)
                // uses `title_json`. Empty dropdowns were the result.
                // Read title_json + decode via MultilingualField for
                // the operator's locale, mirroring how the activity
                // edit form renders the same data.
                $raw = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, code, title_json FROM {$wpdb->prefix}tt_principles
                     WHERE archived_at IS NULL AND club_id = %d ORDER BY code",
                    CurrentClub::id()
                ) );
                $out = [];
                foreach ( (array) $raw as $r ) {
                    $title = '';
                    if ( class_exists( '\\TT\\Modules\\Methodology\\Helpers\\MultilingualField' ) ) {
                        $title = (string) \TT\Modules\Methodology\Helpers\MultilingualField::string( $r->title_json );
                    }
                    $label = trim( (string) $r->code . ( $title !== '' ? ' — ' . $title : '' ) );
                    $out[] = [ 'id' => (int) $r->id, 'label' => $label ];
                }
                return $out;
            case 'football_action':
                // v3.85.3 — same fix: tt_football_actions uses
                // `name_json`, not `name`.
                $raw = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, slug, name_json FROM {$wpdb->prefix}tt_football_actions
                     WHERE archived_at IS NULL AND club_id = %d ORDER BY sort_order, slug",
                    CurrentClub::id()
                ) );
                $out = [];
                foreach ( (array) $raw as $r ) {
                    $name = '';
                    if ( class_exists( '\\TT\\Modules\\Methodology\\Helpers\\MultilingualField' ) ) {
                        $name = (string) \TT\Modules\Methodology\Helpers\MultilingualField::string( $r->name_json );
                    }
                    $out[] = [ 'id' => (int) $r->id, 'label' => $name !== '' ? $name : (string) $r->slug ];
                }
                return $out;
            case 'position':
            case 'value':
                // v3.110.x — `tt_lookups` has NO `archived_at` column
                // (initial schema in migration 0001 didn't include
                // it; same root cause that BasicsStep::render
                // documented for the team wizard's age-group
                // dropdown). The previous WHERE clause referenced
                // `archived_at IS NULL` which made MySQL throw
                // "Unknown column 'archived_at'" so the entire
                // query failed and the second-select rendered empty
                // for every install since the wizard shipped.
                // Lookups use HARD delete via the lookups admin so
                // there is nothing to filter on here anyway.
                $lookup_type = $type === 'position' ? 'position' : 'club_value';
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, name AS label FROM {$wpdb->prefix}tt_lookups
                     WHERE lookup_type = %s AND club_id = %d ORDER BY sort_order, name",
                    $lookup_type, CurrentClub::id()
                ) );
                break;
        }
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[] = [ 'id' => (int) $r->id, 'label' => (string) $r->label ];
        }
        return $out;
    }
}
