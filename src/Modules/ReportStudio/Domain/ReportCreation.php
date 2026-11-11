<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\ReportStudio\Repositories\ReportsRepository;

/**
 * ReportCreation (#4570) — create a report from its starting choices. The
 * one path `POST /reports` and the start wizard's last step both take, so
 * neither can store a combination the other would refuse.
 *
 * #5023 — started from a template, the report takes the choices the template
 * fixes and opens on a copy of its blocks. It is an ordinary report owned by
 * its builder: changing it never changes the template.
 */
final class ReportCreation {

    /**
     * @param array<string,mixed> $in the starting choices, as `ReportStartRules::validate()` reads them,
     *        and optionally `template`, a key from `ReportTemplateRegistry`
     * @return int|StudioError the new report's id, or why it was not made
     */
    public static function create( array $in, int $user_id ): int|StudioError {
        $template = null;
        $key      = $in['template'] ?? null;
        unset( $in['template'] );
        if ( $key !== null && $key !== '' ) {
            $template = self::template( $key, $in, $user_id );
            if ( $template instanceof StudioError ) return $template;
            $in = $template->choices + $in;
        }

        $choices = ReportStartRules::validate( $in, $user_id );
        if ( $choices instanceof StudioError ) return $choices;

        $definition = null;
        if ( $template !== null ) {
            $definition = ReportDefinition::normalise( $template->definition, $choices['layout'], $choices['scope'], $choices['audience'] );
            if ( $definition instanceof StudioError ) return $definition;
        }

        $repo = new ReportsRepository();
        $id   = $repo->create( $choices, $user_id );
        if ( $id <= 0 ) {
            return new StudioError( 'db_error', __( 'The report could not be saved.', 'talenttrack' ), 500 );
        }
        // A report that says it came from a template but holds none of its
        // blocks is not a report the builder asked for.
        if ( $definition !== null && $repo->saveDraft( $id, $user_id, $definition, null ) === null ) {
            return new StudioError( 'db_error', __( 'The report could not be saved.', 'talenttrack' ), 500 );
        }
        return $id;
    }

    /**
     * The template asked for, when this builder may start it and the choices
     * sent agree with the ones it fixes.
     *
     * @param array<string,mixed> $in
     */
    private static function template( mixed $key, array $in, int $user_id ): ReportTemplate|StudioError {
        $template = is_string( $key ) ? ReportTemplateRegistry::find( $key ) : null;
        if ( $template === null ) {
            return new StudioError( 'invalid_field', __( 'There is no template with that key.', 'talenttrack' ), 400, [ 'field' => 'template' ] );
        }
        $refusal = ReportTemplateRegistry::refusal( $template, ReportReader::for( $user_id ) );
        if ( $refusal !== null ) {
            return new StudioError( 'invalid_field', $refusal, 400, [ 'field' => 'template' ] );
        }
        foreach ( $template->choices as $field => $value ) {
            if ( isset( $in[ $field ] ) && $in[ $field ] !== '' && $in[ $field ] !== $value ) {
                return new StudioError( 'invalid_field', __( 'The template sets this choice.', 'talenttrack' ), 400, [ 'field' => $field ] );
            }
        }
        return $template;
    }
}
