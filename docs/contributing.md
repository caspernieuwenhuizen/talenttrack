<!-- audience: dev -->

# Contributing to TalentTrack docs

The two rules every doc PR has to pass.

## 1. Audience marker

Every file under `docs/` (English + Dutch) starts with an HTML-comment marker declaring its target audience:

```markdown
<!-- audience: user -->
<!-- audience: admin -->
<!-- audience: dev -->
<!-- audience: user, admin -->
```

Allowed values: `user`, `admin`, `dev`. Comma-separated for cross-cutting topics.

The in-product `Help & Docs` page filters its sidebar TOC by the viewer's role:

| Role / capability                                          | Audiences shown |
| ---                                                        | ---             |
| `tt_player`, `tt_readonly_observer`, `tt_staff`, `tt_coach` | `user`          |
| `tt_head_dev` (or `tt_edit_settings`)                      | `user` + `admin` |
| WP `administrator`                                         | `user` + `admin` + `dev` |

A doc shows up if any of its declared audiences overlap with the viewer's allowed set.

Direct URL access is not gated — anyone with access to the docs page can read any doc by slug. The audience filter is a UX convenience, not access control.

CI rejects PRs that add a new doc without a marker.

## 2. Translations

The translation discipline is per audience:

- `audience: user` or `audience: admin` (or includes either) → translation in `docs/nl_NL/<slug>.md` is **required in the same PR**. Use the same audience marker on the Dutch counterpart.
- `audience: dev` (only) → no Dutch translation. Dev docs are English-only by design — that's the working language for plugin extenders regardless of locale.

If a doc's audience changes from `dev` to anything else, add the Dutch translation in that PR. If it changes the other way, remove the Dutch counterpart in the same PR.

## Layout conventions

- One H1 per file, matching the slug's title in `HelpTopics::all()`.
- H2 for major sections, H3 for sub-sections. Avoid going below H3.
- Tables for structured data; bullets for lists; paragraphs for prose. No nested lists deeper than two levels — re-think the structure if you need three.
- Code samples in fenced blocks with a language tag (`php`, `json`, `bash`, …).
- Inline `<code>` for slugs, capability names, table names, function names.

## Cross-references

Link to other docs with a relative path:

```markdown
See [REST API reference](rest-api.md) for the contract.
```

Don't hard-code `/wp-admin/admin.php?page=tt-docs&topic=…` URLs unless you specifically need the in-product link — the relative-file form works in both the product viewer and on GitHub.

## Slugs

Slugs go in `HelpTopics::all()`. The pattern is kebab-case, matching the filename without `.md`. Add a `summary` line that fits in a tooltip — that's also what the search index uses. New slugs need a row in the layered TOC at [`docs/index.md`](index.md).

## When you add a feature

The release-discipline commitment from v2.22.0+ : every PR that ships user-facing change updates the relevant doc(s) in the same PR. The doc is the *current* state of the feature; `CHANGES.md` is the per-release diff, not a substitute.
