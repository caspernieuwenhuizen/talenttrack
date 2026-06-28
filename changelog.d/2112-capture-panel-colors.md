# Match prep PDF: white panels + consistent player boxes (#2112)

Bump: patch

The **Export as PDF (A4)** capture rendered the doen-per-speler and rollen
panels with a grey background and tinted the on-pitch player boxes
differently over the blue (1e) and orange (2e) halves — html2canvas can't
resolve the nested `--tt-mp-paper` custom property, so the panel fill
dropped out and the translucent pills blended with the pitch. The capture
now forces opaque white panels and player boxes (and drops the card
shadows that printed as grey halos); the on-screen view and the pitch
colours are unchanged.
