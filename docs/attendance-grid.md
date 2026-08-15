<!-- audience: user -->

# Attendance grid

The **attendance grid** is a fast way to record attendance for a whole period
in one screen — the desktop alternative to the step-by-step attendance wizard.
It works the way a coach's Excel register does: one row per player, one column
per activity, a status in every cell.

Open it from **Activities → Attendance grid**, or with the **Attendance grid**
button on any activity's own page (which opens the grid on that activity). You
need permission to edit activities. It's built for a desktop or laptop; on a
phone the guided wizard is the easier path.

## What you see

- **Rows are your players** — the active roster of the selected team. Every
  player is always a row, even for an activity nobody has been marked for yet.
- **Columns are activities** — training sessions and matches in the selected
  period, oldest on the left. The columns grow as the season goes on; the
  period filter decides how many you see.
- **Each cell is a status.** Pick one from the dropdown:
  - **Present**, **Late**, **Absent**, **Excused**, **Injured**.
  - The cell shows a short letter; the dropdown shows the full word.
- The **Present %** column on the right is a quick read of how often each
  player attended in the shown period.

## Recording attendance

1. Choose the **team**, the **period** (a quick pill or a custom date range),
   and optionally narrow to **training only** or **matches only**.
2. Set a status in each cell. Use **"all present"** at the top of a column to
   mark a whole session present in one click, then fix the exceptions.
3. Click **Save**. The counter shows how many changes are waiting; edited
   cells are outlined until you save. **Cancel** leaves without saving.

The grid records the same attendance the reports and the wizard use, so the
Attendance and Minutes reports stay in step with what you enter here.

## When the guided wizard is switched off

An academy that prefers spreadsheets can switch the guided attendance and
evaluation wizard off under **Settings → Wizards**. The grid then becomes the
main way attendance is entered, and the activity buttons follow:

- **Mark attendance** on an activity (and on its card in the activities list)
  opens this grid on that activity's own column, instead of starting the
  wizard. It's the same button that reads **Complete activity** when the
  wizard is on — renamed so it doesn't promise more than it does.
- **Mark attendance** on your dashboard opens the grid for the activity it
  names.
- **Mark completed** appears on a planned activity's page. Recording
  attendance in the grid does *not* complete the activity by itself — a single
  Save can cover weeks of sessions — so completing stays a deliberate click.
  Record attendance first, then mark the activity completed. You can reopen it
  later if you need to.

With the wizard switched on, none of this changes: completion runs through the
guided flow and flips the activity to completed at its final step.

## Turning it off

An administrator can hide the grid under **Settings → Features → Attendance
grid**. When it's off, the grid button disappears and the page can't be
opened. The attendance wizard is unaffected.

If both the grid and the wizard are off, an activity's attendance is edited on
the activity's own edit form once it has been completed.
