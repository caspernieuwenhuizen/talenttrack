# Demo data actions no longer dead-end on a permission error (#3026)

Generating, wiping or switching demo mode redirected to `tools.php?page=tt-demo-data`,
left over from when the page lived under Tools. It is registered under the
TalentTrack menu, so WordPress answered that URL with "Sorry, you are not
allowed to access this page" and every demo action ended there. The install
wizard's "Try with sample data" button and the demo-mode admin-bar badge hit the
same dead end. All of them now land back on the demo page with their notice
intact.
