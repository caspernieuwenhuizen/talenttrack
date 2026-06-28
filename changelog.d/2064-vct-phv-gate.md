# Player profile: hide the PHV panel when the VCT module is off (#2064)

The player profile's PHV control (the "Speler heeft een PHV-vlag" checkbox +
reason dropdown) is VCT functionality, but it rendered even when the VCT
module was switched off. The PHV hero pill, the Profile-tab panel, and the
PHV form POST handler now all gate on the VCT module being enabled, so a club
that doesn't use VCT no longer sees misleading conditioning controls on a
player's record. Behaviour is unchanged when VCT is on.
