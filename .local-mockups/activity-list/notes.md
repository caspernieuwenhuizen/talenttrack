# Activity list — design notes

## Target

The activities manage page (`?tt_view=activities`). Used by coaches to see what's coming up for their teams and to drill into a specific activity. Default sort is by date ascending. Currently a flat list/table; this redesign adds filters + date-bucket grouping + collapsed past.

## Three combined moves

1. **Filter row** — Team (already exists) + Type (new) + optional Status. Type is the missing piece the pilot asked about; the lookup-backed `activity_type_key` is wired through `tt_lookups` already so the picker is mechanical.
2. **Date-bucket grouping** — group rows by `Today / This week / Next week / This month / Later / Past`. Empty buckets render nothing — the headers don't appear unless they contain rows.
3. **Past collapsed** — the Past bucket renders as a single header line `23 past activities — [Show]`. Tap to expand. URL state (`?include_past=1`) preserves the choice.

## Friction the redesign addresses

| # | Friction in v4.3.x baseline | Redesign response |
|---|---|---|
| 1 | Flat list scrolls past months of training — coach scans dates to find "what's tomorrow" | Date buckets make temporal context instant. |
| 2 | No type filter — coaches looking for the next match scroll past trainings | Type picker in the filter row alongside Team. |
| 3 | Cancelled / completed past activities create scroll noise | Past bucket collapsed by default with hidden-count surfaced. |
| 4 | Past PLANNED (never marked completed/cancelled) is a TODO signal that gets buried | These are intentionally NOT in the Past bucket — they appear as their own "Needs attention" pseudo-bucket above Today. |

## States the mockup picker toggles

- **Default** — past collapsed, type filter "All".
- **Past expanded** — past activities shown.
- **Type: Match** — only matches; trainings hidden.
- **Empty buckets** — demonstrates what happens when "This week" has no activities (the header doesn't render).

## Bucket math

| Bucket | Range |
|---|---|
| Needs attention | `session_date < today` AND `plan_state = 'planned'` |
| Today | `session_date = today` |
| This week | `today < session_date <= end-of-week` (Sun) |
| Next week | `next-week Mon <= session_date <= next-week Sun` |
| This month | `next-next-week Mon <= session_date <= end-of-month` |
| Later | `session_date > end-of-month` |
| Past | `session_date < today` AND `plan_state IN ('completed', 'cancelled')` |

If today is e.g. Wednesday, "This week" = Thu-Sun.

## Open questions for the design pass

- Is the "Needs attention" bucket above Today (the unclosed past planned activities) useful, or does it just add noise? Mockup includes it; pilot to confirm.
- Should the filter row sticky-stick to the top on scroll, or scroll away with the page? Mockup does NOT stick (cleaner).
- Three filters or two? (Status filter is included but commented as "optional" — pilot to decide if status adds enough value over the buckets that already encode it.)
- On the mobile frame, the type filter dropdown could be a horizontal scrolling chip-strip instead of a `<select>`. Which feels better? Mockup uses `<select>` since the activity_type lookup has only ~5 values.
