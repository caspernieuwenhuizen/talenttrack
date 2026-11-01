<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BlockEngine — renders one block for one context (#4568).
 *
 * The order is the contract: the block must exist, fit the context, be
 * readable with the reader's capability, the subject must pass
 * `ReportSubjectAccess`, the block must have data, and the options must be
 * ones the block declared. Only then does the block compose, and the payload
 * is cut to what the audience may receive before it is returned. No step
 * writes anything.
 */
final class BlockEngine {

    /**
     * @param array<string,mixed> $context_in
     * @param array<string,mixed> $options_in
     * @return array<string,mixed>|StudioError
     */
    public static function preview( string $key, array $context_in, array $options_in, int $user_id ): array|StudioError {
        $block = BlockRegistry::find( $key );
        if ( $block === null ) {
            return new StudioError( 'not_found', __( 'There is no block with that key.', 'talenttrack' ), 404 );
        }

        $context = ReportContext::fromInput( $context_in, $user_id );
        if ( $context instanceof StudioError ) return $context;

        if ( ! BlockRegistry::fits( $block, $context ) ) {
            return new StudioError( 'invalid_field', __( 'This block cannot be used for this subject or audience.', 'talenttrack' ), 400, [ 'field' => 'context' ] );
        }
        if ( ! user_can( $user_id, $block->capability() ) ) {
            return new StudioError( 'forbidden', __( 'You do not have access to this block.', 'talenttrack' ), 403 );
        }

        $context = ReportSubjectAccess::check( $context );
        if ( $context instanceof StudioError ) return $context;

        if ( ! $block->available( $context ) ) {
            return new StudioError( 'not_found', __( 'This block has no data for this subject.', 'talenttrack' ), 404 );
        }

        $options = self::validateOptions( $block, $context, $options_in );
        if ( $options instanceof StudioError ) return $options;

        $data = self::forAudience( $block, $context, $block->render( $context, $options ) );

        return [
            'block'   => $block->key(),
            'context' => [
                'scope'      => $context->scope,
                'player_id'  => $context->playerId ?: null,
                'team_id'    => $context->teamId ?: null,
                'player_ids' => $context->playerIds,
                'from'       => $context->from,
                'to'         => $context->to,
                'audience'   => $context->audience,
            ],
            'options' => $options,
            'data'    => $data,
        ];
    }

    /**
     * Cut a payload to what the audience may receive. For an external
     * audience only the block's declared external keys survive, and a
     * breakdown by player or a group average never does: in a small group an
     * average is another child's figure (`PlayerReportAudience::apply()`).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function forAudience( ReportBlock $block, ReportContext $context, array $data ): array {
        if ( ! $context->isExternal() ) return $data;
        $data = array_intersect_key( $data, array_flip( $block->externalKeys() ) );
        unset( $data['group_average'] );
        if ( ( $data['breakdown'] ?? null ) === 'player_id' ) {
            unset( $data['rows'] );
            $data['breakdown'] = null;
        }
        return $data;
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>|StudioError
     */
    private static function validateOptions( ReportBlock $block, ReportContext $context, array $raw ): array|StudioError {
        $declared = $block->options( $context );
        $known    = [];
        foreach ( $declared as $option ) $known[ $option->key ] = true;

        $unknown = array_values( array_diff( array_map( 'strval', array_keys( $raw ) ), array_keys( $known ) ) );
        if ( $unknown !== [] ) {
            return new StudioError(
                'unknown_field',
                /* translators: %s: comma-separated option names */
                sprintf( __( 'This block does not accept: %s.', 'talenttrack' ), implode( ', ', $unknown ) ),
                400,
                [ 'fields' => $unknown ]
            );
        }

        $out = [];
        foreach ( $declared as $option ) {
            [ $ok, $value ] = $option->validate( $raw[ $option->key ] ?? null, $out );
            if ( ! $ok ) {
                return new StudioError(
                    'invalid_field',
                    /* translators: %s: option label */
                    sprintf( __( '%s has a value this block does not offer.', 'talenttrack' ), $option->label ),
                    400,
                    [ 'field' => $option->key ]
                );
            }
            $out[ $option->key ] = $value;
        }
        return $out;
    }
}
