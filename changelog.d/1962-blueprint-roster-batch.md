# Blueprint editor: faster load via batched roster query (#1962)

The team-blueprint editor's "+ Add → Other team" picker built its
cross-team roster with one player query per sibling team (an N+1). It now
fetches all sibling-team players in a single batched query and groups them
in PHP. The editor also read the formation-template table twice per page
(once for the toolbar dropdown, once for the JS payload); it now fetches
those rows once and reuses them. Output is unchanged — purely fewer
queries on load.
