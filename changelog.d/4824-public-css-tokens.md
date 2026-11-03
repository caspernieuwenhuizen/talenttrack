# Custom CSS colours for text and borders now reach the whole dashboard (#4824)

The dashboard's main stylesheet reset the text, muted-text and border colours inside the dashboard to their built-in values. A text, muted-text or border colour set in custom CSS, including the visual editor, therefore never applied there. That reset is gone: the design colours now have a single source, and a custom value reaches every screen in the dashboard. On an install without custom CSS nothing changes.
