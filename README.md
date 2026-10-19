# Home

One place to keep track of everything: passwords, money, reminders and client time. Built on
**Bailey's PHP Framework** (router → controller → Twig, `App` class, `Db.class.php` + easyCRUD, `app/Ajax` endpoints,
`Mailer`, AdminKit Pro). LAMP, no Composer, no build step. Lives at **home.300interactive.com**.

## What's in it

| Section | What it does |
|---|---|
| **Dashboard** `/` | Hours this week vs your goal, money ready to invoice, money owed to you, spending vs budget · timer · this week's hours by client · today's reminders and bills · invoices that need attention · vault health · spending, budgets, cash flow, goals · recent activity · a "remind me…" box |
| **Vault** `/vault` | Passwords, cards, secure notes, Wi-Fi and servers, encrypted with a master password · folders, favorites, search · copy buttons (the clipboard is cleared after a minute where the browser allows it) · **live 2FA codes** · password history · custom fields · auto-lock |
| Password health | Score out of 100 · weak, reused and year-old passwords · logins without 2FA saved |
| Generator | Random, word passphrases (EFF word list) or PINs, made in the browser |
| **Import & export** | **Your old `bailey_accounts` database** (pick whose accounts, preview first) · CSV from Chrome / Edge / Brave, Safari / iCloud Keychain, Firefox, Bitwarden, 1Password, LastPass, KeePass, Dashlane or any CSV · skips duplicates · export to CSV (asks for the master password) |
| **Money** `/money` | Overview: spent / income / net worth / bills · spending pace vs last month · where it went · budgets · coming-up bills |
| Transactions | Filters, search, inline category picker ("do this for every Venmo?" makes a rule), bulk edit, receipts, tags, transfers, CSV export, billable expenses for clients |
| Budgets | Monthly budget per category, pace line, "usually spend", **Suggest budgets** from the last 3 months |
| Bills & subscriptions | Due dates, autopay, reminders, **Paid** records the expense and moves to the next date · spots recurring charges that look like subscriptions · monthly and yearly totals |
| Accounts | Checking, savings, cards (limit used), loans, cash · net worth chart · **Update balance** to match the bank |
| Goals | Save by hand or track a savings account · how much a month to hit the date |
| Reports | By category, top places, 12 months of income vs spending, spending for clients |
| Import | Bank / card CSV: guesses the columns, cleans names ("SQ *CUP OF JOY 1234 PA" → "Cup Of Joy"), applies rules, flags duplicates |
| **Reminders** `/reminders` | Type it like you'd say it: *"Trash every tue and fri 7pm"*, *"Pay water bill every month on the 15th !high #home"*, *"Renew domain on 3/14"* · live preview · Today / Upcoming / All / Someday / Completed · lists · repeats (every N days/weeks/months/years, chosen weekdays, from due date or from when finished, end date) · snooze · email at the time (or before) · morning email · bills show up here too |
| **Time** `/time` | FreshBooks-style **Clients → Projects → time entries** with a service · timer in the top bar (follows you everywhere) · "jump back in" chips · day / week / month lists |
| Timesheet | Week grid, type 1:30 / 1.5 / 90m into any cell |
| Clients | Unbilled, owed, collected, hours · client page with projects, time by project, invoices, payments chart, effective rate |
| Projects | Billing per project: one hourly rate, by service rate, by team member rate, **flat fee**, or non-billable · hour or dollar budgets with burn bars |
| Invoices | Built from unbilled time + flat fees + billable expenses (one line per service / project / day / entry) · edit lines, discount, tax · email it with a **client link** (no login, shows when they opened it) · print / PDF · payments go into Money as income · overdue, partial, paid, void |
| Reports | Time (group by client / project / service / person / day, CSV) · **by client** (hours, value, invoiced, collected, $/hour) · project profitability · owed to you (aging) · payments |
| Search | `Ctrl K` or `/` searches clients, projects, invoices, reminders, bills, payees, accounts and (when open) the vault |
| **Admin** | Settings (business details for invoices, invoice numbering and terms, tax, rounding, morning email time, server checks, test email) · Users (add people, unlock, turn off 2-step, erase a lost vault) |
| Profile | Details, time zone, theme, hourly rate, weekly goal, email preferences, password, **two-step verification** (any authenticator app) |

Keyboard: `Ctrl K` / `/` search · `R` reminder · `T` time · `E` expense · `G` then `D` `V` `M` `T` `R` `I` `C` to jump · on the vault page `N` new, `F` find.

Add it to the iPad / iPhone Home Screen (Share → Add to Home Screen) and it opens full screen with its own icon.

## How the vault keeps passwords safe

