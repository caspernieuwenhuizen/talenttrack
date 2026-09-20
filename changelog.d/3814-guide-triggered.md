# Messages settings: three templates no longer claim they are never sent (#3814)

The Messages settings page and the setup wizard labelled the cancelled-training,
plan-ready and methodology-delivered messages "Not sent automatically yet", while
listeners had been firing all three for several releases. That is the wrong way
round to be wrong: a coach cancelling a training reads the label and concludes the
squad still has to be rung round by hand, or that nothing went out when it did.
The three rows now read as triggered, and a test pins the claim to the listeners
`CommsModule` actually registers, so the page and the code cannot drift apart
again unnoticed.
