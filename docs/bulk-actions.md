<!-- audience: user, admin -->

# Bulk actions (archive & delete)

Most list pages in TalentTrack support bulk actions — useful when you need to archive or delete many rows at once.

## How it works

1. Check the top-left checkbox of each row you want to affect (or the header checkbox to select all on the page).
2. Pick an action from the bulk-actions dropdown at the top of the table.
3. Click **Apply**.
4. A confirmation page shows what will happen. Confirm or cancel.

## Archive vs permanent delete

### Archive

- Sets `archived_at` timestamp and `archived_by` user on the record
- Row disappears from active lists (the default view hides archived)
- **Preserves all relationships** — evaluations still reference the player, reports still include historical data, aggregate stats still work
- Reversible via the Archived tab's "Unarchive" action
- **This is the default, recommended action for most cases**

### Permanent delete

- Actually removes the row from the database
- **Blocked** if the record has dependent data (e.g. you can't delete a player who has evaluations)
- Dependent-data check prevents accidental orphaning of evaluations, goals, sessions, etc.
- **Irreversible**

## Best practice

Archive first. Only permanently delete when you're certain the data should be gone forever (e.g. GDPR request, test data cleanup).

## Filtering

The Archived tab on each list page shows archived records. The default Active tab hides them.