- Each person's vault has a random 256-bit key. Every item is encrypted with it (XChaCha20-Poly1305, libsodium, built into PHP).
- That key is only ever stored **wrapped**: once by a key made from your master password (Argon2id), once by a key made from your
  recovery code. The server never stores the master password or the recovery code, so the database alone, or a backup of it, can't be read.
- Unlocking keeps the key in the session, encrypted with a random key that lives only in an HttpOnly cookie. The session file alone
  isn't enough. It locks after 15 minutes without vault activity (change it in Vault settings), when you press Lock, and on sign out.
- Item names are encrypted too. Only the type, folder and dates are in the clear.
- Your login password and your master password are separate. Forgetting the master password: use the recovery code (shown once
  at setup, you can make a new one in Vault settings). Lose both and the vault can't be opened by anyone. An admin can erase it so
  you can start again.
- Unlock tries are rate limited. Logins lock for 10 minutes after 5 wrong passwords. Two-step verification is on the Profile page.

## Bringing over your old passwords

**From the browser:** sign in, open **Vault**, set your master password, save the recovery code, then **Import & export →
Old Accounts database**. Enter the MySQL details for `bailey_accounts`, check the preview, pick whose accounts (each old login's,
or everyone's) and a folder, and import. It reads `accounts` (name, username, password, url, description → notes) and
`admin_users`. Nothing in the old database changes.

**From the command line:**

```bash
php dev/import-accounts.php --db=user:password@localhost/bailey_accounts --to=you@300interactive.com --owner=1 --folder=Imported
```

It asks for your master password (set up the vault in the browser first). `--owner` is the old `admin_users` id; leave it off for everyone's.

If the old app stored the passwords **encrypted** rather than as plain text, the preview says so. Copy
`settings/legacy-decrypt.php.sample` to `settings/legacy-decrypt.php`, put in how the old app decrypted them, and import again.
Hashed passwords (bcrypt, md5) can't be turned back into passwords.

A read-only MySQL user is safest: `GRANT SELECT ON bailey_accounts.* TO 'home_user'@'localhost';`

## Install (home.300interactive.com)

PHP 8.1+ (pdo_mysql, sodium, mbstring, fileinfo, gd), MySQL 5.7+ / MariaDB 10.3+, Apache with `mod_rewrite`
(plus `headers` and `expires`), `AllowOverride All`, the app as the **DocumentRoot** of its subdomain.

```bash
# 1. Database
sudo mysql -e "CREATE DATABASE home CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'home_user'@'localhost' IDENTIFIED BY 'a-strong-password';
  GRANT ALL ON home.* TO 'home_user'@'localhost';
  GRANT SELECT ON bailey_accounts.* TO 'home_user'@'localhost';"

# 2. Code + settings
cd /var/www && sudo git clone <this repo> home && cd home
sudo cp settings/database.ini.php.sample settings/database.ini.php    # fill in the password
sudo cp settings/app.ini.php.sample settings/app.ini.php              # base_url = https://home.300interactive.com
sudo cp settings/mail.ini.php.sample settings/mail.ini.php            # SMTP details

# 3. Tables, starter categories / lists / services, your login (add --demo for sample data)
sudo php dev/install.php
sudo chown -R www-data:www-data storage

# 4. Cron, as www-data: reminder emails, the morning email, the email queue
sudo crontab -u www-data -e
* * * * * php /var/www/home/dev/cron.php >/dev/null 2>&1
```

Apache vhost (`/etc/apache2/sites-available/home.conf`):

```apache
<VirtualHost *:80>
    ServerName home.300interactive.com
    DocumentRoot /var/www/home
    <Directory /var/www/home>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

```bash
sudo a2enmod rewrite headers expires && sudo a2ensite home && sudo systemctl reload apache2
sudo certbot --apache -d home.300interactive.com
```

**HTTPS is required**: the vault cookie, logins and copy-to-clipboard all need it. Admin → Settings shows a warning if HTTPS, cron or
the storage folders aren't right. Running `php dev/install.php` again is safe (it only adds, and runs new migrations).
`php dev/install.php --reset-password` changes a login password from the server (it doesn't touch anyone's vault).

Try it locally without Apache: `php -S localhost:8000 dev/router.php` (does what `.htaccess` does).

Receipts: `.htaccess` (mod_php) and `.user.ini` (PHP-FPM) allow 20 MB uploads.

## Text messages (Twilio)

Reminder alerts and the morning summary can go out as texts as well as emails. Each person turns them on under **Profile → Text messages**
after confirming their number with a 6-digit code.

Setup:

1. In Twilio, buy a **toll-free number** and submit toll-free verification (free; usually a few days). Toll-free is the cheapest legal
   way to send app texts in the US: no 10DLC brand/campaign fees. Cost is about $2/mo for the number plus roughly $0.008 a text and
   carrier fees.
2. Twilio Console → API keys → **Create API key → Restricted**. Give it only *Messaging → Messages → Create*. Don't use the account
   Auth Token. If this key ever leaks, it can only send texts, and you can delete it without touching anything else.
3. On the server:
   ```bash
   cp settings/sms.ini.php.sample settings/sms.ini.php   # fill in AccountSid, ApiKeySid, ApiKeySecret, From
   php dev/install.php                                    # adds the phone columns and sms_queue table
   php dev/sms-test.php 7245550123                        # sends one text and prints SENT or the Twilio error
   ```
   `Driver = log` writes texts to `storage/logs/sms-*.txt` instead of sending (for testing).

How it stays cheap and safe:

- Calls go out over HTTPS with certificate checks. The site has no Twilio webhook, so there's nothing for anyone to call in.
  Twilio handles STOP/HELP replies itself.
- Numbers must be confirmed by code before anything is texted to them. Codes are stored hashed, expire in 10 minutes, allow 5 tries,
  and you can request 5 per hour.
- `DailyLimit` (default 25) caps texts per person per day. Texts are cut down to plain characters, because a single emoji makes a text
  cost 2–3x, and are capped at 3 segments. Reminders more than 3 hours overdue are not texted, so switching texts on doesn't send a burst.
- `dev/cron.php` sends the queue (`sms_queue`). Failures are retried 3 times and logged.

## How it's built (and what changed from the framework)

```
.htaccess            framework rewrite rules + blocks app/ (except app/Ajax), storage/, settings/, dev/, docs/, views
routes/web.php       the framework router: p/p1/p2 → app/Controllers/<user folder>/<p>.php → app/Controllers/<p>.php → default.php
configuration.php    constants, auth levels, vault key-derivation strength
app/init.php         session (secure cookie), config, Db, easyCRUD, Twig, helpers, autoloader for app/Library
app/Controllers/     index (dashboard) · vault · money · reminders · time · admin · default (login, reset, profile, public invoice /i/<token>)
  parts/             vault-import · time-reports (included by their section, not routable)
app/Ajax/            authentication · vault · money · reminders · time · search   ({result, message} JSON, like the framework)
app/Library/         App · AdminUser (easyCRUD) · Vault · VaultImport · Totp · Finance · Reminder · TimeTracker · Invoice · Mailer · TwigExtras · helpers
app/Modules/         database (Db + easyCRUD + Log), twig, php-mailer
public/views/        bases/ · pages/ (vault, money, reminders, time, admin) · partials/ (modals, invoice paper, search, quick add)
public/mail/         email templates (header/footer partials like the framework)
public/assets/       home.css · home.js (alerts, ajax + CSRF, timer, quick add, search, shortcuts, charts) · vault.js · icons
public/vendor/       AdminKit Pro dist (css/js/fonts, includes ApexCharts + feather), jQuery, Inter font, QR code, EFF word list
storage/             receipts/, logs/, cache/, exports/ (never served directly)
dev/                 install.php · cron.php · import-accounts.php · router.php · database/schema.sql · database/demo.php · database/migrations/
```

Framework changes worth knowing (same as the bug tracker):

- **One shared DB connection** per request, `utf8mb4`, UTC. Errors are logged to `storage/logs` and shown on the router's error page
  instead of `echo` + `die`. `easyCRUD` got `lastId()`. Small helpers `rows()`, `row()`, `val()`, `run()`, `insert()`, `update()` wrap it.
- `App` gained `Setting()`, CSRF (`CsrfField()`, `CheckCsrf()`), `RequireLogin()`, `NotFound()`, `RateLimit()`, `User()`, time-zone
  helpers (DB is UTC, everyone sees their own time zone), `Activity()`.
- Every form and ajax call has a CSRF token, passwords use `password_hash`, reset tokens are stored hashed and expire in an hour,
  receipts are served only through `/money/receipt/<file>` after sign-in.
- `Mailer` can queue (`Mailer::Queue`), and `Driver = log` in `mail.ini.php` writes emails to `storage/logs/mail-*.txt` for testing.
- Charts use ApexCharts, which ships inside AdminKit Pro's `app.js`. Nothing loads from other sites.

Everyone who signs in shares money, reminders and time tracking (a household + one-person agency). Each person's vault is their own.
Members can use everything except Admin.

## Changing the database later

Add `dev/database/migrations/002_what.sql` (or `.php`), update `schema.sql` for fresh installs, and run `php dev/install.php`.

## Git

`.gitignore` leaves out `settings/*.ini.php` (database password, SMTP password), `settings/legacy-decrypt.php`, and everything in `storage/`.
