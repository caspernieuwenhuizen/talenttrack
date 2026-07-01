# Trials list: filters moved to the shared FilterBar (#2174)

Bump: patch

The Trials list filter bar (Status, Track, Decision, Include archived) now
uses the shared FilterBar component: an inline single-line row on desktop
and a "Filters" button + bottom sheet on phones and tablets. Filtering
behaviour is unchanged — same parameters, same results. The bespoke
filter-form styling was removed in favour of the shared component's sheet.
