# GoalLive — PHP + MySQL + XAMPP starter

This is a beginner-friendly PHP application structure for a football website. It includes:
- Home page and fixtures/scores from the MySQL database
- User registration/login/logout with `password_hash()` / `password_verify()`
- PDO prepared statements, HTML escaping, session cookies, and CSRF tokens on forms
- Admin dashboard to add fixtures, update scores/status, and delete matches
- Match details page and authorized stream URL field
- Responsive dark football UI and ad placement placeholder

## Local installation with XAMPP (Windows)

1. Install XAMPP from https://www.apachefriends.org/
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Copy the extracted `goallive-php-xampp` folder to `C:\xampp\htdocs\goallive-php-xampp`.
4. Open `http://localhost/phpmyadmin`.
5. Choose **Import**, select `database/goallive.sql`, and run it. It creates the `goallive` database and tables.
6. Open `config/config.php`. For default local XAMPP, `DB_USER` is often `root` and `DB_PASS` is often blank. Change them if yours differ.
7. Visit `http://localhost/goallive-php-xampp/`.
8. Register an account at `http://localhost/goallive-php-xampp/register.php`.
9. Promote your account to administrator in phpMyAdmin → `goallive` → SQL. Replace the email:
   `UPDATE users SET role='admin' WHERE email='you@example.com';`
10. Sign out, sign back in, then open `http://localhost/goallive-php-xampp/admin/`.

The config is pre-set for `http://localhost/goallive-php-xampp/`. If you rename the folder or use a different path, update `BASE_URL` in `config/config.php` to match (for example `/myfolder`). If Apache/MySQL fails to start, check whether another service is using ports 80/443/3306.

## Optional real football fixtures/scores

The included project starts with manually managed database fixtures. For live external data, get an API key from a provider such as API-Football: https://www.api-football.com/documentation-v3

Add an integration endpoint on the PHP server, keep the API key on the server, cache results according to the provider's terms, and respect quotas. Do not put private API keys in HTML or JavaScript. API data access does not provide TV broadcast rights.

## Authorized video streams

The admin dashboard accepts a stream URL only as a starting point. The watch page's simple `<video>` tag can play direct MP4 sources and browsers with native HLS support may play some `.m3u8` sources. For cross-browser HLS, use a provider-authorized player or integrate hls.js and ensure provider CORS/domain settings permit playback. Do not scrape or redistribute protected sports broadcasts.

## Publish online later

XAMPP is for local development, not public production hosting. For launch, choose PHP/MySQL hosting or a VPS with HTTPS, supported PHP, MySQL, backups and monitoring. Before publishing:
- Move credentials out of the web root/environment configuration and set production secrets securely.
- Set a strong database password and least-privilege database user.
- Disable public error display; log errors privately.
- Add rate limiting, email verification, password reset, account deletion and stronger session protections.
- Add privacy policy, terms, cookie/consent controls where required, and an authorized stream contract.
- Use backups and test restore procedures.
- Add ad-network code only after approval and compliance checks.

## Main structure

- `index.php` — home page
- `fixtures.php` — fixture list and scores
- `watch.php` — match detail and authorized player area
- `register.php`, `login.php`, `logout.php` — account flow
- `admin/index.php` — match management
- `config/config.php` — app config, PDO, session and helpers
- `database/goallive.sql` — database schema and sample match
- `assets/css/style.css` — responsive styling
