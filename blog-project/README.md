# PlacementPrep Hub (PHP + MySQL)

## Setup
1. Install/start **Apache + PHP + MySQL** (XAMPP/WAMP/LAMP).
2. Create/import DB using:
   ```bash
   mysql -u root -p < schema.sql
   ```
3. Put project inside web root (example `htdocs/PlacementPrep-Hub/blog-project`).
4. Configure DB credentials using environment variables (recommended):
   - `DB_HOST` (default `127.0.0.1`)
   - `DB_PORT` (default `3306`)
   - `DB_NAME` (default `placementprep`)
   - `DB_USER` (default `root`)
   - `DB_PASS` (default empty)
5. Open the app in browser from your PHP server URL.

> DB creation/import is the only manual step left for you.

## API Endpoints
All APIs are in `api/index.php` and are called using `?endpoint=...`.
Wrapper files also exist for required endpoints: `register.php`, `login.php`, `contact.php`, `report.php`, `analytics.php`, `role.php`, `followers.php`, `user_kv.php`.

Core endpoints:
- `POST endpoint=register`
- `POST endpoint=login` (uses PHP session)
- `POST endpoint=logout`
- `GET endpoint=session`
- `POST/GET endpoint=contact`
- `POST/GET/DELETE endpoint=report`
- `POST/GET endpoint=analytics`
- `PUT endpoint=role`
- `POST/GET endpoint=followers`
- `GET/PUT/DELETE endpoint=user_kv`

App compatibility endpoints also implemented:
- users, posts/post, comments, replies, likes, saved, drafts

## Security Notes
- Passwords are hashed with `password_hash`.
- Login uses PHP sessions (no JWT).
- Protected endpoints reject unauthenticated users (`401`) and admin-only routes enforce role checks (`403`).
- All user-rendered content in frontend is escaped before HTML output to reduce XSS risk.
- APIs return real JSON errors/messages for frontend display.
