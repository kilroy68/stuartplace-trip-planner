# Hostinger Google authentication setup

This repo now contains a PHP + MySQL Google sign-in gate for the site.

## Protected pages

- `/` is served by `index.php` and requires login.
- `/california-trip/` is served by `california-trip/index.php` and requires login.
- `/auth/login.php`, `/auth/callback.php`, and `/auth/logout.php` handle Google sign-in.
- `/auth/users.php` is the admin allow-list page.

## Initial allowed accounts

Configured in `auth/config.php`:

- `david.c.stuart@gmail.com` as admin
- `angelarx@gmail.com` as admin

These are re-seeded as active admin accounts whenever the auth layer initializes.

## 1. Create Google OAuth credentials

In Google Cloud Console:

1. Create or select a project.
2. Go to APIs & Services > OAuth consent screen.
3. Configure the consent screen for external users unless you are using a Google Workspace-only app.
4. Go to APIs & Services > Credentials.
5. Create an OAuth client ID.
6. Application type: Web application.
7. Add this authorized redirect URI exactly:

   `https://www.stuartplace.net/auth/callback.php`

8. Copy the client ID and client secret.

## 2. Create Hostinger MySQL database

In Hostinger hPanel:

1. Create a MySQL database.
2. Create/assign a MySQL user.
3. Save:
   - database host
   - database name
   - database username
   - database password

The app creates the `allowed_users` table automatically the first time it connects.

## 3. Create the private config file on Hostinger

Permanent/safe location:

`stuartplace-config.php`

placed one folder above `public_html`, usually:

`domains/stuartplace.net/stuartplace-config.php`

This keeps the file outside the Git-deployed web folder so deployments cannot delete it.

Temporary fallback location, still supported:

`auth/config.php`

Inside Hostinger File Manager, copy:

`auth/config.example.php`

to the permanent location above as:

`stuartplace-config.php`

Then fill in:

- `google_client_id`
- `google_client_secret`
- `db_host`
- `db_name`
- `db_user`
- `db_pass`
- `session_secret`

`auth/config.php` is ignored by git so secrets are not committed.

## 4. First login

After config is in place:

1. Visit `https://www.stuartplace.net/`.
2. Sign in with `david.c.stuart@gmail.com` or `angelarx@gmail.com`.
3. Open `https://www.stuartplace.net/auth/users.php` to manage allowed users.

## 5. Add other users

Use `/auth/users.php` as an admin:

- Add a Google email address.
- Choose `User` or `Admin`.
- Disable, enable, or delete users later.

## Notes

- The site uses server-side PHP sessions. It does not rely on client-side-only JavaScript login.
- Protected pages should not be duplicated as public `.html` files.
- `.htaccess` redirects old `index.html` URLs to the PHP-protected routes.
- If Hostinger Git deployment wipes untracked files, re-upload `auth/config.php` after deployment or configure Hostinger to preserve it.
