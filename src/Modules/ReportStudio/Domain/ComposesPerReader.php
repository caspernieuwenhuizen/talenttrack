<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ComposesPerReader (#4963, epic #4564) — a block whose payload depends on
 * who reads it beyond the checks `BlockEngine::gate()` asks and the cut
 * `BlockEngine::cutForReader()` makes: it aggregates over the rows the
 * reader's own scope reaches (`OwnQuestionBlock` through `FactScope`), so a
 * count is a different number for a coach and for the head of development.
 *
 * One copy for several readers (`BlockEngine::forReaders()`) composes such a
 * block for each of them, and keeps it only when every payload is the same.
 *
 * **When a block must carry it:** whenever its figure is aggregated over rows
 * the reader's own scope decides (`FactQuery` with
 * `ReportSubjectAccess::factScope()`, or any read by the reader's scope
 * rather than by the subject), and the subject filters do not already pin
 * those rows to the subject's own players. `AttendancePerWeekBlock` reads
 * through `factScope()` too, but its subject filters bind it to the
 * subject's players, so every reader gets the same figure and it does not
 * carry the marker. Classify the next fact-backed block on purpose.
 */
interface ComposesPerReader {}
