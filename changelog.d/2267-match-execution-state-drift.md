# Match execution: timer no longer keeps running after the match ends (#2267)

Bump: patch

The live-match screen now understands the real post-match states
(pending review and finalized) instead of a legacy value the server never
sends. On a finished match the clock stops, the state pill and the sticky
bottom action read correctly, and a reload stays in step with the server.
