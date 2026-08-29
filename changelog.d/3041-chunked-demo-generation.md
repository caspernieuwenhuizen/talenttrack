# The large demo preset finishes on hosted installs (#3041)

Bump: minor

Generating the large demo set used to die with an Apache **Proxy Error** and
leave the dataset half-written, with nothing on screen to say how far it got.
The generation ran inside a single request, and while the plugin raised PHP's
own time limit, the proxy in front of a hosted install gave up long before PHP
did — which no setting inside the request can change.

A run is now a list of steps, and each step is its own short request. The
overlay names the one it is on — *Step 7 of 24 — Evaluations* — instead of
spinning indeterminately, and no single request has to outlive the gateway.

An interrupted run is now visible rather than silent. Close the tab
mid-generation and the page tells you next time: how many steps finished, which
batch, and a choice of **Resume this run** or **Discard it**. The rows already
written stay tagged either way, so a wipe still reaches them. A second run
cannot start while one is unfinished.

The dataset is unchanged in shape and still reproducible from a seed: the same
seed and preset produce the same academy, whether it was generated in one
request or thirty. With JavaScript switched off the run happens in one request
exactly as before.
