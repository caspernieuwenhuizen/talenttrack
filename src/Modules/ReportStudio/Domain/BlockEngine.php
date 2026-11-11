<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RecordRedactor;
use TT\Modules\Analytics\Domain\UnknownFactKeyException;

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

        $context = self::gate( $block, $context_in, $user_id );
        if ( $context instanceof StudioError ) return $context;

        if ( $block instanceof ChecksFactKeys ) {
            try {
                $options_in = $block->resolveStoredKeys( $options_in );
            } catch ( UnknownFactKeyException $e ) {
                return self::unknownField( $e );
            }
        }
        $options = self::validateOptions( $block, $context, $options_in );
        if ( $options instanceof StudioError ) return $options;

        // #4860 — a block that names a field its fact no longer has is
        // refused, never answered for more rows than it asked about. A stored
        // report shows the refusal in that block's place; a preview is 400.
        try {
            $rendered = $block->render( $context, $options );
        } catch ( UnknownFactKeyException $e ) {
            return self::unknownField( $e );
        }
        $data = self::masked( self::forAudience( $block, $context, $rendered ), $user_id );

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
     * Whether this reader may read this block about this subject: the block
     * fits the context, the reader holds its capability, the subject passes
     * `ReportSubjectAccess`, and the block has data. The checks `preview()`
     * asks before a block composes, and the ones a frozen copy answers to for
     * each reader (#5021).
     *
     * @param array<string,mixed> $context_in
     */
    public static function gate( ReportBlock $block, array $context_in, int $user_id ): ReportContext|StudioError {
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
        return $context;
    }

    /**
     * #4963 — one block, composed once for several readers: what every one of
     * them may read, and nothing one of them may not. The rule a scheduled
     * PDF follows, one copy for everyone it goes to (#5014's, for every
     * block).
     *
     * The block is composed as the first reader, the schedule's owner. Then,
     * for each other reader, it must pass the checks it answers live
     * (`gate()`: fit, capability, subject, data) and is cut to what they may
     * read (`cutForReader()`), so a field one reader may not see is masked for
     * everyone, and a row about a player one reader may not see is left out.
     * A block that fails for one reader is left out for all. A reader of 0 —
     * whose rights cannot be checked — has none, so only a block that needs
     * no right at all survives them.
     *
     * A block whose figures depend on the reader's own scope
     * (`ComposesPerReader`) is composed for each reader and kept only when
     * every payload is the same.
     *
     * A refusal for the first reader is returned as `preview()` gives it; a
     * block left out because of another reader is `403 not_for_every_reader`
     * or `differs_per_reader`, with the reason a schedule preview shows.
     *
     * @param array<string,mixed> $context_in
     * @param array<string,mixed> $options_in
     * @return array<string,mixed>|StudioError
     */
    public static function forReaders( string $key, array $context_in, array $options_in, \TT\Infrastructure\Visibility\ReaderSet $readers ): array|StudioError {
        $first = $readers->first();
        $read  = self::preview( $key, $context_in, $options_in, $first );
        if ( $read instanceof StudioError ) return $read;

        $block = BlockRegistry::find( $key );
        if ( $block === null ) return new StudioError( 'not_found', __( 'There is no block with that key.', 'talenttrack' ), 404 );

        $context = is_array( $read['context'] ?? null ) ? $read['context'] : $context_in;
        $data    = self::keyed( $read['data'] ?? [] );
        foreach ( $readers->readers as $reader ) {
            if ( $reader === $first ) continue;
            $cut = self::cutForReader( $block, $data, $context, $reader );
            if ( $cut === null ) {
                return new StudioError( 'not_for_every_reader', __( 'Not every recipient may read this block.', 'talenttrack' ), 403, [ 'block' => $key ] );
            }
            $data = $cut;
        }

        if ( $block instanceof ComposesPerReader ) {
            $own = self::keyed( $read['data'] ?? [] );
            foreach ( $readers->readers as $reader ) {
                if ( $reader === $first ) continue;
                $theirs = self::preview( $key, $context_in, $options_in, $reader );
                if ( $theirs instanceof StudioError || self::keyed( $theirs['data'] ?? [] ) != $own ) {
                    return new StudioError( 'differs_per_reader', __( 'Its figures differ between recipients, so it is left out.', 'talenttrack' ), 403, [ 'block' => $key ] );
                }
            }
        }

        $read['data'] = $data;
        return $read;
    }

    /**
     * #4963 — a payload composed for one reader, as another reader may read
     * it about the same subject: the checks the block answers live
     * (`gate()`), the records its composer gates inside the payload
     * (`CutsForReader`), the rows about players they may not see, and the
     * fields they may not see. Null when they may not read the block at all.
     *
     * The one cut a frozen copy (`ReportSnapshots::forReader()`) and a copy
     * for several readers (`forReaders()`) both make, so a snapshot and a
     * schedule never disagree about what a reader may read.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $context the document's context: scope, player_id, team_id, player_ids, from, to, audience
     * @return array<string,mixed>|null
     */
    public static function cutForReader( ReportBlock $block, array $data, array $context, int $reader ): ?array {
        if ( self::gate( $block, $context, $reader ) instanceof StudioError ) return null;
        // #4961 — a block whose records are gated inside the payload is cut
        // by the rights its composer asks.
        if ( $block instanceof CutsForReader ) {
            $cut = $block->forReader( $data, $reader, $context );
            if ( $cut === null ) return null;
            $data = $cut;
        }
        return self::masked( self::visibleRows( $data, $reader ), $reader );
    }

    /**
     * @param mixed $value
     * @return array<string,mixed>
     */
    private static function keyed( mixed $value ): array {
        $out = [];
        foreach ( is_array( $value ) ? $value : [] as $k => $v ) $out[ (string) $k ] = $v;
        return $out;
    }

    /**
     * #5021 — a payload's rows about players this reader may not see, left
     * out. A frozen copy carries the rows its taker could see; a reader with a
     * narrower scope reads only their own players' rows. A row names its
     * player as `masked()` reads it.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function visibleRows( array $data, int $user_id ): array {
        if ( ! is_array( $data['rows'] ?? null ) ) return $data;
        $by_key = ( $data['breakdown'] ?? null ) === 'player_id';

        $ids = [];
        foreach ( $data['rows'] as $row ) {
            $pid = self::playerOf( $row, $by_key );
            if ( $pid > 0 ) $ids[ $pid ] = $pid;
        }
        if ( $ids === [] ) return $data;
        $allowed = AuthorizationService::canViewPlayers( $user_id, array_values( $ids ) );

        $rows = [];
        foreach ( $data['rows'] as $row ) {
            $pid = self::playerOf( $row, $by_key );
            if ( $pid > 0 && empty( $allowed[ $pid ] ) ) continue;
            $rows[] = $row;
        }
        $data['rows'] = $rows;
        return $data;
    }

    /** The player a row is about: `player_id`, or `key` on a breakdown by player; 0 for none. */
    private static function playerOf( mixed $row, bool $by_key ): int {
        if ( ! is_array( $row ) ) return 0;
        if ( isset( $row['player_id'] ) && is_numeric( $row['player_id'] ) ) return (int) $row['player_id'];
        return $by_key && isset( $row['key'] ) && is_numeric( $row['key'] ) ? (int) $row['key'] : 0;
    }

    /** The refusal for a block that names a field its fact no longer has. */
    public static function unknownField( UnknownFactKeyException $e ): StudioError {
        return new StudioError(
            'unknown_dimension',
            /* translators: %s: the name of the field that no longer exists */
            sprintf( __( 'This block refers to a field that no longer exists: %s.', 'talenttrack' ), $e->key ),
            400,
            $e->details()
        );
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
     * #4591 — a block's rows about a player go through the sensitive-field
     * registry (`RecordRedactor`, entity `players`), so a field this reader
     * may not see about that player never leaves the server, whichever block
     * and whichever route carries it. A row names its player in `player_id`,
     * or in `key` when the block is broken down by player.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function masked( array $data, int $user_id ): array {
        if ( ! is_array( $data['rows'] ?? null ) || RecordRedactor::fields( 'players' ) === [] ) return $data;

        $by_key = ( $data['breakdown'] ?? null ) === 'player_id';
        $player = static fn( mixed $row ): int => self::playerOf( $row, $by_key );

        $teams = [];
        foreach ( $data['rows'] as $row ) {
            $pid = $player( $row );
            if ( $pid > 0 ) $teams[ $pid ] = null;
        }
        if ( $teams === [] ) return $data;
        RecordRedactor::prime( $user_id, $teams );

        $rows = [];
        foreach ( $data['rows'] as $row ) {
            $pid = $player( $row );
            if ( $pid <= 0 || ! is_array( $row ) ) {
                $rows[] = $row;
                continue;
            }
            $keyed = [];
            foreach ( $row as $k => $v ) $keyed[ (string) $k ] = $v;
            $rows[] = RecordRedactor::redact( 'players', $keyed, $user_id, $pid );
        }
        $data['rows'] = $rows;
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
