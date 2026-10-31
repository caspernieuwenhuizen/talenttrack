# Dashboard action cards follow the authorization matrix (#4348)

The dashboard action cards and the quick actions panel now decide visibility with the same check as the views they link to (`AuthorizationService::userCanOrMatrix()`). On an install where the matrix bridge is not supplying the raw capabilities, a head of development who holds the team create right in the matrix sees the "Add team" card again. Before, the card was hidden while "+ New team" still showed on the teams view. Nothing changes for administrators.
