# Status colours as text are readable on every theme (#5108)

Warnings, errors, success messages and info labels printed in their status
colour were hard to read: the default warning orange measured 2.5:1 on white,
and several themes fell short too. Every such text now uses a darker shade of
the same colour that reads at 4.5:1 or better on white and on its pale banner,
on every theme. Banners, pills, badges and borders keep their colour exactly
as before. An academy that sets its own status colours under Branding gets
the same treatment: a colour too light to read as text is darkened just
enough where it is printed as words.
