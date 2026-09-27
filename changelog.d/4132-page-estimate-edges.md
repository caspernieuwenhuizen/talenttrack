# Monthly report page estimate matches the PDF at the edges (#4132)

The printed-size meter now lays the report out the way the PDF does rather
than dividing its height by the page: a table row, a stat strip or the
landscape footer moves whole to the next sheet when it does not fit, and the
player table repeats its header on the sheets it runs onto. A landscape matrix
that runs past one page and a one-pager that is just full now show the page
count the PDF prints. The one-pager keeps a small margin in hand, so a close
call reads as "does not fit" rather than printing a second page, and the
section heights were re-measured on the PDF.
