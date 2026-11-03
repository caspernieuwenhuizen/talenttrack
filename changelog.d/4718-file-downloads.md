# Read-only observer: no file downloads; downloads and prints follow the export rule (#4718)

Bump: minor

The read-only observer no longer receives files the server produces: exports, the CSV and PDF downloads on reports and the explorer, and scheduled reports by email. The download buttons and the Exports tile are hidden from it, and the routes behind them answer 403. Printing a page it can see, with the browser's print, still works. Receiving a file is a new matrix right, File downloads (`exports`). Every other role holds it, so its downloads are unchanged, and existing installs get the rows through a migration. Downloads and prints follow the export rule and the record's own read rule, and scheduled files follow the export rule for every account.
