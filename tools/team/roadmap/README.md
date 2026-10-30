# Roadmap page generator

The owner's visual roadmap, linked from the control room. One page,
generated, never hand-edited.

```
node tools/team/roadmap/gen.mjs
```

writes `roadmap.html` next to the script from:

- `docs/roadmap.md`, the only source: every section and table row becomes a
  part of the page (Direction, Now, Next, Later, Decided this cycle, Shipped
  recently, How the queue reads);
- GitHub (`gh`): the state of every issue a row links. A row that links
  several issues counts those; a row that links one epic counts the issues
  that reference it; a row that names the `from-hq` label counts that label
  since the start of the current HQ round (`ROUND_SINCE` near the top).

The product owner runs it after changing `docs/roadmap.md` and publishes
`roadmap.html` to the same artifact URL each time:
https://claude.ai/artifact/Rf5wtJuk8yXkWMJknaXRLc. The control room links
there. `roadmap.html` itself is not committed.

The script needs `gh` logged in and `node`.
