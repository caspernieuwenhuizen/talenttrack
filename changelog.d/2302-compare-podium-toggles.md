# Player comparison and Podium are now switchable features (#2302)

Bump: patch

The **Player comparison** and **Podium** analytics tiles can now be turned
off per academy from the Modules & features page (`?tt_view=modules`), the
same way as the other analytics surfaces. Both ship **on**, so nothing
changes on upgrade; switching one off hides its dashboard tile and blocks a
direct link to its `?tt_view` route. Until now these two tiles were
hard-wired on and had no toggle.
