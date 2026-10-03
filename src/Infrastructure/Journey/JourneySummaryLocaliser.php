<?php
namespace TT\Infrastructure\Journey;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\JourneyEventType;
use TT\Domain\Vocabularies\Lookups\TrialCaseDecision;

/**
 * JourneySummaryLocaliser — rewrites stored journey summaries that carry a
 * raw vocabulary key into the reader's language.
 *
 * "Trial ended" entries were written as `Trial ended: admit`, with the
 * decision key rather than its label. The summary column is stored text,
 * so those rows would keep the key for ever. Rather than a backfill, which
 * would freeze the label in whatever language the migration ran in, the
 * summary is rebuilt when it is read: an entry whose text ends in a known
 * decision key is replaced by the translated "Trial ended" sentence with
 * the decision's label. New entries are written with the label already,
 * and an operator-edited lookup label shows up on old entries too.
 *
 * Applied by {@see PlayerEventsRepository} on every read path, so the
 * timeline, the REST payload, the cohort view and the reports agree.
 */
final class JourneySummaryLocaliser {

    /**
     * Rewrites the rows' summaries in place (rows are objects).
     *
     * @param array<int, mixed> $rows
     */
    public static function localise( array $rows ): void {
        foreach ( $rows as $row ) {
            if ( ! is_object( $row ) || ! property_exists( $row, 'event_type' ) || ! property_exists( $row, 'summary' ) ) continue;
            $row->summary = self::summary( (string) $row->event_type, (string) $row->summary );
        }
    }

    public static function summary( string $event_type, string $summary ): string {
        if ( $event_type !== JourneyEventType::TRIAL_ENDED ) return $summary;

        foreach ( TrialCaseDecision::ALL as $key ) {
            $suffix = ': ' . $key;
            if ( substr( $summary, -strlen( $suffix ) ) === $suffix ) {
                return self::trialEnded( $key );
            }
        }
        return $summary;
    }

    /** The "Trial ended" sentence for a decision key, in the current language. */
    public static function trialEnded( string $decision ): string {
        /* translators: %s: the trial decision, e.g. "Admit (offer a place)" */
        return sprintf( __( 'Trial ended: %s', 'talenttrack' ), self::decisionLabel( $decision ) );
    }

    private static function decisionLabel( string $decision ): string {
        if ( class_exists( '\\TT\\Modules\\Trials\\Repositories\\TrialCasesRepository' ) ) {
            return \TT\Modules\Trials\Repositories\TrialCasesRepository::decisionLabel( $decision );
        }
        return $decision;
    }
}
