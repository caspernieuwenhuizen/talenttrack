# Monthly report composition panel: sections left, options in a sidebar on desktop (#4062)

On a desktop screen the team monthly report's composition panel clipped the test
names and dates, wrapped the section cards to four or five lines and left the
printed-size meter on its own. From 1024px wide the sections now take the main
column, with the printed size and the buttons under them, and the Matches and
Tests options stack in a sidebar on the right. A test option reads its name and
date on one line, and a card's description stays under its title however tall
the row is. Phones and tablets keep their layout; the keyboard order is
unchanged.
