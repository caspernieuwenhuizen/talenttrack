# Coach activity report: club scope guard + name fallback (#2353)

Bump: patch

The Coach activity report now scopes its per-coach evaluation counts to the current club, so it can never surface a coach from another academy in a multi-tenant install. Coaches whose user account has been deleted are labelled **Unknown coach** instead of a raw account number, while still keeping their saved evaluations in the count.
