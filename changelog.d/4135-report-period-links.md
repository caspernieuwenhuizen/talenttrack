# Report period pills and saved views keep the section options (#4135)

The monthly report's section options (which tests, readings and trend, which
match parts) were lost when a coach switched the period with the pills above
the report, reset the range, or opened a saved view: those links carried the
options as raw JSON, and printing the link stripped its braces and quotes.
Their values are now percent-encoded, the same fix the PDF and schedule links
received.
