# Scene editor: correct Dutch for the line types (#2687)

The scene editor's line picker offered a Dutch coach **Geslaagd** for *Pass* and
**Uitvoeren** for *Run* — "passed" as in a test result, and "execute" as in
running a program. Both are now right: **Pass** and **Loopactie**.

`Pass` and `Run` are single English words, and the catalogue already held them
from unrelated parts of the product. Gettext returns whichever translation was
registered first, so the picker inherited a meaning from somewhere else entirely
with nothing to show for it — the English read fine and the catalogue looked
complete.

The whole diagram vocabulary — the six markers, the five line types and the four
pitch presets — is now translated under its own context, so none of these words
can pick up a sense from elsewhere, and a word added to the set later cannot
either.
