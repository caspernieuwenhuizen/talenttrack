# Measurements: Record-measurements roster and profile cards readable in dark mode (#2142)

Bump: patch

The Record measurements page and the player-profile Measurements cards rendered
dark text on a dark background when the operating system or browser was in dark
mode — the stylesheet darkened the card backgrounds without lightening the text,
while no other dashboard surface offers a dark variant. Removed those two
half-implemented dark-mode overrides so the measurement surfaces stay light and
legible in both modes.
