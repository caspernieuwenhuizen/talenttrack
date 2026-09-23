---
title: Bulk actions (archive & delete)
group: configuration
summary: Selecting many rows at once. Archive vs. permanent delete.
audience: [user, admin]
order: 40
---

# Bulk actions

Most list pages support bulk actions — handy when you want to archive or delete many rows at once.

## How it works

1. Tick the checkbox on each row you want, or use the header checkbox to select all rows on the page.
2. Pick an action from the dropdown above the table.
3. Click **Apply**.
4. A confirmation page tells you what will happen. Confirm or cancel.

## Only your own rows

A bulk action acts on the records you could act on one at a time, never more. The selection is checked row by row when you apply it, so a batch that mixes twelve of your own players with one from another squad archives your twelve and leaves the other alone. The confirmation afterwards counts what actually changed, so the number never overstates.

If none of the selected rows were yours to change, nothing happens and the page says so rather than reporting "0 items archived", which reads like a fault in the list.

## Archive vs delete

### Archive

- Hides the row from the active list.
- Keeps every connection — evaluations still attach to the player, reports still include the historical data, totals still work.
- Reversible — archived rows can be restored from the **Archived** tab.
- This is the recommended choice in almost every case.

### Permanent delete

- Removes the row.
- Blocked when the row has connected data (you can't permanently delete a player who has evaluations, for example).
- Cannot be undone.

## Best practice

Archive first. Only permanently delete when you're sure (cleanup of test data, or a privacy request).

## Finding archived rows

Each list page has an **Archived** tab. The default **Active** tab hides archived rows.
