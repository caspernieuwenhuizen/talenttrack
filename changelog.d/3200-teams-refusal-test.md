# Fix a test that pinned the wrong refusal on the team edit page (#3200)

No behaviour change. Two scope fixes that shipped together — one refusing an
out-of-scope team id before anything loads, one refusing the edit form
without permission to edit teams — are both correct and both stay. A test
written alongside the second asserted the exact wording of its refusal, and
the first now answers before it, so the test failed on `main` even though the
page does the right thing.

The test now checks what it was always about: opening the edit URL without
the rights refuses in words, and neither the form nor the roster picker
renders.
