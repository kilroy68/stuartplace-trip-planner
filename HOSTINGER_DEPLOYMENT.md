# Stuart Place: visited states + New England 2027

## Release contents

- `/`: private, interactive 50-state map tracking only states David and Angela have visited together, with links to both planners. On first load, any state marked for both travelers in the earlier tracker is carried into the shared list.
- `/new-england-trip/`: October 3–14, 2027 tentative itinerary (11 nights), clickable map, search, driving links, print, and shared per-stop lodging/confirmation/cost/notes.
- `/future-trips/`: shared, prioritized list of possible trips with title, description, add, remove, and reorder controls.
- `/api/travel.php`: existing Google session and admin role; mutations require POST + session CSRF token. Data is stored in separate `travel_visits`, `travel_plans`, and `travel_future_trips` tables, created automatically in the configured MySQL database.
- California, OAuth configuration loader, callback, allowed users, session cookies, and existing database tables are unchanged.

The map route uses bundled road geometry between overnight towns. It is an overview, not live traffic or turn-by-turn navigation. Scenic detours have separate direction links. The map needs the Leaflet CDN and OpenStreetMap tiles; the itinerary and locally bundled state map remain available without those services. Viewing requires login; admins edit shared data.

## Hostinger deployment gate

Hostinger hPanel access was blocked in the working cloud browser. No production deployment has been verified. No hosting credentials, webhook URL, or SSH destination were available. Do not treat a GitHub commit as proof of a Hostinger deployment.

Before deploying through the existing Hostinger Git integration, verify the private `stuartplace-config.php` is outside `public_html` as described in `CONFIG_SAFE_DEPLOY.md`. This matters because a replacing Git deployment may remove the ignored legacy `auth/config.php`. No new OAuth client or callback is needed.

Use the existing repository and PHP hosting document root; do not switch this PHP project to static hosting. Deploy the reviewed commit using the existing Hostinger integration. The database account already performs schema creation for authentication; it must also be able to create the two new tables. Back up the existing database and any server-only files before replacing the document root.

Release checks are in `.github/workflows/check-travel.yml`. After deployment, verify existing Google login, homepage map save/reload from another session, New England stop save/reload, non-admin read-only access, and the unchanged California planner. Revert the code commit to roll back; the separate travel tables can remain in place without changing California data.

## Verification limits

Local checks passed for JavaScript syntax, itinerary/state/road geometry consistency, DOM interaction tests (traveler separation, save failure handling, read-only forms, safe note rendering, search and map fallback), and PHP 8.3 parsing using php-parser. Native PHP runtime execution and visual browser QA were unavailable locally; CI includes native PHP linting. Live Google OAuth, MySQL persistence and Hostinger deployment require the existing hosting environment; they were not verified by source inspection.
