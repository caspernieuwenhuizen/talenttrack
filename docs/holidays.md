<!-- audience: user -->

# Holidays

Academy-wide holiday periods — Christmas break, half-term, summer shutdown — recorded once and shown on **every** team planner, so coaches plan around them.

## Adding a holiday

Open **Holidays** (Administration group on the dashboard) and click **+ New holiday**. The wizard asks for:

- **Name** — e.g. "Christmas break".
- **Start date** and **End date** — the holiday can span multiple days; both ends are inclusive.
- **Note** (optional) — anything coaches should know.

Holidays are **one-off** — there's no recurring-holiday engine yet, so add each season's breaks individually.

## Where they show

Each holiday renders as a coloured banner on the affected day(s) of every team planner, so it's obvious at a glance which days the academy is closed. The banner uses the holiday's colour (if set) and shows its note on hover.

When you try to schedule an activity on a holiday day from the planner, a **soft confirmation** appears ("This day is an academy holiday … schedule anyway?"). It never blocks — confirm to go ahead, cancel to pick another day.

## Who can manage them

Managing holidays (create / delete) needs the **Manage holidays** permission — held by academy admins, managers and the Head of Development. Coaches can see holidays but don't manage them.

## Viewing a holiday

On the Holidays list, click any row to open the holiday's **detail page**. This read-only summary is available to everyone who can see holidays, and shows, at a glance:

- The **period** — start and end date in your locale's format (e.g. "21 dec 2026 – 4 jan 2027").
- The **duration** — the total number of days the holiday spans, counting both the start and end date.
- The **note**, or a dash when none was added.
- The holiday's **colour**, when one is set.

It also reminds you that the holiday shows as a banner across these days on every team planner. Managers see an **Edit** button on this page that opens the edit form; coaches and other viewers see the read-only summary only.

## Editing a holiday

On the holiday's detail page (or via the list row's **Edit** action), open the holiday's edit form. Change the name, the start or end date, or the note, then **Update holiday** to save. **Cancel** returns you without changing anything. Editing needs the **Manage holidays** permission.

## Removing a holiday

On the Holidays list, use the row's **Delete** action. Holidays are soft-archived (kept in the database, hidden from lists and planners), not hard-deleted.
