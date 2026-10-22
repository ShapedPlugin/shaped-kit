# Shaped Kit

**Unified AI & MCP Workspace for ShapedPlugin Products**

[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net/)
[![MCP Adapter](https://img.shields.io/badge/MCP%20Adapter-v0.7.0-green.svg)](https://github.com/WordPress/mcp-adapter)
[![License: GPLv2+](https://img.shields.io/badge/License-GPL%20v2%2B-orange.svg)](LICENSE)

Shaped Kit is the free companion plugin for ShapedPlugin products. It lets AI clients such as Claude, Cursor and Copilot work with ShapedPlugin products on a WordPress site through the Model Context Protocol (MCP).

The Kit bundles the official WordPress MCP Adapter and hosts the **AI & MCP** dashboard in WordPress Admin. From there it switches each product's MCP server on or off, sets up AI clients, and keeps an audit log of every tool call. Each product serves its own tools at its own address. The Kit runs no AI model and has no chat: your AI client does the thinking, and the site answers tool calls.

> **Status:** developer beta. The latest release is [0.2.0-beta.7](https://github.com/ShapedPlugin/shaped-kit/releases). For local testing, not for production sites.

---

## Highlights

- **Off by default.** Installing the Kit turns on no MCP server and no endpoint. Each product stays off until the site owner switches it on.
- **One address per product.** Every product has its own MCP endpoint, `/wp-json/{slug}/mcp`. Free and Pro editions share it, so upgrading changes nothing in the AI client.
- **Connect in minutes.** Create an Application Password, copy a ready-made snippet for your client, then press **Test connection** and **Try a tool**.
- **Confirm before delete.** Destructive tools need a second call with a single-use token (WriteGuard), enforced on the server.
- **Audit log, names only.** Every tool call is logged with the names of its arguments, never their values.
- **Opt-in toolsets.** Owners turn tool groups on only when they need them, so the AI client's context stays small.
- **Build with AI.** AI clients can build and insert product blocks and ready designs, checked on the server.
- **Images for your agent.** Find an image in the media library, or import one from the web when the owner allows it.

---

## Supported products

| Product | Endpoint | Free | Pro | Toolsets |
|---|---|---|---|---|
| **Real Testimonials** | `/wp-json/real-testimonials/mcp` | 18 tools | 22 tools | Core, Management, Permanent Deletion, Block Builder, Bulk Import, Media Import |
| **WP Carousel** | `/wp-json/wp-carousel/mcp` | 17 tools | 17 tools | Core, Management, Permanent Deletion, Block Builder, Bulk Import, Media Import |
| **Location Weather** | `/wp-json/location-weather/mcp` | 10 tools | 11 tools | None: every tool is on once MCP is on |

- Real Testimonials also serves the `build-testimonial-block` MCP prompt, and WP Carousel the `build-carousel` prompt.
- By default only **Core** and **Management** are on. **Core** cannot be turned off. The other toolsets are turned on in each product's own **MCP for AI Agents** settings tab.
- The MCP modules ship with the product plugins, not with the Kit. They are on the products' `feat/mcp-module` branches and are shared with testers separately. The Kit's tool list (`assets/admin/toolsCatalog.js`) describes every tool with sample prompts.

---

## Features

### 1. Connect an AI client

- **Application Passwords:** create one per client with one click. It is shown once (`Cache-Control: no-store`), and WordPress keeps only a hash.
- **Kept for 8 hours in this browser:** so a page refresh does not lose it. It never goes to the server, and **Clear** removes it at once.
- **Revoke all Shaped Kit passwords:** one button removes every password the Kit made. Passwords you made yourself are never touched.
- **Client snippets** for Claude Code, Claude Desktop, Cursor, Codex, GitHub Copilot, Gemini CLI and any other client:
  - Claude Code, Claude Desktop and Gemini CLI connect through the `@automattic/mcp-wordpress-remote` bridge (`npx`).
  - Cursor and Copilot connect straight to the endpoint over HTTP with an `Authorization` header.
  - Codex and the generic tab list the URL and header; the generic tab also prints a `curl` check.
- **Test connection and Try a tool:** the browser signs in the way a client would and runs a real tool call.
- **Deep link:** each product's settings tab links straight to the Kit's connect drawer for that product.

### 2. Opt-in toolsets

Every tool a server lists is sent to the AI model on every turn, so unused tools cost tokens. Toolsets let the owner keep the read-only tools on and turn the rest on only when needed. A toolset that is off is removed from the server's tool list completely.

### 3. WriteGuard: confirm before delete

- A destructive call first returns `confirmation_required`, a preview of what will change, and a token.
- The token works once and expires after 5 minutes. It is bound to the user, the exact arguments and the current state of the record.
- Changed arguments, a changed record, another user or a reused token are refused. Two parallel confirmations cannot both run.
- Arguments that cannot be encoded safely are refused.
- An optional idempotency key makes a retry after a timeout return the first result.

### 4. Build with AI

The AI client builds blocks without opening the block editor:

- `get-block-catalog`: the block's allowed settings and design rules.
- `validate-block-attributes`: checks a set of settings and reports what was dropped.
- `insert-block`: saves the block. A new draft is saved in one call; a change to an existing page needs a WriteGuard confirmation with a preview.
- `list-ready-patterns` and `insert-ready-pattern`: ready-made designs. On a Free edition, Pro designs are listed as "needs Pro" and refused on insert.

The AI only ever sends settings, never HTML. The site builds the block itself and checks every setting against the block's own list.

### 5. MCP prompts

Products can serve MCP prompts (`prompts/list`, `prompts/get`) that guide the AI through a task step by step, such as `build-testimonial-block`. A prompt is listed only while its toolset is on.

### 6. Images for your AI agent

- `find-media` (on with MCP): searches the media library by title, file name or alt text. Read only.
- `import-image` (Media Import toolset, off by default): downloads up to 5 https images into the media library.
- Imports are guarded: private network addresses are refused, redirects are checked, size and time are capped, certificates are always checked, and the file must really be an image. The same address imported twice reuses the first image.

### 7. Audit log

- One row per tool call in `{$wpdb->prefix}shaped_kit_mcp_log`: time, user, Application Password name, product, tool, outcome (`ok`, `error`, `denied`, `confirmation_required`, `confirmed`), error id, duration, and the **names** of the arguments.
- Argument values, customer text, API keys and licence keys are never stored.
- Rows older than 90 days are removed daily, with a cap of 100,000 rows.
- Products report to the log through an action, so the log shows what products report. It is not tamper-proof.

### 8. MCP adapter and safe loading

- Bundles the official [`WordPress/mcp-adapter`](https://github.com/WordPress/mcp-adapter), pinned to 0.7.0, as an unmodified copy.
- Steps aside when another plugin already supplies an adapter, so two copies never load.
- The adapter's shared default server stays off.
- WordPress 6.9 or newer is required for MCP. On older versions the Kit skips MCP without errors.
- Stray PHP notices or plugin output are removed from MCP responses, so the JSON stays valid.
- When a product's Free and Pro editions are both active, only one serves the module.

### 9. Products & Store

The **Products & Store** tab lists ShapedPlugin plugins with install, activate and update state, and installs Free and Pro editions.

- The catalog is signed (Ed25519) and checked against pinned keys. Each zip's SHA-256 is checked before it is unpacked, and downloads come only from allowed hosts.
- Licence keys are sent in the request body, never stored and never logged.
- Betas are one-time installs that never enter the WordPress update list, with no downgrade.
- **Off until the release pipeline is live.** The tab, the Store routes and the update checks are hidden or answer 503. On a site whose environment type is `local` the tab is on as a developer preview, and the `shaped_kit/store_live` filter can turn it on or off for one site.

### 10. Product module template

`templates/product-module/` is the drop-in MCP module every product copies (adoption guide: `templates/product-module/README.md`). Free and Pro run the same code; only their data differs.

Its shared safety files (`Support/`: Audit, Pagination, Secret_Mask, Token_Budget, Url, WriteGuard; and `Media/`) are kept equal in every product:

- `php tools/sync-product-module.php <product>` reports changes, `--diff` shows them and `--write` writes them into the Free and Pro copies together.
- The `SharedFileCopyTest` drift test fails on any difference and names the command that fixes it.
- A shared package, `wp-mcp-engine`, is planned for when a fourth product joins.

---

## Dashboard

The dashboard is under **ShapedPlugin → AI & MCP**, with four tabs:

1. **AI & MCP:** overview strip (MCP adapter, products on, tools available), one card per product with its endpoint and switch, the **View tools & sample prompts** drawer, the **Connect a client** drawer, **Test connection** and **Try a tool**.
2. **Products & Store:** see section 9. Shown only while the Store is on.
3. **Diagnostics:** the Connection Doctor checks the environment type, HTTPS, Application Passwords and whether the `Authorization` header reaches PHP.
4. **Activity Log:** the audit log, with filters for product, user and outcome.

---

## Installation

### Requirements

- WordPress 6.9 or newer.
- PHP 7.4 or newer. The Store's signature check uses the PHP `sodium` extension; without it, the built-in catalog is used.
- At least one ShapedPlugin product with an MCP module (Real Testimonials, WP Carousel or Location Weather).
- An MCP client, such as Claude Code, Claude Desktop, Cursor, Codex, GitHub Copilot or Gemini CLI.

### Install a release

1. Download `shaped-kit-<version>.zip` from the [Releases page](https://github.com/ShapedPlugin/shaped-kit/releases). Check it against its `.sha256` file if you like (`shasum -a 256 -c`).
2. **Do not use "Source code (zip)" or "Code → Download ZIP".** They leave out the built dashboard (`build/` is not in git), so the page would show no dashboard.
3. In WordPress, go to **Plugins → Add New → Upload Plugin**, install the zip and activate it.
4. Open **ShapedPlugin → AI & MCP**, switch a product on and connect your client.

To update, upload the next release zip over the installed one.

### Run from source

Build the dashboard once after cloning: `npm ci && npm run build`. To make an installable zip from your checkout, run `npm run package` (it builds, zips and verifies the package).

---

## Development

The Kit uses its own isolated toolchains, never global binaries.

```bash
# Install the PHPUnit and PHPCS toolchains (first run)
composer tools:install

# Everything: loader scripts, unit, integration, and integration on a real multisite
composer test

# Single suites
composer test:scripts      # dependency-free loader tests, one PHP process per scenario
composer test:unit         # PHPUnit without WordPress
composer test:integration  # PHPUnit inside real WordPress
composer test:multisite    # the integration suite on a real multisite

# What CI runs (needs tools/install-wp-tests.sh first)
composer test:integration:ci
composer test:multisite:ci

# Product tools on the wire, through every adapter copy found
composer test:adapter
SHAPED_KIT_PRODUCTS=template composer test:adapter   # the template alone, no product plugin needed

# Dashboard
npm run test:js            # Jest
npm run build              # compile the admin bundle

# Code standards (WordPress Coding Standards + PHPCompatibilityWP)
composer lint
composer lint:fix

# Release package
npm run package            # build, zip and verify
npm run package:verify     # check the zip against the allow/denylist

# Shared product files
php tools/sync-product-module.php <product> --diff
```

Some integration tests load the sibling product plugins. They run on a developer machine; CI runs the suite without them.

---

## Privacy

- **No telemetry.** The Kit sends no usage data anywhere.
- **MCP stays on your site.** Tool calls go between your WordPress site and your own AI client.
- **Store calls only when the Store is on.** Then the Kit fetches the signed catalog from `api.shapedplugin.com` and checks for its own updates. The request adds no site data of its own; WordPress adds its usual User-Agent, which includes the site address. While the Store is off, nothing is fetched.
- **Application Passwords:** WordPress stores only a hash. The dashboard keeps the plain text in your browser for up to 8 hours, never on the server, and removes it on expiry, on **Clear** and when you revoke.
- **Audit log:** argument names only, never values.

---

## License

Shaped Kit is licensed under **GPLv2 or later**; see [`LICENSE`](LICENSE).
The bundled WordPress MCP Adapter is copyright its contributors, under **GPL-2.0-or-later**.
