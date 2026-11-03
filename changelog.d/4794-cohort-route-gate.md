# Cohort transitions API follows the cohort_transitions read right (#4794)

`GET /journey/cohort-transitions` is readable by whoever may read the `cohort_transitions` entity academy-wide, the same rule the Cohort transitions screen applies, and only while the feature is switched on. The settings view right plays no part, so the screen and its API answer the same question for every persona.
