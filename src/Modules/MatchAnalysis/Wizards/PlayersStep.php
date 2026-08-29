<?php
namespace TT\Modules\MatchAnalysis\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\MatchAnalysis\Frontend\MatchAnalysisAssets;
use TT\Modules\MatchAnalysis\Frontend\PlayerTallyRoster;
use TT\Modules\MatchAnalysis\MatchAnalysisEnums;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisComposer;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisWriter;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * PlayersStep — the roster, with an optional marker and one specific line
 * per player.
 *
 * Everyone who played is listed, and the resting state of every row is
 * "not mentioned". A two-list "who stood out / who struggled" picker would
 * be quicker to fill in and would systematically overlook the quiet
 * players — which is the bias a talent system exists to counteract, not to
 * automate.
 */
final class PlayersStep implements WizardStepInterface {

    public function slug(): string  { return 'players'; }
    public function label(): string { return __( 'Players', 'talenttrack' ); }

    public function render( array $state ): void {
        MatchAnalysisAssets::enqueue();

        // The step renders the shared roster so the wizard and the flat
        // surface cannot grow two different ways of marking a player.
        // In-flight wizard answers win over what is stored, because the
        // coach may have gone Back and changed their mind.
        PlayerTallyRoster::render( self::withState( self::players( $state ), $state ), 'maw' );
    }

    /**
     * Overlay the wizard's own state onto the composed roster.
     *
     * @param list<array<string,mixed>> $players
     * @param array<string,mixed>       $state
     * @return list<array<string,mixed>>
     */
    private static function withState( array $players, array $state ): array {
        $saved = isset( $state['players'] ) && is_array( $state['players'] ) ? $state['players'] : [];
        if ( empty( $saved ) ) return $players;

        foreach ( $players as $index => $player ) {
            $pid = (int) $player['player_id'];
            if ( ! isset( $saved[ $pid ] ) || ! is_array( $saved[ $pid ] ) ) continue;

            $players[ $index ]['marker']        = (string) ( $saved[ $pid ]['marker'] ?? '' );
            $players[ $index ]['note_items']    = is_array( $saved[ $pid ]['notes'] ?? null )
                ? array_values( $saved[ $pid ]['notes'] )
                : [];
            $players[ $index ]['team_function'] = $saved[ $pid ]['team_function'] ?? null;
        }

        return $players;
    }

    public function validate( array $post, array $state ) {
        $posted = isset( $post['players'] ) && is_array( $post['players'] ) ? $post['players'] : [];
        $out    = [];

        foreach ( $posted as $pid => $item ) {
            $player_id = (int) $pid;
            if ( $player_id <= 0 || ! is_array( $item ) ) continue;

            $marker = sanitize_key( (string) ( $item['marker'] ?? '' ) );
            $tag    = sanitize_key( (string) ( $item['team_function'] ?? '' ) );
            $notes  = MatchAnalysisWriter::cleanNoteItems(
                array_key_exists( 'notes', $item ) ? $item['notes'] : ( $item['note'] ?? [] )
            );

            // Only carry the rows that say something. The rest are the
            // roster's resting state and never become records.
            if ( $marker === '' && $notes === [] ) continue;

            $out[ $player_id ] = [
                'marker'        => MatchAnalysisEnums::isMarker( $marker ) ? $marker : '',
                'notes'         => $notes,
                'team_function' => MatchAnalysisEnums::isPlayerItemTag( $tag ) ? $tag : null,
            ];
        }

        return [ 'players' => $out ];
    }

    public function nextStep( array $state ): ?string {
        return 'review';
    }

    public function submit( array $state ) {
        return null;
    }

    /**
     * @param array<string,mixed> $state
     * @return list<array<string,mixed>>
     */
    public static function players( array $state ): array {
        $payload = ( new MatchAnalysisComposer() )->forActivity( OverallStep::activityId( $state ), false );

        return $payload === null ? [] : (array) $payload['players'];
    }
}
