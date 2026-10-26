# Control room generator

The owner's status page for the delivery team (`docs/team/README.md`, "How
the owner steers"). One page, generated, never hand-edited.

```
node tools/team/control-room/gen.mjs
```

writes `control-room.html` next to the script from:

- GitHub (`gh`): open issues and their labels, open PRs with their check-runs,
  the last releases;
- the three installs: the local checkout's `talenttrack.php` version, and the
  public `readme.txt` of `sto.mediamaniacs.nl` and `jg4it.mediamaniacs.nl`;
- `main`'s `changelog.d/` (unreleased snippets), `tests/e2e/mobile-baseline.json`
  and `phpstan-baseline.neon` (gate health);
- `team.json`, the only hand-kept part: the headline, one status line per
  seat, and the decision inbox (`D-n` with `state` open / answered, the
  recommendation and what happens if nobody answers).

The lead runs it and publishes `control-room.html` as the artifact the owner
already has (keep the same URL: publish with that `url`). `team.json` is
edited by the lead only; a decision is `answered` when the owner has said so in
a session, and its answer is also recorded on the issue it belongs to.

The script needs `gh` logged in and `node`; it reads the main checkout at the
path in `MAIN` near the top of the file.
