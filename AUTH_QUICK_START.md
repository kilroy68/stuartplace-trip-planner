# Quick start: Google login on Hostinger

## What exists already

You do **not** need to create these PHP files manually. They are already in the repo in commit `8527307`:

- `auth/callback.php`
- `auth/login.php`
- `auth/logout.php`
- `auth/users.php`
- `auth/config.example.php`
- `index.php`
- `california-trip/index.php`

The only private file you manually create on Hostinger is now preferably:

- `stuartplace-config.php` one folder above `public_html`

The old fallback still works, but can disappear during Git deployment:

- `auth/config.php`

## Where is config.example.php?

Right now, before pushing, it is on David's Mac at:

`/Users/davidstuart/stuartplace-trip-planner/auth/config.example.php`

After I push the auth commit and Hostinger deploys it, it will be on Hostinger at approximately:

`public_html/auth/config.example.php`

or, depending on your deployment folder:

`domains/stuartplace.net/public_html/auth/config.example.php`

## What you do in Hostinger File Manager

1. Open Hostinger hPanel.
2. Go to File Manager.
3. Open the website folder for `stuartplace.net`.
4. Open `public_html/auth`.
5. Copy the working `config.php` file.
6. Go back up to the folder that contains `public_html`.
7. Paste the file there.
8. Rename the pasted file to `stuartplace-config.php`.
9. Leave `public_html/auth/config.php` in place as a temporary fallback until the new code is deployed and confirmed working.

After this, the private config is outside the Git-deployed folder and should not disappear during future deployments.

## Do I manually create callback.php?

No.

`auth/callback.php` is already part of the code. Google redirects users there after login.

In Google Cloud Console, you only register this URL as an Authorized redirect URI:

`https://www.stuartplace.net/auth/callback.php`

## What the empty MySQL database is for

You do not need to manually create tables.

The PHP code automatically creates the `allowed_users` table on first run.

It also automatically seeds:

- `david.c.stuart@gmail.com` as admin
- `angelarx@gmail.com` as admin

## Deployment warning

The auth code has not been pushed yet. Once it is pushed, the site will require `auth/config.php` on Hostinger. If that file is missing, the site will show an authentication setup message instead of the normal pages.

Safe deployment order:

1. Push/deploy auth code.
2. Immediately create `auth/config.php` on Hostinger from `auth/config.example.php`.
3. Fill in Google + MySQL settings.
4. Visit `https://www.stuartplace.net/` and sign in.

## Google Cloud OAuth settings

Create an OAuth Client ID:

- Application type: Web application
- Authorized JavaScript origins:
  - `https://www.stuartplace.net`
- Authorized redirect URI:
  - `https://www.stuartplace.net/auth/callback.php`

Then paste the client ID and client secret into `auth/config.php`.
