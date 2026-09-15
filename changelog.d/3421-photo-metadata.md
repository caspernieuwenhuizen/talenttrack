# Migrated player photos record their real size and fingerprint (#3421)

The v4.120.0 upgrade moves player photographs into the private store. It
stored the file correctly and served it correctly, but recorded the wrong
details about it: every migrated photo was filed as zero bytes, with no
dimensions and no fingerprint.

The fingerprint is what TalentTrack uses to notice it already has a copy of
an image, so migrated photos could not be matched against anything and the
same face uploaded again would be stored twice. The size feeds the storage
figures the retention settings report against, so migrated photos counted
as taking up no space.

Upgrading repairs the existing rows from the stored files. Nothing was lost
and no photo needs re-uploading — only the record describing each one was
wrong, and it is recalculated from the picture itself.
