<?php
namespace TT\Modules\DemoData;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DemoRunPlan — which steps a demo run is made of, in order (#3041).
 *
 * The order is `DemoCoverage`'s `run_order`, which is a reproducibility
 * contract rather than a convenience: the same seed and preset must produce
 * the same dataset, and that only holds if the generators run in the same
 * sequence every time. Chunking the run across requests changes nothing here
 * — it changes only how many requests the sequence is spread over.
 */
final class DemoRunPlan {

    /** WP users + the people directory. Runs in the submitting request. */
    public const STEP_PEOPLE = 'people';

    /** The uploaded workbook. Runs in the submitting request. */
    public const STEP_EXCEL = 'excel';

    public const STEP_TEAMS   = 'teams';
    public const STEP_PLAYERS = 'players';

    /** The tagging sweep over hook-written journey rows. Always last. */
    public const STEP_JOURNEY = 'journey';

    /** Dependent-generator steps carry their category after this prefix. */
    public const DEPENDENT_PREFIX = 'dep:';

    /**
     * Steps that must run inside the request that submitted the form: one
     * needs the password, the other the uploaded file, and neither may be
     * written down between requests.
     */
    public static function isInline( string $step ): bool {
        return $step === self::STEP_PEOPLE || $step === self::STEP_EXCEL;
    }

    public static function categoryOf( string $step ): ?string {
        if ( strpos( $step, self::DEPENDENT_PREFIX ) !== 0 ) {
            return null;
        }
        return substr( $step, strlen( self::DEPENDENT_PREFIX ) );
    }

    public static function forCategory( string $category ): string {
        return self::DEPENDENT_PREFIX . $category;
    }

    /**
     * Every step this run will take, in order.
     *
     * A step the run will skip anyway — a category the operator switched
     * off, a sheet the workbook already covered — is left out rather than
     * included and no-opped, so the progress the overlay shows is the work
     * that is actually going to happen.
     *
     * @param array<string,mixed> $context source, gen_people, gen_flags,
     *                                     excel_present_sheets
     * @return list<string>
     */
    public static function build( array $context ): array {
        $source = (string) ( $context['source'] ?? 'procedural' );
        $steps  = [];

        if ( $source !== 'procedural' || ! empty( $context['gen_people'] ) ) {
            $steps[] = self::STEP_PEOPLE;
        }
        if ( $source !== 'procedural' ) {
            $steps[] = self::STEP_EXCEL;
        }

        $steps[] = self::STEP_TEAMS;
        $steps[] = self::STEP_PLAYERS;

        if ( $source !== 'excel' ) {
            $raw_flags  = $context['gen_flags'] ?? null;
            $raw_sheets = $context['excel_present_sheets'] ?? null;
            $flags      = is_array( $raw_flags ) ? $raw_flags : [];
            $sheets     = is_array( $raw_sheets ) ? $raw_sheets : [];

            foreach ( array_keys( DemoCoverage::dependentGenerators() ) as $category ) {
                if ( ! ( $flags[ $category ] ?? true ) ) continue;

                $sheet = DemoCoverage::excelSheetFor( $category );
                if ( $sheet !== null && in_array( $sheet, $sheets, true ) ) continue;

                $steps[] = self::forCategory( $category );
            }
        }

        $steps[] = self::STEP_JOURNEY;

        return $steps;
    }

    /** What the overlay calls this step while it runs. */
    public static function label( string $step ): string {
        switch ( $step ) {
            case self::STEP_PEOPLE:
                return __( 'Accounts and staff', 'talenttrack' );
            case self::STEP_EXCEL:
                return __( 'Importing the workbook', 'talenttrack' );
            case self::STEP_TEAMS:
                return __( 'Teams', 'talenttrack' );
            case self::STEP_PLAYERS:
                return __( 'Players', 'talenttrack' );
            case self::STEP_JOURNEY:
                return __( 'Tagging journey events', 'talenttrack' );
        }

        $category = self::categoryOf( $step );

        return $category !== null ? DemoCoverage::categoryLabel( $category ) : $step;
    }
}
