# Evaluation coverage stops reporting a clean sheet it never measured (#3802)

The evaluation-coverage report showed nought gaps for every player in the academy, while the last evaluation anywhere was seven weeks old. It was not miscounting: with no evaluation periods set up it had nothing to measure against, and reported nought because it had been asked nothing.

Three things change. A fresh install now starts with four evaluation rounds across its own season, so the report works out of the box instead of waiting to be discovered. Where no period is set, the report says so rather than showing an unearned green. And filtering by team now actually filters — it was accepted and quietly ignored, so asking about one team returned all of them.

Periods you have set up, including a list you deliberately emptied, are left exactly as they are.
