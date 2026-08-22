<!-- audience: dev, admin -->

# UI copy — buttons and labels

Words are part of the interface, not decoration on top of it. This page is the convention every button label in TalentTrack follows. It exists because there wasn't one: 392 distinct button labels had drifted into four create-verbs, three case styles and 54 spellings of *Save* before #2614 pulled them back together.

Read this before adding a button, renaming one, or reviewing a PR that does.

## 1. Sentence case, always

Capitalise the first word and proper nouns. Nothing else.

```
Add goal            not  Add Goal
Save order          not  Save Order
Open custom CSS     not  Open Custom CSS
```

Proper nouns and initialisms keep their capitals: `Connect Strava`, `Export Excel`, `Download JSON`, `Share` (WhatsApp), `Open custom CSS`.

**Never set the casing in CSS.** `text-transform: uppercase` on a button class means the label a translator writes is not the label a user reads, and the rule reaches some tags and not others — that was #2615, where `<a class="tt-btn">` rendered `CANCEL` beside a `<button>` reading `Save` in the same row. Casing belongs in the label.

## 2. Verb + object, and drop the object when the context supplies it

A button inside a section headed *Rating scale* does not need to say `Save rating scale`. It says **Save**.

```
Save                 in a section already titled "Rating scale"
Save conversation    on a screen that also has "Save verdict"
```

Qualify only when two actions of the same verb share one screen with nothing separating them. If a screen has two bare `Save` buttons and no headings, the fix is to add the headings, not to lengthen the labels.

The same test applies to `Open`, `Print`, `Export` and `Download`: name the object only when the screen doesn't.

## 3. Two words, about 18 characters

Longer than that needs a reason, written down at the callsite:

```php
// long label: the two effects are genuinely separate and the second is not obvious.
__( 'Record decision and generate letter', 'talenttrack' )
```

A label over 18 characters wraps on a 360px page-header action row, which is where most of them live.

## 4. One verb per action

The canonical set:

| Verb | Use for |
| --- | --- |
| `Add` | Creating any record — a goal, a season, a test, an assignment |
| `Save` | Committing an edit |
| `Cancel` | Leaving a form without saving |
| `Edit` | Opening a record for change |
| `Archive` | Soft-delete, the safe default |
| `Delete` | Irreversible removal only |
| `Open` | Navigating to another surface |
| `Print` | Opening the print dialog, whatever the user then does with it |
| `Export` / `Download` | Producing a file |

**Retired:** `New`, `+ New`, `+ Add`, `Remove`, `Store`. `Create` is reserved for account and authentication CTAs — `Create account` is right, `Create goal` is not.

## 5. No glyphs, no trailing punctuation

```
Add player      not  + Add player
Save filters    not  Save current filters…
Print           not  Print / Save as PDF
```

The `+` duplicates an affordance the component already provides: `FrontendViewBase::pageActionsHtml()` renders its own icon slot, and on a phone the label collapses to that icon — so a literal `+` in the text is invisible exactly where it was supposed to help.

An ` / ` in a label almost always means two names for one action; pick one. A trailing `…` conventionally means "this opens a dialog", which is not a distinction this product makes consistently enough to be worth the character.

## 6. Write the label in the language of the person pressing it

Name things by what the user recognises, not how the system is built. A coach records an **injury**, not a `player_injuries` row; they set a **role**, not a `functional_role_id`.

And the label must survive translation. Never build one by concatenating fragments — pass a full sentence through `__()` with `sprintf` placeholders, so a translator sees the whole string and can reorder it.

## 7. Source strings are English

The msgid is English even when the academy is Dutch. A Dutch source string cannot be translated into any other locale — the "original" is already localised — and it renders as Dutch on an English install. If you catch yourself typing `__( 'Opslaan' )`, the msgid is `'Save'` and the Dutch belongs in `languages/talenttrack-nl_NL.po`.

## On the CI gate

#2619 considered a diff-only lint for these rules, in the shape of the inline-style containment gate (#1389). It was **not shipped**, and the reason is worth recording: reliably identifying which `__()` calls are button labels needs either a regex over rendered markup — the #2614 audit script did this and carried about 10% noise from `sprintf`-built markup — or routing every label through a `ButtonLabel::of()` helper, which is a 122-file refactor.

A gate that cries wolf 10% of the time teaches people to ignore it. This page plus review is the enforcement for now. If label drift comes back, the helper-based approach is the one to build, because it makes the gate exact rather than approximate.

## See also

- [Internationalisation architecture](i18n-architecture.md) — where labels live and how they reach the front end.
- [Mobile patterns](mobile-patterns.md) — touch targets and the 360px action row these limits are set against.
