# RecordSpine gained in-page tabs, and they survive the classic layout (#2932)

Bump: minor

The shared record spine could only render tabs that navigate: each one was a
link, and following it reloaded the page. A surface whose sections are really
one record seen several ways had no compliant way to switch between them
without a round trip, and the only alternative was to hand-roll a tab strip —
which is exactly the drift the shared component exists to prevent.

A tab entry can now name a `panel` instead of a `url`. Those render as real
tab buttons wired to panels already on the page, with arrow-key navigation and
the correct assistive-technology roles, and switching between them costs no
request.

They also render under the classic navigation layout, where the rest of the
spine does not. The identity strip is navigation chrome and disappearing with
the shell is correct; a section switcher is the only way into the content
behind it, so a screen whose sections vanished would simply be missing half of
itself.
