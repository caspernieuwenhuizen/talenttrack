# An assistant coach assigned to a trial case can now open it (#3222)

Bump: patch

Assigning an assistant coach to a trial case gives them the right to write their
input on it. Until now they could not open the case to do it: the screen let you
in only if you could also read the other coaches' synthesis, which an assistant
coach cannot. The capability was real and no screen could reach it.

Opening a case now asks the right question — may this person read the synthesis,
**or** write an input — and both still require being assigned. Nothing is
widened: the **Execution** tab, which gathers what the other coaches have said,
still needs the synthesis permission, both in the tab strip and when the tab
itself loads.

The Trials documentation was also wrong about how this works. It said other
coaches see nothing "unless they are assigned to it", which reads as though
assigning somebody grants access. It does not — whether a role can reach trial
cases at all is set in the authorization matrix, and assignment narrows it from
there. Both languages now say so, because "just assign them" was the wrong first
thing to try.
