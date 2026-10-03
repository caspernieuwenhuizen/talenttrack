# The dashboard alert summary and the bell show the same number; role names on the people list in Dutch (#4222)

Bump: patch

On a phone, the dashboard's alert summary and the bell above it could show two different numbers for the same alerts, such as "23+" next to "50". Both now count the same alerts and stop at the same ceiling, so past 50 alerts both read "50+". The summary reads like "50+ open alerts · 3 urgent". The bell no longer shows a plain "50" when there are more. On the people list, the functional role under a person's name (for example "Assistant Coach @ U23") now shows in the site language.
