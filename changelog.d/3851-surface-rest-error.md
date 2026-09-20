# The match screen says why a write was refused (#3851)

Every write failure on the match execution screen was reported as
"Opslaan mislukt: HTTP 400". The server had already said something far more
useful — "The player coming off is not currently on the pitch", translated
and specific — and the screen threw the body away before anyone could read
it, so a refusal a coach could have fixed by swapping two dropdowns read as
a broken app.

The refusal is now read off the response and shown: the server's sentence
where there is one, the status code only when the body held nothing
usable, and the same queued-offline behaviour as before for a request that
never arrived. A refused late goal or substitution shows it as a toast
above the form, which stays filled in behind it, rather than a system
dialog that has to be dismissed before the fields can be changed.
