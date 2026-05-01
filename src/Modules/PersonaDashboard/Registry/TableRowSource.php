<?php
namespace TT\Modules\PersonaDashboard\Registry;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TableRowSource — contract a DataTableWidget preset implements to wire
 * real rows. Each row is a `list<string>` of pre-rendered HTML / text
 * cells aligned with the preset's `columns` config.
 */
interface TableRowSource {

    /**
     * @param int                  $user_id  Current viewer.
     * @param array<string, mixed> $config   Slot config (`days`, `limit`, ...).
     * @return list<list<string>>
     */
    public function rowsFor( int $user_id, array $config ): array;
}
