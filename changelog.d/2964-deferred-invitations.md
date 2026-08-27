# Add people now, send their credentials when you are ready (#2964)

Bump: minor

Creating an invitation used to send it in the same moment, which made
setting up a club awkward: adding your coaches meant emailing them
immediately, before you had looked around yourself.

An invitation can now be created and held. Nobody receives anything until
you explicitly send it. Held invitations show in the list as "not sent
yet", with a count and a **Send all invitations** action, or **Send now**
on a single row.

Sending is safe to repeat — an invitation that already went out is skipped
rather than delivered twice — and a bulk send reports how many went and
how many were left alone, rather than one overall result.

Invitations created before this change are unaffected and continue to send
on creation.
