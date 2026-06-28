# List filters get the mobile-first FilterBar chrome (#2082)

Bump: minor

Every list surface built on `FrontendListTable` (players, goals, teams, people,
evaluations, holidays, tournaments, prospects, functional roles, custom fields,
my activities, PDP, …) now renders its filter row through the shared, mobile-first
FilterBar: a single inline row on wide screens that collapses to a "Filters"
button and a bottom sheet on phones. Filters are the same as before — the team /
type / status selects, the search box, and the from/to date ranges all filter
exactly as they did, with the same URL parameters, sorting, pagination and
live-filtering — they just gain a touch-friendly layout on small screens.

The list table keeps owning rows, sorting, pagination and per-page; only the
filter chrome moved. FilterBar gained free-text/search and date-range group
types and an opt-in status-pill rendering for views that want one. No view
needed changes to inherit the new chrome.
