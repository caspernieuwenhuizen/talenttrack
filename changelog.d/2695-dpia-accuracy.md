# Photo-capture DPIA corrected against the code (#2695)

Bump: patch

`docs/photo-capture-dpia.md` is the document an academy signs before photographs
taken at a youth academy are sent to a vision model. An audit against the shipped
code found that several of its technical assertions described safeguards that do
not exist, so the document has been rewritten to describe what the code actually
does, and now carries a prominent **not ready for signature** banner listing what
must be settled first.

The correction that matters most: the feature does **not** route to an EU-resident
endpoint by default. The document previously said it did, and that breaking that
required a deliberate opt-out. In fact the default is Anthropic's direct API,
there is no AWS Bedrock code path at all, and an operator-supplied endpoint
override is not validated.

Corrected in the safer direction: the uploaded photograph is never written to
disk. The document described a seven-day retention and a cron sweep; neither
exists, because there is nothing stored to sweep.

Also corrected: the structured extraction is **not** currently included in the
GDPR subject-access export, contrary to what the document claimed.

No behaviour changed in this release — the feature remains off by default behind
the `exercises_vision_extraction` flag. If you have already signed a copy of this
DPIA, re-read it: the version you signed misdescribed where photographs go.
