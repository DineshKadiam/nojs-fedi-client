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
- **Multiple accounts** - log in to several accounts (same or different instances) simultaneously; switch between them via a nav dropdown; each browser tab can use a different account independently via `?a=N` URL parameter
- Home timeline with pagination
- Post detail view with thread context
- Profile view with follow / unfollow
- Compose posts with media attachments and alt text
- Reply, favourite, boost, bookmark
- Poll display and voting
- Notifications with type filter
- Hashtag timelines
- Content warning support - CW hides post body with a "Show content" link
- Dark / light theme toggle (saved in session)

---

## Requirements

- PHP 8.0 or newer
- The `pdo_sqlite` PHP extension
- The `curl` PHP extension
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
<DirectoryMatch "data">
    Require all denied
</DirectoryMatch>
```

### 3. Configure the application

Copy the sample config and edit it:

```sh
cp config-sample.php config.php
$EDITOR config.php
```

Set at minimum:

| Constant            | Description                                              |
|---------------------|----------------------------------------------------------|
| `DEFAULT_INSTANCE`  | URL of the Fediverse instance users will log in to       |
| `DB_PATH`           | Absolute path to the SQLite database file                |
| `APP_NAME`          | Name shown to users during OAuth authorization           |
| `OAUTH_REDIRECT_URI`| Public URL of `callback.php` on your server              |

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

### 5. Open the app in your browser

Navigate to your domain. You will be prompted to log in with your Fediverse account.

---

## GoToSocial notes

SmolFedi works with GoToSocial. A few differences from standard Mastodon apply:

- GoToSocial uses ULID-format IDs (not numeric). The code handles this correctly.
- GoToSocial requires a `User-Agent` header on all API requests. SmolFedi sends `SmolFedi/1.0`.
- Some fields (e.g. `bookmarked`) may be absent depending on the GtS version. SmolFedi detects this gracefully.

---

## Project structure

```
config.php          - configuration constants + session bootstrap
config-sample.php   - template to copy before first run
schema.sql          - SQLite schema (applied automatically on first run)
index.php           - home timeline
login.php           - OAuth step 1: redirect to instance
callback.php        - OAuth step 2: exchange code for token
logout.php          - log out current account (others remain active)
switch.php          - handle account switcher form redirect
profile.php         - user profile
post.php            - single post / thread
compose.php         - write a new post
notifications.php   - notification feed
tag.php             - hashtag timeline
action.php          - handle all form POST actions
theme.php           - toggle dark/light theme
lib/
  api.php           - Mastodon API wrapper (curl, no external library)
  auth.php          - OAuth helpers
  db.php            - PDO SQLite helper
  html.php          - shared HTML output helpers
static/
  style.css         - single stylesheet, smolweb Grade B
data/               - SQLite database lives here (create manually)
```

---

## Security

- All `$_GET` / `$_POST` inputs are validated and sanitized before use.
- OAuth `state` parameter is verified in `callback.php`.
- CSRF tokens protect all POST forms.
- API HTML content is sanitized through a strict tag whitelist before output.
- Session cookies are `Secure`, `HttpOnly`, `SameSite=Lax`.

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
with this program. If not, see <https://www.gnu.org/licenses/>.
