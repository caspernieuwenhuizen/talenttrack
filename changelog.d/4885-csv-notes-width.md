# CSV import preview: the reason a row failed gets room to read (#4885)

In the preview of a players or exercises CSV import, the Notes column holds
the reason a row failed, often two or three sentences. It was meant to keep a
minimum width so the reason wraps inside it instead of squeezing the other
columns, but that width never applied. It does now. On a phone the preview is
unchanged.
