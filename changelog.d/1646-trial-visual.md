# Trial case page 2026 card layout + Save/Cancel on trial config forms (#1646)

Bump: patch

The trial-case detail page now wraps each section in a token-styled 2026
card with cleaner headings, matching the teams and activity-detail surfaces;
the regenerate-letter form's inline margin moved into the enqueued sheet. The
trial-tracks editor and letter-template editor both gained a proper Cancel
button alongside Save (via the shared `FormSaveButton` helper, honouring any
`tt_back` hint), and the letter editor's monospace HTML textarea moved into a
CSS class. Visual and markup only — no data, query, or permission changes.
