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
- Home, local, and federated timelines with pagination
- Post detail view with thread context, favourited/boosted by lists
- Profile view with follow / unfollow, bio fields, follower and following lists
- Compose posts with media attachments and alt text; create polls
- Reply, favourite, boost, bookmark, pin, edit, delete posts
- Bookmarks and favourites timelines
- Direct messages (conversations)
- Poll display and voting
- Notifications with type filter; clear all notifications
- Hashtag timelines with pin/unpin
- User-defined lists — view timelines, create/delete lists, add/remove members
- Search by hashtag, @user@host handle, or URL
- Follow requests management (accept / reject)
- Block, mute, and unblock/unmute accounts; block domains
- Report posts and accounts
- Word/phrase filters with expiry and whole-word matching
- Mute conversation threads
- Content warning support — CW hides post body with a "Show content" link
- Sensitive media hidden behind a "Sensitive media" disclosure
- Post visibility indicator (public, unlisted, followers-only, direct)
- Dark / light theme toggle, font size, page width, posts per page, timezone preferences
- Access tokens encrypted in session storage (AES-256-GCM)
- Content Security Policy (no external scripts allowed)
- smolweb Grade B CSS, no JavaScript, no external dependencies

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
| `OAUTH_REDIRECT_URI` | Public URL of `callback.php` on your server             |
| `DB_PATH`            | Absolute path to the SQLite database file               |
| `SESSIONS_PATH`      | Directory for PHP session files (created automatically) |

`APP_NAME` is derived automatically from `OAUTH_REDIRECT_URI` (e.g. `SmolFedi/yourdomain.example`) to distinguish multiple instances in users' authorized apps list.

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

## GoToSocial notes

SmolFedi works with GoToSocial. A few differences from standard Mastodon apply:

- GoToSocial uses ULID-format IDs (not numeric). The code handles this correctly.
- GoToSocial requires a `User-Agent` header on all API requests. SmolFedi sends `SmolFedi/<version>`.
- Some fields (e.g. `bookmarked`) may be absent depending on the GtS version. SmolFedi detects this gracefully.

---

## Project structure

```
config.php          - configuration constants
config-sample.php   - template to copy before first run
bootstrap.php       - session initialisation (included by all pages after config.php)
schema.sql          - SQLite schema (applied automatically on first run)
index.php           - home timeline
login.php           - OAuth step 1: redirect to instance
callback.php        - OAuth step 2: exchange code for token
bookmarks.php       - bookmarked posts
messages.php        - direct message conversations
follow.php          - followers / following list for a profile
logout.php          - log out current account (others remain active)
switch.php          - handle account switcher form redirect
profile.php         - user profile with bio fields and stats
post.php            - single post / thread with context
compose.php         - write a new post
notifications.php   - notification feed with type filter
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
- Content Security Policy blocks all external scripts; media from any instance is allowed.
- Session files stored in a dedicated directory (`SESSIONS_PATH`), separate from the database.

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
