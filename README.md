# Orneos status

The source of [status.orneos.eu](https://status.orneos.eu): the availability of Orneos, measured from outside
its servers, so the page stays up when they are not.

- Every 5 minutes, GitHub Actions checks the site, the cloud and the mail ports (`check.php`), keeps the 90-day
  history, and reads the announcements (incidents, maintenance) published by orneos.eu.
- `build.php` renders the page in French, English and German and publishes it on GitHub Pages.
- An outage that lasts two checks opens an issue in this repository, closed when the service is back.

The data lives on the `data` branch (one commit, replaced at each run). This repository is generated from the
Orneos repository (`status/`, `tools/status-publish.sh`): changes made here are overwritten.
