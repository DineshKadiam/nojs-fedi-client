# SmolFedi

A lightweight, no-JavaScript Fediverse client written in PHP.

**Homepage / source:** https://codeberg.org/adele/smolfedi

---

## What is this?

SmolFedi is a web-based Mastodon/GoToSocial client that works without JavaScript, runs on any basic PHP + SQLite host, and produces HTML that complies with the [smolweb specification](https://smolweb.org/specs/index.html). CSS stays at [Grade B](https://smolweb.org/css-grading.html) maximum.

It is designed for:

- Smolweb advocates
- Retro and text browser users
- Anyone who wants a fast, dependency-free Fediverse interface

---

## Features

- OAuth 2.0 login (works with Mastodon, GoToSocial, and compatible instances)
- **Copy/paste login mode** for old browsers or machines that cannot reach the instance directly — get an authorization code on another device and paste it in
- **Multiple accounts** - log in to several accounts (same or different instances) simultaneously; switch between them via a nav dropdown; each browser tab can use a different account independently via `?a=N` URL parameter
- Available in English, German, Spanish and French
- Home, local, and federated timelines with pagination
- Post detail view with indented thread context, go-to-parent navigation, favourited/boosted by lists, edit history
- Post translation with target language selector (on instances that support it)
- Profile view with follow / unfollow, bio fields, follower and following lists, media gallery
- Compose posts with media attachments and alt text; create polls; schedule publication
- Reply, favourite, boost, quote, bookmark, pin, edit, delete posts
- Link preview cards for posts containing URLs (when provided by the instance)
- Bookmarks and favourites timelines
- Direct messages (conversations)
- Poll display and voting
- Notifications with type filter; clear all notifications
- Hashtag timelines with pin/unpin and follow/unfollow
- Explore page: trending posts, trending hashtags, trending links, and suggested accounts
- User-defined lists — view timelines, create/delete lists, add/remove members
- Search by hashtag, @user@host handle, or URL
- RSS/Atom feed discovery for any URL
- Follow requests management (accept / reject)
- Block, mute, and unblock/unmute accounts; block domains
- Report posts and accounts
- Word/phrase filters — create, delete, expiry and whole-word matching
- Followed hashtags management in preferences
- Instance info page (thumbnail, description, rules, contact)
- Instance announcements displayed after the navigation bar
- Mute conversation threads
- Content warning support — CW hides post body with a "Show content" link
- Sensitive media hidden behind a "Sensitive media" disclosure
- Post visibility indicator (public, unlisted, followers-only, direct)
- Dark / light theme toggle, font size, font family, page width, posts per page, timezone preferences
- Access tokens encrypted in session storage (AES-256-GCM)
- Content Security Policy (no external scripts allowed)
- smolweb Grade B CSS, no JavaScript, no external dependencies

---

## Requirements

- PHP 8.0 or newer
- The `pdo_sqlite` PHP extension
- The `curl` PHP extension
- The `mbstring` PHP extension
- The `zip` PHP extension (required for ePub export)
- The `gd` PHP extension (recommended — used in two places: profile banner display (scale-and-center-crop to the configured aspect ratio) and ePub export (resize attached images before embedding); both features degrade gracefully without it, falling back to the original image)
- A web server (Apache, nginx, Caddy, …)
- An account on a Mastodon-compatible instance

No Composer, no npm, no build step.

---

## Installation

### 1. Download the source

```sh
git clone https://codeberg.org/adele/smolfedi.git
cd smolfedi
```

### 2. Create the data directory

```sh
mkdir -p data
```

Ideally place `data/` outside the document root, then set `DB_PATH` accordingly. If it must stay inside the document root, protect it:

```apache
# .htaccess
Require all denied
```

### 3. Configure the application

Copy the sample config and edit it:

```sh
cp config-sample.php config.php
$EDITOR config.php
```

Set at minimum:

| Constant              | Description                                              |
|-----------------------|----------------------------------------------------------|
| `OAUTH_REDIRECT_URI`  | Public URL of `callback.php` on your server              |
| `DB_PATH`             | Absolute path to the SQLite database file                |
| `SESSIONS_PATH`       | Directory for PHP session files (created automatically)  |
| `SESSION_ENC_KEY`     | Symmetric key used to encrypt OAuth access tokens        |

`APP_NAME` is derived automatically from `OAUTH_REDIRECT_URI` (e.g. `SmolFedi/yourdomain.example`) to distinguish multiple instances in users' authorized apps list.

`SESSION_ENC_KEY` must be a 32-byte random key, base64-encoded. To generate one:

```sh
# with bash:
head -c 32 /dev/urandom | base64
# or with php
php -r 'echo base64_encode(random_bytes(32)), "\n";'
```

### 4. Point your web server at the project root

All `.php` files in the root are entry points; no URL rewriting is needed.

Example nginx block:

```nginx
server {
    listen 443 ssl;
    server_name smolfedi.example.com;
    root /var/www/smolfedi;
    index index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location /data {
        deny all;
    }
}
```

Example caddy block:

```caddy
smolfedi.example.com {
        @blocked {
                path  /data/* /sessions/*
        }
        respond @blocked 403

        root * /var/www/html/smolfedi
        php_fastcgi unix//run/php/php8.4-fpm.sock
        file_server
        encode gzip zstd
}
```

### 5. Open the app in your browser

Navigate to your domain. You will be prompted to log in with your Fediverse account.

---

## Customization

To override or extend the default styles, create `static/custom.css` in the project root. It is loaded automatically after `static/style.css` if the file exists, so any rule you define there takes precedence. The file is never shipped with SmolFedi and will not be overwritten by updates.

```sh
touch static/custom.css
$EDITOR static/custom.css
```

---

## Browser compatibility

SmolFedi requires no JavaScript and produces smolweb Grade B HTML, so it works in most browsers including old and text-mode ones.

### Dillo

Dillo blocks cookies by default, which prevents sessions from persisting. Allow cookies for your SmolFedi domain:

```sh
echo "yourdomain.example ACCEPT" >> ~/.dillo/cookiesrc
```

Replace `yourdomain.example` with the hostname of your SmolFedi instance. Restart Dillo after editing the file.

For instances that cannot be reached directly from the Dillo machine (e.g. when the old computer has no modern TLS support), use the **copy/paste mode** on the login page: check the box, copy the authorization URL shown, open it in a capable browser to get a code, then paste the code back in Dillo.

---

## Project structure

```
config.php          - configuration constants
config-sample.php   - template to copy and edit before first run
bootstrap.php       - session initialisation (included by all pages after config.php)
schema.sql          - SQLite schema (applied automatically on first run)
index.php           - home / local / federated timelines
login.php           - OAuth step 1: redirect to instance (or copy/paste mode)
callback.php        - OAuth step 2: exchange code for token
action.php          - handle all form POST actions (favourite, boost, follow, …)
compose.php         - write a new post or poll
post.php            - single post / thread with context
profile.php         - user profile with bio fields and stats
profile_edit.php    - edit own profile (display name, bio, avatar, header)
follow.php          - followers / following list for a profile
follow_requests.php - accept or reject incoming follow requests
notifications.php   - notification feed with type filter
bookmarks.php       - bookmarked posts
favourites.php      - favourited posts
messages.php        - direct message conversations
tag.php             - hashtag timeline with pin/unpin and follow/unfollow
list.php            - user list timeline
search.php          - search by hashtag, @handle, or URL
explore.php         - explore: trending posts, hashtags, links, and suggested accounts
rssdiscover.php     - discover RSS/Atom feed URL for any external page
epub.php            - export a timeline or hashtag as a self-contained ePub 3 file
report.php          - report a post or account
instance.php        - instance info page (description, rules, contact)
prefs.php           - preferences, lists, filters, and moderation management
switch.php          - handle account switcher form redirect
theme.php           - toggle dark/light theme
imgproxy.php        - server-side image proxy for profile banner scale-and-crop (requires gd)
logout.php          - log out current account (others remain active)
lang/               - UI translations
lib/
  api.php           - Mastodon API wrapper (curl, no external library)
  auth.php          - OAuth helpers, CSRF, redirect()
  db.php            - PDO SQLite helper
  html.php          - shared HTML output helpers
static/
  style.css         - single stylesheet, smolweb Grade B
  custom.css        - optional custom stylesheet loaded after style.css (create if needed)
data/               - SQLite database lives here (create manually)
```

---

## Troubleshooting

### OAuth fails with `server_error` after moving SmolFedi to a different path

When you move SmolFedi to a new URL (e.g. from `/` to `/smolfedi/`), you must:

1. Update `OAUTH_REDIRECT_URI` in `config.php` to the new `callback.php` URL.
2. Clear the OAuth app registrations from the database, because the old `client_id`/`client_secret` were registered with the previous redirect URI and the instance will reject a mismatch:

```sh
sqlite3 data/smolfedi.db "DELETE FROM oauth_apps;"
```

SmolFedi will re-register automatically on the next login attempt.

### Login loop or "App registration not found"

The session and the database are out of sync. Clear your browser session (log out or delete the cookie) and try again. If the problem persists, also clear the `oauth_apps` table as shown above.

---

## Security

- All `$_GET` / `$_POST` inputs are validated and sanitized before use.
- OAuth `state` parameter is verified in `callback.php`.
- CSRF tokens protect all POST forms.
- API HTML content is sanitized through a strict tag whitelist before output.
- Session cookies are `Secure`, `HttpOnly`, `SameSite=Lax`.
- Content Security Policy blocks all external scripts; media from any instance is allowed.
- Session files stored in a dedicated directory (`SESSIONS_PATH`), separate from the database.
- Access tokens in session files are encrypted at rest (AES-256-GCM)

---

## License

Copyright (C) 2026 Adële

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU Affero General Public License as published by the Free
Software Foundation, version 3 of the License.

This program is distributed in the hope that it will be useful, but WITHOUT
ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
FOR A PARTICULAR PURPOSE. See the GNU Affero General Public License for more
details.

You should have received a copy of the GNU Affero General Public License along
with this program. If not, see <https://www.gnu.org/licenses/agpl-3.0.html>.
