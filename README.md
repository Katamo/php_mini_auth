# php_mini_auth

Reusable PHP authentication module with sessions. Designed as an access barrier for static or simple web projects served with nginx + PHP-FPM.

- Users with bcrypt passwords stored in JSON outside the web root
- PHP sessions with per-project isolation (`session_name`)
- Auto-generated login page with configurable theming (warm, dark, or custom)
- User management via CLI over SSH
- Server-side protection using nginx `auth_request` (no flash, no visible JS on protected pages)

## Quick start

Want to password-protect your site? You need to do five things:

1. **Copy the `auth/` folder into your project** along with `themes/` and `login.html` — these are the files that make everything work.

2. **Create your config** — duplicate `auth.config.example.json` as `auth.config.json` and fill in your project name and the path where users will be stored. Add that file to your `.gitignore`; it must never be committed to the repository.

3. **Add a build step** — your build script reads the config, injects the theme into the login page, and copies the PHP files to `dist/auth/`. See the [Generate login.html](#3-generate-loginhtml-during-the-build) section.

4. **Configure nginx** — copy the config block from the [nginx](#nginx-configuration) section and replace `subdomain.yourdomain.com` and `/var/www/name` with your own values. Verify with `sudo nginx -t` and reload.

5. **Create your users** — on the server via SSH, create the users file and add your first user with `php /path/auth/add-user.php your-username`. The script will prompt for a password. To add more users in the future, repeat this command.

---

## Module files

```
auth/
  login.php       POST: validates credentials and creates session
  check.php       GET:  returns 200/401 based on session (used by nginx auth_request)
  logout.php      GET/POST: destroys the session
  add-user.php    CLI: user management (add / list / remove)
themes/
  warm.css        Warm serif theme (Lora + DM Sans)
  dark.css        Dark monospace theme (IBM Plex Mono)
login.html        Login page template (placeholders {{variable}})
auth.config.example.json
```

---

## Quick integration

### 1. Copy the module into your project

```bash
cp -r php_mini_auth/auth/    my-project/auth/
cp -r php_mini_auth/themes/  my-project/auth/themes/
cp    php_mini_auth/login.html my-project/auth/login.html
cp    php_mini_auth/auth.config.example.json my-project/auth/auth.config.example.json
```

### 2. Create the config (gitignored)

```bash
cp my-project/auth/auth.config.example.json my-project/auth/auth.config.json
```

Edit `auth.config.json`:

```json
{
  "project_name": "Project name",
  "subtitle":     "private access",
  "session_key":  "unique_project_name",
  "users_file":   "/var/www/.name-users.json",
  "theme":        "warm"
}
```

Add to the project's `.gitignore`:
```
auth/auth.config.json
```

> `session_key` acts as the session cookie name. Use a unique value per project so that projects on the same server don't interfere with each other.

> `users_file` must point **outside the project's web root** so nginx cannot serve it.

### 3. Generate login.html during the build

`auth/login.html` uses placeholders `{{project_name}}`, `{{subtitle}}`, and `{{css}}`. The build replaces them with a simple `replaceAll` — no templating dependencies. Example for a project with a Node.js `build.js`:

```js
const cfg      = JSON.parse(fs.readFileSync('auth/auth.config.json', 'utf8'));
const css      = fs.readFileSync(`auth/themes/${cfg.theme}.css`, 'utf8');
const loginHtml = fs.readFileSync('auth/login.html', 'utf8')
  .replaceAll('{{project_name}}', cfg.project_name)
  .replaceAll('{{subtitle}}',     cfg.subtitle)
  .replaceAll('{{css}}',          css);

fs.mkdirSync('dist/auth', { recursive: true });
fs.writeFileSync('dist/auth/login.html', loginHtml);

for (const f of ['login.php', 'check.php', 'logout.php', 'add-user.php']) {
  fs.copyFileSync(`auth/${f}`, `dist/auth/${f}`);
}
```

### 4. Configure the server (once via SSH)

#### Create the users file
```bash
sudo touch /var/www/.name-users.json
echo '{}' | sudo tee /var/www/.name-users.json
sudo chown oscar:oscar /var/www/.name-users.json
sudo chmod 644 /var/www/.name-users.json
```

#### Copy auth.config.json to the server
```bash
scp -i ~/.ssh/gcp_key auth/auth.config.json user@ip:/var/www/name/auth/auth.config.json
```

#### Add the first user
```bash
ssh -i ~/.ssh/gcp_key user@ip
php /var/www/name/auth/add-user.php oscar
```

---

## User management (CLI)

```bash
# Add or update a user
php /var/www/name/auth/add-user.php <username>

# List users
php /var/www/name/auth/add-user.php --list

# Remove a user
php /var/www/name/auth/add-user.php --remove <username>
```

---

## nginx configuration

Full example for a subdomain with a protected area. Adjust `server_name`, `root`, and the PHP-FPM socket (`php8.x-fpm.sock`) to match your server.

To check the installed PHP-FPM version: run `php -v` on the server.

```nginx
server {
    listen 443 ssl;
    server_name subdomain.yourdomain.com;

    root  /var/www/name;
    index index.html;

    # SSL — Let's Encrypt
    ssl_certificate     /etc/letsencrypt/live/subdomain.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/subdomain.yourdomain.com/privkey.pem;
    include             /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam         /etc/letsencrypt/ssl-dhparams.pem;

    # ── Internal endpoint for auth_request ──────────────────────────────
    # 'internal' makes it inaccessible directly from the browser.
    # nginx calls it on every protected request to verify the session.
    location = /auth/check.php {
        internal;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # ── Public auth endpoints ────────────────────────────────────────────
    # login.html: auto-generated static page
    location = /auth/login.html { }

    # login.php and logout.php: PHP endpoints with no session restriction
    location ~ ^/auth/(login|logout)\.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # ── Block HTTP access to sensitive files ─────────────────────────────
    location = /auth/add-user.php  { deny all; }
    location ~ /auth/.*\.json$     { deny all; }

    # ── Protected area ───────────────────────────────────────────────────
    # Every request goes through check.php before being served.
    # If check.php returns 401, nginx redirects to login with the original URL.
    location / {
        auth_request /auth/check.php;
        error_page 401 = @login_redirect;
        try_files $uri $uri/index.html =404;
    }

    location @login_redirect {
        return 302 /auth/login.html?redirect=$request_uri;
    }
}

# Redirect HTTP → HTTPS
server {
    listen 80;
    server_name subdomain.yourdomain.com;
    return 301 https://$host$request_uri;
}
```

### Enable the virtual host

```bash
# Link and verify
sudo ln -s /etc/nginx/sites-available/subdomain /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### Multiple protected projects on the same server

Each project has its own `server {}` block with:
- Its own `server_name` and `root`
- Its own `auth.config.json` (distinct `session_key` and `users_file`)
- Users in separate JSON files (e.g. `.menus-users.json`, `.photos-users.json`)

Projects don't share sessions thanks to the combination of distinct domains (cookies isolated by domain) and unique `session_key` values.

---

## Themes

| Theme  | Typography           | Background      | Best for                        |
|--------|----------------------|-----------------|---------------------------------|
| `warm` | Lora + DM Sans       | Cream/white     | Editorial or food-style projects |
| `dark` | IBM Plex Mono        | Black           | Technical or portfolio projects  |

### Custom theme

Create `auth/themes/my-theme.css` following the structure of `warm.css` or `dark.css` (same BEM classes: `.login`, `.login__card`, `.login__title`, etc.) and reference the new theme in `auth.config.json`:

```json
{ "theme": "my-theme" }
```

---

## Security

- Passwords are stored securely, never in plain text
- Measures are applied to prevent unauthorized access
- Sensitive files are not accessible from the browser
- The config and user list are never exposed publicly
