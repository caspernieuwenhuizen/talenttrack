# Module cards stop stretching, and long ones fold away (#2890)

Bump: patch

On the Modules and features page, every card in a row was stretched to match the
tallest one. Because sub-feature counts are so uneven — Reports has twenty-one,
most modules have none — a row could show one full card beside two that were
mostly empty space, with the *Includes* line stranded at the bottom.

Cards now take their own height, and a module with more than four sub-features
shows a count you can expand rather than listing all of them. Reports no longer
occupies most of a screen while the modules beside it go unread.

Expanding works with the keyboard and needs no mouse, and the panel starts closed
at every screen size — opening it by default on a phone would put the same wall
back where it hurts most.
