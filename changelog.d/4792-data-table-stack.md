# Dashboard table widgets stack their rows on a phone (#4792)

On a phone the dashboard's table widgets were wider than the screen. In Dutch,
the Recent audit events widget needed a sideways scroll inside the widget, and
that scroll hid the "What" column, the one an admin opens it for. Below 480px
every table widget now shows each row as a block, with the column name before
each value, so When, Who and What are all readable without scrolling. This
covers every preset: audit events, trials needing a decision, scout reports,
prospects, upcoming activities, goals by principle and behaviour pending. From
480px up the widgets are tables, as before.
