# TalentTrack v4.0.10 — Players / Attendance / Goals bulk exports get an XLSX option (closes #864)

## Pilot ask

> I want to have xlsx and csv instead of only csv as option

Coaches commonly want XLSX so they can pivot in Excel without re-saving, share with parents/board who prefer the format, and preserve number formatting (CSV loses leading zeros on jersey numbers; date interpretation flips with locale).

## Scope

Three row-oriented exporters move from CSV-only to CSV + XLSX:

- `players_list`
- `attendance_register`
- `goals_list`

Other exporters keep their single canonical format (PDFs, ICS, JSON, ZIP, multi-sheet XLSX) — they already fit their job.

## Architecture choice

Approach (A) per the issue body: **one exporter per data shape, multi-format**. The exporter's `collect()` output is renderer-shape-agnostic — both `CsvRenderer` and `XlsxRenderer` can consume the same `[ 'headers' => …, 'rows' => … ]` payload. Avoids the parallel-classes duplication of approach (B), and avoids the renaming churn the issue flagged.

Class names stay (`PlayersListCsvExporter` etc.) — they're internal; renaming for cosmetics costs review noise without changing behaviour.

## Changes

### Exporters

```php
// PlayersListCsvExporter / AttendanceRegisterCsvExporter / GoalsCsvExporter
public function label(): string { return __( 'Players list', 'talenttrack' ); }
//                                       // ^ drops the "(CSV)" suffix
public function supportedFormats(): array { return [ 'csv', 'xlsx' ]; }
```

`collect()` is unchanged on all three.

### `XlsxRenderer::resolveSheets()`

Adds a recognition path for the assoc shape returned by the CSV exporters, before falling through to the numeric-indexed shape and the existing multi-sheet shape:

```php
if ( is_array( $payload ) && isset( $payload['headers'], $payload['rows'] ) ) {
    return [
        'Data' => [
            array_values( (array) $payload['headers'] ),
            array_values( (array) $payload['rows'] ),
        ],
    ];
}
```

So the same exporter output reaches both renderers without per-exporter branching.

### `FrontendExportsView`

- `cards()` shape: `'format' => 'CSV'` → `'formats' => [ 'csv', 'xlsx' ]`. Every card was migrated, including the single-format ones (now `'formats' => [ 'ics' ]` / `[ 'json' ]` / `[ 'zip' ]` / `[ 'xlsx' ]`).
- New `formatLabel($slug)` helper produces the display string (CSV / XLSX / iCal / JSON / ZIP / PDF).
- `renderCard()` branches on `count($formats) > 1`:
    - **Multi-format**: render a chip-group with one `<label class="tt-export-card__format-chip">` per format, each containing a hidden `<input type="radio" name="format" value="…">`. First slug is `checked` by default. The chip-group sits inside the card's field stack with a "Format" label above it, matching the other field rows.
    - **Single-format**: the static top-right badge stays; the form emits `<input type="hidden" name="format" value="…">` so the server sees the slug.
- JS reads `body.format` (from the form's FormData) for the fallback filename extension, so a CSV→XLSX toggle changes the downloaded extension when the server's `Content-Disposition` header is missing.

### CSS — `assets/css/frontend-exports.css`

New chip styles. Pills are 32px tall, brand-green when selected:

```css
.tt-export-card__format-chip {
    display: inline-flex;
    padding: 6px 12px;
    border-radius: 999px;
    background: #f3f4f5;
    cursor: pointer;
    min-height: 32px;
    touch-action: manipulation;
}
.tt-export-card__format-chip:has(input:checked) {
    background: #0b3d2e;
    color: #fff;
}
.tt-export-card__format-chip:has(input:focus-visible) {
    outline: 2px solid #0b3d2e;
    outline-offset: 2px;
}
```

The hidden radio uses the standard clip-rect visually-hidden pattern so keyboard users can still Tab + arrow-key through the choices and `:focus-visible` highlights the active chip.

## Out of scope

- Other CSV exporters not in the three listed above (no pilot ask for those).
- Demo-data XLSX export — already XLSX, no CSV value-add.
- Renaming exporter class files — purely cosmetic, no consumer-facing change.

## Verification

- Players list card shows two chips (CSV selected). Click XLSX → chip flips green → Export → file downloads as `.xlsx` and opens in Excel with proper column types.
- Attendance register: same toggle, full row set roundtrips into XLSX preserving the date column.
- Goals list: same toggle, due_date column lands as a string (preserved; no Excel re-interpretation).
- Single-format cards (Evaluations XLSX, Team iCal, Federation JSON, Backup ZIP, Demo-data XLSX) still show their static badge top-right; their form posts the right `format` via the hidden input.
- Keyboard test: Tab into chip-group → arrow keys flip selection → Enter on Export submits.

## Closes

- #864 — Exports page — offer both CSV and XLSX as format option for bulk data exports
