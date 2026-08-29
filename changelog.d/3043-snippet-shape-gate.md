# A malformed release note fails its own PR, not the release (#3043)

Bump: patch

The check that every code change ships with a release note only confirmed a
note existed — it never read one. A note written without its title line became
a changelog entry titled "Bump: minor" and quietly shipped as a patch release
instead of a minor one; seven of the nine notes in one batch were wrong that
way, and it only came to light while cutting the release.

The check now reads each note and fails the pull request that introduced a
broken one, and the release script refuses to run rather than guessing at a
title. Three notes already waiting to ship were malformed and have been fixed,
so the next release names them properly.
