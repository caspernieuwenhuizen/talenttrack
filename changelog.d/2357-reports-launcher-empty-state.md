# Reports launcher: honest empty state when no tiles are available (#2357)

Bump: patch

When every report tile was filtered out — all reports switched off for the academy, or none within the viewer's scope — the Reports launcher rendered a blank grid with no explanation. It now shows a clear notice explaining that no reports are available and pointing the user to ask an administrator to enable a report or widen their scope. When any tile survives the filtering, output is unchanged.
