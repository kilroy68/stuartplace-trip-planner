# Keep `config.php` from disappearing on Hostinger

Hostinger Git deployments can replace the deployed `public_html` folder with the files from GitHub. Because the real `auth/config.php` is intentionally ignored by Git, a deployment can delete it.

The fix is to keep the private config file **outside `public_html`**.

## Permanent config location

Create this file on Hostinger:

`stuartplace-config.php`

one folder above `public_html`.

Most likely location in Hostinger File Manager:

`domains/stuartplace.net/stuartplace-config.php`

Your web files are probably here:

`domains/stuartplace.net/public_html/`

So the private config should sit beside `public_html`, not inside it:

```text
domains/stuartplace.net/
├── public_html/
│   ├── auth/
│   │   ├── bootstrap.php
│   │   ├── config.example.php
│   │   └── ...
│   └── california-trip/
└── stuartplace-config.php   ← private config lives here
```

## Exact Hostinger steps

1. Open Hostinger hPanel.
2. Open File Manager.
3. Go to the folder for `stuartplace.net`.
4. Open `public_html/auth/`.
5. Copy the working `config.php` file.
6. Go back up one level until you are in the folder that contains `public_html`.
7. Paste the file there.
8. Rename the pasted file to:

   `stuartplace-config.php`

9. Leave the original `public_html/auth/config.php` in place until you confirm the site still works.
10. After this code is deployed, the site will use the outside config first.

## Loader order

The authentication code checks for config files in this order:

1. `STUARTPLACE_CONFIG` environment variable, if set.
2. `stuartplace-config.php` one level above `public_html`.
3. Alternate shared-host parent folders.
4. Legacy fallback: `public_html/auth/config.php`.

That means the site will keep working with the old file, but future deployments will be safe once the outside `stuartplace-config.php` exists.

## Do not commit secrets

Do not add the real config file to GitHub. It contains:

- Google OAuth client secret
- MySQL password
- session secret
- optional SmugMug API key/secret

Only `auth/config.example.php` belongs in GitHub.
