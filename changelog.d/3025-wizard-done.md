# The install wizard now shows its Done screen (#3025)

Finishing the dashboard-page step dropped straight to a bare "Setup is
complete" line. The summary of what was set up, the recommended next steps and
the link to the dashboard just created were written and translated but
unreachable on every install — the completion flag was set in the same request
that moved to the last step, and the page's completion check fired first.
Leaving the Done screen still gets you the short line on a later visit.

The step indicator also skipped the Import and Staff steps, so it highlighted
nothing while you were on either of them.
