<?php
namespace TT\Modules\Export;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LabelTranslator;
use TT\Infrastructure\Query\LookupTranslator;

/**
 * ExportValueFormatter (#2012) — display labels for human-facing exports.
 *
 * Exporters wrote raw database values into the CSV/XLSX cells while the
 * frontend routed the same columns through `LabelTranslator`. The same
 * record therefore read "Actief / Centrale verdediger" on screen and
 * `active / ["CB","LB"]` in the file a coach sends to a parent or a
 * federation desk — the export is usually the artifact that leaves the
 * system, so raw codes are most visible exactly where they are least
 * wanted.
 *
 * This is the one place that knows how a stored value becomes a label, so
 * six exporters do not each re-derive it, and a future non-WordPress export
 * consumer has a single chokepoint to call.
 *
 * Machine-facing exports do NOT use this: `DemoDataXlsxExporter` round-trips
 * back through import, `BackupZipExporter` is a fidelity dump and
 * `GdprSubjectAccessZipExporter` is a raw record dump. Humanising those
 * would break re-import or a downstream contract.
 *
 * ## Stored formats are not uniform, and that is the trap
 *
 * The helpers in `LabelTranslator` each expect the format their own module
 * stores, and those disagree:
 *
 * - `tt_players.status`, `tt_people.status`, `tt_people.role_type` are
 *   lowercase (`active`, `coach`) and the helpers switch on exactly that.
 * - `tt_attendance.status` is capitalised (`Present`, `Absent`, `Late`) and
 *   `attendanceStatus()` switches on that.
 * - `tt_goals.status` is title case with spaces (`In Progress`, `On Hold`)
 *   while `goalStatus()` switches on snake_case (`in_progress`).
 *
 * A helper handed the wrong format does not fail — it falls through to its
 * default and returns the raw value, so the export looks unchanged and the
 * bug is invisible. The goal normalisation below is why this class exists
 * rather than six inline calls; it mirrors what `FrontendGoalsManageView`
 * already does.
 */
final class ExportValueFormatter {

    /** Player lifecycle status: `active` → "Actief". */
    public static function playerStatus( $value ): string {
        $code = self::str( $value );
        return $code === '' ? '' : LabelTranslator::playerStatus( $code );
    }

    /** Staff lifecycle status: `active` → "Actief". */
    public static function personStatus( $value ): string {
        $code = self::str( $value );
        return $code === '' ? '' : LabelTranslator::personStatus( $code );
    }

    /** Staff role type: `coach` → "Trainer". */
    public static function roleType( $value ): string {
        $code = self::str( $value );
        return $code === '' ? '' : LabelTranslator::roleType( $code );
    }

    /**
     * Attendance mark: `Present` → "Aanwezig".
     *
     * Stored capitalised, which is what the helper expects — verified
     * against real data rather than assumed, because a mismatch here is
     * silent.
     */
    public static function attendanceStatus( $value ): string {
        $code = self::str( $value );
        return $code === '' ? '' : LabelTranslator::attendanceStatus( $code );
    }

    /**
     * Goal status: `In Progress` → "In ontwikkeling".
     *
     * Normalises to the snake_case the helper switches on. Without this the
     * call is a no-op that returns the English value untouched.
     */
    public static function goalStatus( $value ): string {
        $code = self::str( $value );
        if ( $code === '' ) return '';
        return LabelTranslator::goalStatus( strtolower( str_replace( ' ', '_', $code ) ) );
    }

    /** Goal priority: `Medium` → "Gemiddeld". The helper lowercases itself. */
    public static function goalPriority( $value ): string {
        $code = self::str( $value );
        return $code === '' ? '' : LabelTranslator::goalPriority( $code );
    }

    /** Preferred foot: stored as a lookup value (`Right`, `Both`). */
    public static function preferredFoot( $value ): string {
        $code = self::str( $value );
        return $code === '' ? '' : LookupTranslator::byTypeAndName( 'foot_option', $code );
    }

    /**
     * Preferred positions: `["CB","LB"]` → "Centrale verdediger / Linksback".
     *
     * Stored as a JSON array. Unknown or custom codes pass through via the
     * helper's own fallback rather than being dropped — an export that
     * silently loses a position is worse than one showing a raw code.
     * Separator matches the player detail Key Facts row.
     */
    public static function positions( $value ): string {
        $raw = self::str( $value );
        if ( $raw === '' ) return '';

        $codes = json_decode( $raw, true );
        if ( ! is_array( $codes ) ) {
            // Not JSON — an older row may hold a bare code or a plain list.
            $codes = array_map( 'trim', explode( ',', $raw ) );
        }

        $labels = [];
        foreach ( $codes as $code ) {
            if ( ! is_scalar( $code ) ) continue;
            $code = trim( (string) $code );
            if ( $code === '' ) continue;
            $labels[] = LabelTranslator::positionLabel( $code );
        }

        return implode( ' / ', $labels );
    }

    private static function str( $value ): string {
        return is_scalar( $value ) ? trim( (string) $value ) : '';
    }
}
