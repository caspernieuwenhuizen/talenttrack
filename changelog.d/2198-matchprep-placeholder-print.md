# Match-prep PDF: empty fields print blank, not placeholders (#2198)

Bump: patch

The match-prep PDF no longer prints empty-field placeholder text. In the
image-capture export, empty goal / attention inputs and unassigned
set-piece roles ("Goal 1…", the "…" hints, "— Pick player —") now render
blank; on-screen editing keeps its placeholders. The standalone print /
DomPDF sheet likewise drops the "—" dash for an empty attention note or an
unassigned role. CSS + printable-renderer only.
