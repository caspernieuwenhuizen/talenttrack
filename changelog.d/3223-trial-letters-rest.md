# Trial letters are reachable over the API (#3223)

Bump: patch

Generating and reading a trial letter was only possible from the trial-case
screen — the code behind it could not be reached any other way. That made the
letters the one part of the Trials module a reporting tool, a mobile app or any
integration could not touch.

`GET /trial-cases/{id}/letters` now lists what has been generated for a case,
and `POST` to the same route generates one. Both need the same permission as
the Letter tab itself, so nothing new is visible to anybody: a letter telling a
family whether the academy wants their child is not something an assigned coach
can produce.

The list gives the audience, when it was generated, who by, and which one is
currently the live letter. Generating a new letter supersedes the previous one,
the same as it always has on screen — a case has one letter that counts, plus
the record of what it replaced.
