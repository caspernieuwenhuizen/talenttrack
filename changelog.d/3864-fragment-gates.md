# The i18n pull-request gate reads the fragment, and the catalogue is no longer union-merged (#3864)

The translation gate now asks one question: does every new translatable
string on this pull request's added lines have an entry, with a Dutch
translation, in the request's own fragment? It names the strings that
don't, with the file and line that introduced them, and it runs in a
second with nothing installed but PHP, so the same command that decides
the request can be run before opening it. It replaces a check that
regenerated the string template on both sides and compared untranslated
counts — accurate, but it reported "delta 3 (baseline 9, head 12)"
alongside "new untranslated msgids: 0", and reading the two together as
"three strings, none translated" took real effort. The override label
and the hardcoded-English check are untouched.

The duplicate-msgid check covers the fragments too, so a bad one fails in
the request that wrote it rather than blocking a release. And
`languages/*.po` loses its union merge setting: a catalogue is not
append-only, so union quietly kept both copies of a relocated entry and
produced duplicates that stop every locale compiling — and it never
delivered the conflict reduction it was added for, since the setting
applies on a developer's machine but not to the merge that gates a pull
request. Nothing about how the plugin behaves changes.
