# local_lmslaunch

Custom Moodle local plugin for one-time browser launch from `lms-api`.

## Install

1. Copy `local/lmslaunch` into your Moodle codebase under `local/`.
2. Visit Moodle admin notifications to complete installation.
3. Configure:
   - `LMS API base URL`
   - `Shared secret`
   - `Connect timeout`

## Expected `lms-api` contract

- `GET /courses/{id}/launch` returns a URL pointing to:
  - `/local/lmslaunch/launch.php?token=...`
- `POST /moodle/launch/consume`
  - Header: `X-Lms-Launch-Secret`
  - JSON body: `{ "token": "..." }`
- `GET /moodle/courses/{courseId}/content`
  - Header: `X-Lms-Launch-Secret`
  - Returns the course content tree from CarbonEdge for rendering inside the Moodle plugin page.
- `POST /moodle/courses/{courseId}/items/{itemId}/progress/start`
- `POST /moodle/courses/{courseId}/items/{itemId}/progress/complete`
  - Header: `X-Lms-Launch-Secret`
  - JSON body: `{ "moodleUserId": 123 }`
  - Records the learner's start and completion timestamps for an item.
- `POST /moodle/courses/{courseId}/items/{itemId}/progress/video`
  - Header: `X-Lms-Launch-Secret`
  - JSON body: `{ "moodleUserId": 123, "videoPositionMs": 125800, "videoDurationMs": 600000 }`
  - Saves the latest video position so incomplete videos resume across page visits.

For course launches, `launch.php` now redirects to `/local/lmslaunch/course.php?courseid=...`, which renders CarbonEdge sections and activities inside Moodle.

The shared secret must match `MOODLE_LAUNCH_SHARED_SECRET` in `lms-api`.

## Update on the Moodle host

Copy the plugin source to `/opt/carbon-edge/plugin/lmslaunch`, then replace the files in the running Moodle container. The trailing `/.` copies the contents only and avoids creating a nested `lmslaunch/lmslaunch` directory.

```bash
sudo docker cp /opt/carbon-edge/plugin/lmslaunch/. moodle:/var/www/html/lms/local/lmslaunch/
sudo docker exec moodle php admin/cli/upgrade.php --non-interactive
sudo docker exec moodle rm -rf /var/www/moodledata/cache/* /var/www/moodledata/localcache/*
```

Use the Moodle code root configured in the container. In the current production layout it is `/var/www/html/lms`.
