<?php
namespace TT\Modules\MatchAnalysis\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\MatchAnalysis\MatchAnalysisEnums;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisComposer;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisWriter;

/**
 * SectionStepFields — the shared body of every section-writing wizard step.
 *
 * Two steps ask the same question about different sections (the four team
 * functions, then set pieces), and the flat surface asks it a third way.
 * Keeping the markup and the read-back in one place is what stops the
 * wizard and the form growing different rating vocabularies.
 */
final class SectionStepFields {

    private const BULLETS = 4;

    /**
     * Render rating chips + bullet inputs for one section.
     *
     * @param array<string,mixed> $state
     */
    public static function render( string $section_key, array $state, ?object $prep ): void {
        $label   = MatchAnalysisEnums::sectionLabel( $section_key );
        $saved   = self::savedFor( $section_key, $state );
        $planned = MatchAnalysisComposer::plannedTextFor( $section_key, $prep );

        echo '<fieldset class="tt-ma-wz__section">';
        echo '<legend class="tt-ma-wz__legend">' . esc_html( $label ) . '</legend>';

        if ( $planned !== '' ) {
            echo '<p class="tt-ma__planned"><span class="tt-ma__planned-label">'
                . esc_html__( 'Planned', 'talenttrack' ) . '</span> '
                . esc_html( str_replace( "\n", ' · ', $planned ) )
                . '</p>';
        }

        printf(
            '<div class="tt-ma__ratings" role="radiogroup" aria-label="%s">',
            esc_attr( sprintf(
                /* translators: %s: section name, e.g. Aanvallen */
                __( 'Rating — %s', 'talenttrack' ),
                $label
            ) )
        );

        $options = [ '' => __( 'Not rated', 'talenttrack' ) ] + MatchAnalysisEnums::ratings();
        foreach ( $options as $value => $option_label ) {
            $id = 'tt-ma-wz-' . sanitize_key( $section_key ) . '-' . ( $value === '' ? 'none' : sanitize_key( (string) $value ) );
            printf(
                '<input type="radio" class="tt-ma__rating-input" id="%1$s" name="sections[%2$s][rating]" value="%3$s"%4$s />'
                . '<label class="tt-ma__rating" for="%1$s" data-rating="%3$s">%5$s</label>',
                esc_attr( $id ),
                esc_attr( $section_key ),
                esc_attr( (string) $value ),
                checked( (string) ( $saved['rating'] ?? '' ), (string) $value, false ),
                esc_html( (string) $option_label )
            );
        }
        echo '</div>';

        $lines = is_array( $saved['notes'] ?? null ) ? array_values( $saved['notes'] ) : [];

        echo '<ul class="tt-ma__bullets">';
        for ( $i = 0; $i < self::BULLETS; $i++ ) {
            printf(
                '<li><input type="text" class="tt-input tt-ma__bullet" name="sections[%1$s][notes][%2$d]" value="%3$s" maxlength="180" placeholder="%4$s" aria-label="%5$s" /></li>',
                esc_attr( $section_key ),
                $i,
                esc_attr( (string) ( $lines[ $i ] ?? '' ) ),
                esc_attr__( 'One short point…', 'talenttrack' ),
                esc_attr( sprintf(
                    /* translators: 1: section name, 2: bullet number */
                    __( '%1$s — point %2$d', 'talenttrack' ),
                    $label,
                    $i + 1
                ) )
            );
        }
        echo '</ul>';
        echo '</fieldset>';
    }

    /**
     * Pull the submitted sections out of a step's POST, keeping only the
     * keys that step owns. A step must not be able to overwrite a section
     * it never showed — that is how a Back-then-Next loop silently wipes
     * the step after it.
     *
     * @param array<string,mixed> $post
     * @param list<string>        $section_keys
     * @param array<string,mixed> $state
     * @return array<string,mixed> merge into state
     */
    public static function collect( array $post, array $section_keys, array $state ): array {
        $sections = isset( $state['sections'] ) && is_array( $state['sections'] ) ? $state['sections'] : [];
        $posted   = isset( $post['sections'] ) && is_array( $post['sections'] ) ? $post['sections'] : [];

        foreach ( $section_keys as $key ) {
            $row = isset( $posted[ $key ] ) && is_array( $posted[ $key ] ) ? $posted[ $key ] : [];

            $notes = isset( $row['notes'] ) && is_array( $row['notes'] ) ? $row['notes'] : [];
            $notes = array_map( static fn( $line ): string => sanitize_text_field( (string) $line ), $notes );

            $sections[ $key ] = [
                'rating' => MatchAnalysisWriter::cleanRating( $row['rating'] ?? null ),
                'notes'  => array_values( array_filter( $notes, static fn( string $l ): bool => trim( $l ) !== '' ) ),
            ];
        }

        return [ 'sections' => $sections ];
    }

    /**
     * @param array<string,mixed> $state
     * @return array{rating:?string, notes:list<string>}
     */
    private static function savedFor( string $section_key, array $state ): array {
        $sections = isset( $state['sections'] ) && is_array( $state['sections'] ) ? $state['sections'] : [];
        $row      = isset( $sections[ $section_key ] ) && is_array( $sections[ $section_key ] )
            ? $sections[ $section_key ]
            : [];

        return [
            'rating' => isset( $row['rating'] ) ? (string) $row['rating'] : null,
            'notes'  => isset( $row['notes'] ) && is_array( $row['notes'] ) ? array_values( $row['notes'] ) : [],
        ];
    }
}
