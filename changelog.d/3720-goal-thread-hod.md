# The Head of Development can read and join a goal's conversation (#3720)

Bump: patch

The goal thread decided academy-wide access on a settings capability that the Head of Development role is never granted, so they got an empty "Conversation" heading on the goal page and a refusal over the API — on every goal for a player they don't personally coach. Access now follows the authorization matrix's academy-wide read on goals, and writing in a thread additionally needs the goals edit right, so a role that only reads goals across the academy can follow a conversation without joining it. The "Conversation" heading no longer appears at all when the reader can't see the thread underneath it.
