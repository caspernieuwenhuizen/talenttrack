# A stuck prospect can be put forward for a test training (#3710)

The invite task is the only thing that links a prospect to a test training, and
it was only ever created when a prospect was logged through the wizard. So a
prospect whose chain was cancelled, who was logged while the pipeline workflow
was off, who was imported, or who was seeded by the demo generator sat in the
first column with nothing to click. Their card now offers a way forward:
**Propose test training** asks the head of development to arrange one, and for
somebody who may issue the invitation themselves the button goes straight to the
New test training form instead. Proposing twice, or two scouts proposing the
same prospect, produces one request — the head of development is asked about a
child once. `POST /prospects/{id}/test-training-proposal` does the same over
REST.
