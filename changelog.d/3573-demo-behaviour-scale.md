# Demo data: behaviour ratings use the academy's own rating scale (#3573)

The demo generator wrote behaviour ratings on a fixed 1–5 scale. On an
install with a 5–9 scale, every rated demo player therefore sat under the
behaviour floor of 7, was capped at amber, and could never show green. Behaviour
ratings are now drawn from the configured scale and snapped to its steps. Most
players sit at or above the midpoint and a minority fall below it, so the
squad shows a real mix of colours. Existing demo data keeps the old values
until the demo is wiped and regenerated.
