# Usage statistics: season default, truncation labels, better empty states (#2355)

The Application KPIs dashboard now defaults to a season-aware window instead of a fixed 30 days, picking the smallest period that spans the running season so far. Truncated tables (Active users, Dormant users) carry a "(Showing top N)" label so it's clear the list is capped, not complete. A collapsible "How these numbers are measured" note explains that stickiness is always a 30-day MAU ratio, that visits end after 30 minutes idle, and that observed time online is a lower bound. Role labels now render as shared role chips, and the empty states for "Top features used" and "Dormant users" suggest a next action.

Bump: patch
