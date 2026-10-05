# drupalator

A PHP CLI tool that produces per-customer Drupal security update reports across a fleet of sites you administer. For each customer it enumerates installed modules through `drush pm:list` (using your local drush aliases or a Drush wrapper command), queries the drupal.org release-history XML API per project, cross-references the [CISA Known Exploited Vulnerabilities catalog](https://www.cisa.gov/known-exploited-vulnerabilities-catalog) to flag actively-exploited CVEs, and emits a printable HTML report plus a JSONC summary. It can also draft a stakeholder email with the report attached.

## Requirements
- PHP 8.3+
- `drush` on `PATH`. If you use a Drush wrapper (e.g. `ddev`, `lando`), you can set it as a `DRUSH_PREFIX` in your `.env` file.
- Working drush site aliases for every site listed in `config/sites.json` — `drush @your.alias status` must succeed from the shell you run `drupalator` in
- Network access to `updates.drupal.org`, `www.cisa.gov`, and (optionally) `services.nvd.nist.gov`
- Optional: `msmtp`, a local `sendmail`, or `gws` (Google Workspace CLI) if you want automated email drafting rather than `.eml` files on disk

## Install

```
git clone <repo-url> drupalator
cd drupalator
composer install     # runs autoloader dump; no runtime deps yet
```

There are no external PHP packages to fetch. Composer only wires up the PSR-4 autoloader for the `Drupalator\` namespace under `lib/`.

## Configure

Two files under `config/`. Both are gitignored — commit only the `*.example.*` variants.

### 1. `config/.env`

Copy `config/.env.example` to `config/.env` and edit. Every recognized key:

| Key | Required | Purpose |
|---|---|---|
| `DRUPALATOR_WORKING_DIR` | yes | Base directory for tool output. Reports land under `<DRUPALATOR_WORKING_DIR>/reports/<customer>/` unless `DRUPALATOR_REPORTS_DIR` overrides. |
| `DRUPALATOR_REPORTS_DIR` | no | Override the reports output directory. Defaults to `$DRUPALATOR_WORKING_DIR/reports`. |
| `DRUPALATOR_CACHE_DIR` | no | Where drupal.org XML + KEV JSON get cached between runs. Default `/tmp/drupal_update_cache`. 24-hour TTL with a Wednesday-UTC purge aligned to Drupal's security release cadence. |
| `DRUPALATOR_SITES_FILE` | no | Path to `sites.json`. Relative paths resolve against the repo root. Default `config/sites.json`. |
| `DRUPALATOR_EMAIL_BACKEND` | no | One of `stdout-eml` (default), `sendmail`, `msmtp`, `gws`. See the Email backends section below. |
| `DRUPALATOR_EMAIL_SIGNATURE` | no | Signature line appended to drafted email bodies. Default `Security Team`. |
| `DRUPALATOR_EMAIL_TO` | for `sendmail`/`msmtp` | Recipient address. |
| `DRUPALATOR_EMAIL_FROM` | no | Optional `From:` address for `sendmail`/`msmtp` backends. |
| `DRUPALATOR_SENDMAIL_BIN` | no | Override path to the `sendmail` binary. |
| `DRUPALATOR_MSMTP_BIN` | no | Override path to the `msmtp` binary. |
| `DRUPALATOR_MSMTP_ACCOUNT` | no | msmtp account/profile name to use (from your `~/.msmtprc`). |

Environment variables exported in your shell win over values in `config/.env`.

### 2. `config/sites.json`

Copy `config/sites.example.json` to `config/sites.json` and replace with your real customers.

```json
{
  "customers": {
    "<customer-key>": {
      "name": "Human-readable name",
      "sites": {
        "<site-key>": {
          "name": "Site display name",
          "url": "https://example.com",
          "drush_alias": "@example.prod",
          "uri": "example.com"
        }
      }
    }
  }
}
```

Field notes:

- `<customer-key>` — the string you pass to `drupalator generate <customer>`. Keep it short and shell-safe.
- `drush_alias` — must resolve locally. `drush @example.prod status` should print site info without prompting for anything.
- `uri` — optional. This is needed for multisite installs, including Acquia Cloud Site Factory (ACSF), where one drush alias serves several domains and you need to disambiguate.

Schema errors fail fast at load time with a clear message pointing at the offending path.

## Usage

`drupalator help` prints:

```
drupalator <command> [args]

Commands:
  generate <customer|all>     Generate HTML + JSONC reports for a customer (or all)
  combine [options]           Combine JSONC reports into one JSON
                                --date=YYYY-MM-DD
                                --customers=k1,k2
                                --output=path
  email <customer>            Draft/send email for a customer's latest report
  check <customer>            Run update checker only (no report, no email)
  cache <subcommand>          Cache management
                                cache clear      Delete all cached items
                                cache stats      Show cache dir + item count
  list                        List configured customers + site counts
  help                        Show this help
```

Typical flows:

```
# List configured customers and their site counts
./bin/drupalator list

# Generate for one customer
./bin/drupalator generate acme

# Generate for every customer, plus a combined multi-customer JSONC
./bin/drupalator generate all

# Combine already-generated .jsonc files for downstream tools
./bin/drupalator combine --date=2026-07-21 --output=/tmp/all-reports.json

# Run the update checker without producing a report or email
./bin/drupalator check acme

# Draft an email with the latest report attached
./bin/drupalator email acme

# Cache management
./bin/drupalator cache stats
./bin/drupalator cache clear
```

If composer's global `bin` dir is on your `PATH`, you can drop the `./bin/` prefix and just run `drupalator ...`. Otherwise invoke it as shown above.

## Field Notes

### `d8r` / `dr8r` Shortcuts

For convenience, two equivalent shortcuts streamline generating reports for a customer. Instead of typing `./bin/drupalator generate customer_name`, you can simply use `d8r customer_name` (bash wrapper) or `dr8r customer_name` (PHP wrapper, also installed via composer's `bin`).

To enable the shortcuts:
1.  Ensure the `bin/d8r` and `bin/dr8r` scripts exist and are executable in your project.
2.  Add the absolute path to your project's `bin/` directory to your shell's `PATH` environment variable. For example, add `export PATH="/path/to/your/drupalator/bin:$PATH"` to your `~/.bashrc` or `~/.zshrc` file and then `source` it.

## Email backends

| Backend | Requires | Behavior |
|---|---|---|
| `stdout-eml` (default) | Nothing | Writes an RFC 2822 `.eml` file under `$DRUPALATOR_REPORTS_DIR/emails/`. Open with any mail client to review, forward, or send. |
| `sendmail` | `/usr/sbin/sendmail` (or `DRUPALATOR_SENDMAIL_BIN`) plus `DRUPALATOR_EMAIL_TO` | Pipes the drafted message to the local MTA. |
| `msmtp` | `msmtp` on `PATH` (or `DRUPALATOR_MSMTP_BIN`) plus `DRUPALATOR_EMAIL_TO` | Great for sending through Gmail with an app password; honors `DRUPALATOR_MSMTP_ACCOUNT`. |
| `gws` | `gws` (Google Workspace CLI) | Creates a Gmail draft via the API. Uncommon dependency — most users will want one of the others. |

## Cache behavior

- Drupal.org update XML is cached per project in `$DRUPALATOR_CACHE_DIR/*.cache` with a 24-hour TTL.
- Wednesday-UTC purge: any cache entry written before the most recent Wednesday midnight UTC is treated as stale. Drupal.org publishes security releases on Wednesdays, so a run on Wednesday always fetches fresh data.
- CISA KEV catalog is cached at `$DRUPALATOR_CACHE_DIR/cisa_kev.json` with the same 24-hour TTL and Wednesday purge rule.
- NVD CPE range lookups (used to filter KEV entries to your exact Drupal core version) are cached under `$DRUPALATOR_CACHE_DIR/nvd_cpe/` for 7 days. NVD is aggressively rate-limited; the first cold run against a new core version may take several minutes.

Use `drupalator cache stats` to see the cache directory and item count, and `drupalator cache clear` to wipe it and force a fresh fetch.

## Output

For each customer run:

- `<DRUPALATOR_REPORTS_DIR>/<customer>/<customer>-security-report-<YYYY-MM-DD>.html` — printable HTML report suitable for sharing with stakeholders.
- The same path with a `.jsonc` extension — structured JSONC summary suitable for dashboards or downstream automation. Inline comments explain each field.

`drupalator generate all` additionally writes `<DRUPALATOR_REPORTS_DIR>/all-security-report-<YYYY-MM-DD>.jsonc`, a combined multi-customer view.

## Development

- No test suite yet.
- Autoloader: `Drupalator\` maps to `lib/` (PSR-4). Run `composer dump-autoload` after adding new classes.
- Adding a new email backend: implement `Drupalator\EmailBackend` (see `lib/EmailBackend.php`), register the new class in `lib/EmailBackend/Factory.php`, and add its slug to `Drupalator\Config::VALID_EMAIL_BACKENDS` so misconfiguration fails fast at load time.

## License

MIT. See `LICENSE`.
