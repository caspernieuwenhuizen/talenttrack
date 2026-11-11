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
 */
interface ComposesPerReader {}
