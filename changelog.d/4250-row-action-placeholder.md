# The prospects overview's "Edit contact" link opens the right prospect (#4250)

Every "Edit contact" link on the prospects overview opened a "Prospect not found" page, so a family's contact details could not be corrected from the list. The link now opens that prospect's edit form, and Cancel and the back pill return to the overview. The fix is in the shared list component, so any other list whose row links carry a back-link behaves the same way.
