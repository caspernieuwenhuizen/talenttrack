# Plans are set by your operator, not bought in the plugin (#2923)

Bump: minor

TalentTrack no longer carries a marketplace integration or an in-app trial. Which plan an install is on is recorded when the install is provisioned; the install keeps a local copy so it keeps working normally if that record is briefly unreachable, and falls back to Free when there is none.

For operators this replaces the Freemius adapter and the 30-day trial → 14-day grace state machine with a single entitlement the plugin reads and never writes. The Account page drops the "Start trial" and trial-state panels and now says what the next plan adds, with plan changes going through the operator. Every feature gate, the free-tier caps and the REST enforcement behave exactly as before.

Installs running as non-commercial test instances — which is all of them today, with `TT_COMMERCIAL_MODE` false — are unaffected: every feature stays unlocked and no caps apply.
