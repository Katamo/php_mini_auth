# php_mini_auth

Módulo PHP reutilizable de autenticación con sesiones. Pensado como barrera de acceso para proyectos web estáticos o simples servidos con nginx + PHP-FPM.

- Usuarios con contraseña bcrypt almacenados en JSON fuera del web root
- Sesiones PHP con aislamiento por proyecto (`session_name`)
- Página de login autogenerada con theming configurable (warm, dark, o custom)
- Gestión de usuarios por CLI vía SSH
- Protección server-side mediante nginx `auth_request` (sin flash, sin JS visible en páginas protegidas)

## Archivos del módulo

```
auth/
  login.php       POST: valida credenciales y crea sesión
  check.php       GET:  devuelve 200/401 según sesión (usado por nginx auth_request)
  logout.php      GET/POST: destruye la sesión
  add-user.php    CLI: gestión de usuarios (add / list / remove)
themes/
  warm.css        Tema cálido serif (Lora + DM Sans)
  dark.css        Tema oscuro monospace (IBM Plex Mono)
login.html        Template de la página de login (placeholders {{variable}})
auth.config.example.json
```

---

## Integración rápida

### 1. Copiar el módulo al proyecto

```bash
cp -r php_mini_auth/auth/    mi-proyecto/auth/
cp -r php_mini_auth/themes/  mi-proyecto/auth/themes/
cp    php_mini_auth/login.html mi-proyecto/auth/login.html
cp    php_mini_auth/auth.config.example.json mi-proyecto/auth/auth.config.example.json
```

### 2. Crear la configuración (gitignoreada)

```bash
cp mi-proyecto/auth/auth.config.example.json mi-proyecto/auth/auth.config.json
```

Editar `auth.config.json`:

```json
{
  "project_name": "Nombre del proyecto",
  "subtitle":     "acceso privado",
  "session_key":  "nombre_unico_user",
  "users_file":   "/var/www/.nombre-users.json",
  "theme":        "warm"
}
```

Añadir al `.gitignore` del proyecto:
```
auth/auth.config.json
```

> `session_key` actúa como nombre de la cookie de sesión. Usa un valor único por proyecto para que proyectos en el mismo servidor no interfieran entre sí.

> `users_file` debe apuntar **fuera del web root** del proyecto para que nginx no pueda servirlo.

### 3. Generar login.html durante el build

`auth/login.html` usa placeholders `{{project_name}}`, `{{subtitle}}` y `{{css}}`. El build los sustituye con un simple `replaceAll` — sin dependencias de templating. Ejemplo para un proyecto con `build.js` en Node.js:

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

### 4. Configurar el servidor (una sola vez vía SSH)

#### Crear el archivo de usuarios
```bash
sudo touch /var/www/.nombre-users.json
echo '{}' | sudo tee /var/www/.nombre-users.json
sudo chown oscar:oscar /var/www/.nombre-users.json
sudo chmod 644 /var/www/.nombre-users.json
```

#### Copiar auth.config.json al servidor
```bash
scp -i ~/.ssh/gcp_key auth/auth.config.json usuario@ip:/var/www/nombre/auth/auth.config.json
```

#### Añadir el primer usuario
```bash
ssh -i ~/.ssh/gcp_key usuario@ip
php /var/www/nombre/auth/add-user.php oscar
```

---

## Gestión de usuarios (CLI)

```bash
# Añadir o actualizar usuario
php /var/www/nombre/auth/add-user.php <usuario>

# Listar usuarios
php /var/www/nombre/auth/add-user.php --list

# Eliminar usuario
php /var/www/nombre/auth/add-user.php --remove <usuario>
```

---

## Configuración nginx

Ejemplo completo para un subdominio con área protegida. Ajusta `server_name`, `root`, y el socket de PHP-FPM (`php8.x-fpm.sock`) según tu servidor.

Para verificar la versión de PHP-FPM instalada: `php -v` en el servidor.

```nginx
server {
    listen 443 ssl;
    server_name subdominio.tudominio.es;

    root  /var/www/nombre;
    index index.html;

    # SSL — Let's Encrypt
    ssl_certificate     /etc/letsencrypt/live/subdominio.tudominio.es/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/subdominio.tudominio.es/privkey.pem;
    include             /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam         /etc/letsencrypt/ssl-dhparams.pem;

    # ── Endpoint interno para auth_request ──────────────────────────────
    # 'internal' lo hace inaccesible directamente desde el navegador.
    # nginx lo llama en cada petición protegida para comprobar la sesión.
    location = /auth/check.php {
        internal;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # ── Endpoints públicos de auth ───────────────────────────────────────
    # login.html: página estática autogenerada
    location = /auth/login.html { }

    # login.php y logout.php: endpoints PHP sin restricción de sesión
    location ~ ^/auth/(login|logout)\.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # ── Bloquear acceso HTTP a archivos sensibles ────────────────────────
    location = /auth/add-user.php  { deny all; }
    location ~ /auth/.*\.json$     { deny all; }

    # ── Área protegida ───────────────────────────────────────────────────
    # Toda petición pasa por check.php antes de ser servida.
    # Si check.php devuelve 401, nginx redirige al login con la URL original.
    location / {
        auth_request /auth/check.php;
        error_page 401 = @login_redirect;
        try_files $uri $uri/index.html =404;
    }

    location @login_redirect {
        return 302 /auth/login.html?redirect=$request_uri;
    }
}

# Redirigir HTTP → HTTPS
server {
    listen 80;
    server_name subdominio.tudominio.es;
    return 301 https://$host$request_uri;
}
```

### Activar el virtual host

```bash
# Enlazar y verificar
sudo ln -s /etc/nginx/sites-available/subdominio /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### Múltiples proyectos protegidos en el mismo servidor

Cada proyecto tiene su propio bloque `server {}` con:
- `server_name` y `root` propios
- `auth.config.json` propio (session_key y users_file distintos)
- Usuarios en archivos JSON separados (ej: `.menus-users.json`, `.fotos-users.json`)

Los proyectos no comparten sesión gracias a la combinación de dominios distintos (cookies aisladas por dominio) y `session_key` únicos.

---

## Temas

| Tema   | Tipografía           | Fondo     | Indicado para         |
|--------|----------------------|-----------|-----------------------|
| `warm` | Lora + DM Sans       | Crema/blanco | Proyectos con estética editorial/cocina |
| `dark` | IBM Plex Mono        | Negro     | Proyectos técnicos/portfolio |

### Tema personalizado

Crea `auth/themes/mi-tema.css` siguiendo la estructura de `warm.css` o `dark.css` (mismas clases BEM: `.login`, `.login__card`, `.login__title`, etc.) y referencia el nuevo tema en `auth.config.json`:

```json
{ "theme": "mi-tema" }
```

---

## Seguridad

- Contraseñas hasheadas con **bcrypt** (`password_hash` / `password_verify`)
- **300ms de delay** en credenciales inválidas para ralentizar fuerza bruta
- **`session_regenerate_id(true)`** tras login exitoso (previene session fixation)
- Archivo de usuarios **fuera del web root** (nginx no lo sirve nunca)
- `auth.config.json` **bloqueado** en nginx y **gitignoreado**
- `add-user.php` **bloqueado** en nginx (solo ejecutable por CLI vía SSH)
- `check.php` marcado como **`internal`** en nginx (inaccesible desde el navegador)
