<?php
/**
 * Write routes that predate the args rule (#3689). Read by
 * `tools/check-rest-args.php`.
 *
 * Each line is `file | route path | methods`. The gate fails when a line
 * here no longer violates: when you give one of these routes its `args`,
 * delete its line in the same PR so the list only gets shorter. Never add a
 * line to make a new route pass; declare the route's args instead.
 */

return [
    'src/Infrastructure/REST/BackupRestController.php | /backups/(?P<id>[A-Za-z0-9._-]+)/restore | POST',
    'src/Infrastructure/REST/BackupRestController.php | /backups/migration/commit | POST',
    'src/Infrastructure/REST/BackupRestController.php | /backups/migration/dry-run | POST',
    'src/Infrastructure/REST/BackupRestController.php | /backups/migration/preview | POST',
    'src/Infrastructure/REST/BackupRestController.php | /backups/run | POST',
    'src/Infrastructure/REST/BackupRestController.php | /backups/settings | POST',
    'src/Infrastructure/REST/ExercisesRestController.php | /exercises | POST',
    'src/Infrastructure/REST/ExercisesRestController.php | /exercises/(?P<id>\\d+) | PUT',
    'src/Infrastructure/REST/ExercisesRestController.php | /exercises/(?P<id>\\d+)/promote | POST',
    'src/Infrastructure/REST/ExercisesRestController.php | /exercises/import | POST',
    'src/Infrastructure/REST/ExercisesRestController.php | /exercises/principles/bulk | POST',
    'src/Infrastructure/REST/OnboardingRestController.php | \'/onboarding/\' . $path | POST',
    'src/Infrastructure/REST/SpondRestController.php | /spond/base-url | POST',
    'src/Infrastructure/REST/SpondRestController.php | /spond/credentials | POST',
    'src/Infrastructure/REST/SpondRestController.php | /spond/test | POST',
    'src/Infrastructure/REST/SpondRestController.php | /teams/(?P<id>\\d+)/spond/credentials | POST',
    'src/Infrastructure/REST/SpondRestController.php | /teams/(?P<id>\\d+)/spond/preview | POST',
    'src/Infrastructure/REST/SpondRestController.php | /teams/(?P<id>\\d+)/spond/sync | POST',
    'src/Infrastructure/REST/SpondRestController.php | /teams/(?P<id>\\d+)/spond/test | POST',
    'src/Infrastructure/REST/StravaRestController.php | /players/(?P<id>\\d+)/strava/connect | POST',
    'src/Infrastructure/REST/StravaRestController.php | /strava/app | POST',
    'src/Infrastructure/REST/StravaRestController.php | /strava/webhook | POST',
    'src/Infrastructure/REST/StravaRestController.php | /strava/webhook/subscription | POST',
];
