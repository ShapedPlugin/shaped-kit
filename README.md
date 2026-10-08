# Shaped Kit

Shaped Kit is the free companion plugin that lets AI agents work with ShapedPlugin products through the **Model Context Protocol (MCP)**.

An owner connects their own AI client — Claude Desktop, Claude Code, Cursor, OpenAI Codex, GitHub Copilot, or any HTTP MCP client — to their WordPress site. The agent can then read and, where allowed, change data in the ShapedPlugin products installed there: weather layouts, testimonials, carousels and so on. It does that by calling tools, authorised by a WordPress Application Password the owner created.

Shaped Kit runs no AI model, calls no AI provider, and sends nothing to ShapedPlugin. The owner's AI client does the reasoning; the site only answers tool calls.

---

## Status

| Area | State |
|---|---|
| Bundled WordPress MCP Adapter 0.7.0, plus a loader that never clashes with other copies | **Built and tested** |
| Adapter status report (version, compatibility, which plugin supplied it) | Built (`AdapterBootstrap::status()`); not shown in the UI yet |
| Admin page **ShapedPlugin → AI & MCP** | Placeholder (an empty React mount point) |
| First product module (Location Weather) | Planned, next |
| Real Testimonials module | Planned |
| Dashboard: products, connection snippets, per-product on/off | Planned |
| Audit log of agent actions | Planned |
| Write tools with server-side confirmation | Planned |

Nothing is exposed to AI agents by Shaped Kit on its own today. See [Roadmap](#roadmap).

---

## Requirements

- **WordPress 6.9 or later.** The Abilities API that MCP tools are built on landed in core in 6.9.
- **PHP 7.4 or later.**
- No Composer or npm install is needed to run the plugin. The bundled adapter ships with its own `vendor/`.

---

## How it works

Shaped Kit copies a proven split: **the Kit carries the plumbing, each product carries its own tools.**

```
┌─ Shaped Kit (one per site) ────────────────────────────────────────┐
│ • bundles the official WordPress MCP Adapter (unmodified copy)     │
│ • dashboard: ShapedPlugin → AI & MCP                    (planned)  │
│ • connection help, per-product switches, audit log      (planned)  │
└────────────────────────────────────────────────────────────────────┘
          ▲ a product needs *an* adapter, never Shaped Kit specifically
┌─ each ShapedPlugin product, free or Pro ───────────────────────────┐
│ • registers its own tools (WordPress Abilities API)                │
│ • runs its own MCP endpoint:  /wp-json/{product-slug}/mcp          │
│ • the Pro edition adds its tools to the same endpoint              │
└────────────────────────────────────────────────────────────────────┘
```

Three consequences:

1. **One endpoint per product, whatever the edition.** Upgrading from free to Pro adds tools; the AI client's configuration keeps working.
2. **Products work without the Kit.** Any MCP Adapter on the site will do: the standalone MCP Adapter plugin, or a copy another plugin bundles. The Kit adds convenience, the dashboard and the audit log.
3. **Products never call Kit code.** They talk to the Kit only through WordPress filters, so neither can break the other.

---

## The bundled MCP Adapter

`libs/mcp-adapter/` is the official [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) **0.7.0** release, byte-for-byte unmodified (GPL-2.0-or-later). Kit-specific behaviour lives only in `src/Mcp/AdapterBootstrap.php`.

### When the Kit loads it

Many plugins ship their own copy of the adapter, and two copies of its classes in one request is a fatal error. So the Kit loads its copy **only when nothing else will**. It decides at plugin include time, and loads only if all of these hold:

| Check | Why |
|---|---|
| `SHAPED_KIT_DISABLE_BUNDLED_MCP_ADAPTER` is not set | Escape hatch for hosts and site owners |
| WordPress is 6.9+ (read from `$wp_version`, pre-releases normalised) | Adapter 0.6+ supports only core's Abilities API |
| No adapter class exists yet, including one another plugin has registered but not started | Another plugin's copy always wins |
| No active plugin **is** an adapter, in any folder name | It would load after the Kit and redeclare the classes |
| This request is not activating an adapter plugin, from wp-admin (single or bulk) or WP-CLI (`plugin activate`, `--all`, `toggle`, `install --activate`) | WordPress includes the plugin being activated after everything else |

It loads the adapter through the adapter's own plugin entry file, not as a library. That defines `WP_MCP_VERSION` and keeps adapter 0.7.0 from flagging itself as a deprecated bundled dependency.

Loading at include time rather than on `plugins_loaded` is deliberate. By `plugins_loaded`, a plugin that bundles an *unstarted* adapter library has registered its autoloader, and the adapter's own duplicate check would step aside for a copy that never runs.

### Verified behaviour

Probed on a real WordPress 7.1 site:

| Site state | Adapter in use | Result |
|---|---|---|
| Rank Math active (bundles 0.4.1 and starts it while loading) | Rank Math's 0.4.1 | Kit stays out; no notices |
| No other adapter | Kit's 0.7.0 | Loaded; no notices |
| WooCommerce active (bundles 0.1.0, loads after the Kit) | Kit's 0.7.0 | Loaded; WooCommerce's autoloader prefers the newest copy |
| Standalone adapter active, in a folder sorting after `shaped-kit` | The standalone | Kit stays out; previously a fatal on every request |
| Activating the standalone adapter (wp-admin or WP-CLI) | The standalone | Activates cleanly; previously "Cannot redeclare class" |

The oldest adapter verified to have everything products rely on is **0.4.1** (`AdapterBootstrap::MIN_COMPATIBLE_VERSION`).

### The adapter's shared default server

The adapter normally also starts a shared "default" MCP server with three generic abilities (discover, get info, execute), open to any logged-in user with the `read` capability.

**When Shaped Kit is the supplier, that server stays off.** Installing the Kit must enable nothing by itself. When another plugin supplied the adapter, the Kit leaves its default server alone.

---

## Configuration

| Setting | Type | Default | Effect |
|---|---|---|---|
| `SHAPED_KIT_DISABLE_BUNDLED_MCP_ADAPTER` | constant (`wp-config.php`) | not set | `true` stops the Kit from ever loading its bundled adapter |
| `shaped_kit/allow_default_mcp_server` | filter | `false` | `true` lets the Kit's bundled adapter start its shared default server |

```php
// wp-config.php — never load Shaped Kit's bundled adapter.
define( 'SHAPED_KIT_DISABLE_BUNDLED_MCP_ADAPTER', true );
```

```php
// Allow the shared default MCP server when Shaped Kit supplies the adapter.
add_filter( 'shaped_kit/allow_default_mcp_server', '__return_true' );
```

---

## Adding MCP to a ShapedPlugin product

> **Planned contract.** No product implements this yet. Location Weather is the first, and its module will become the template the other products copy. Treat the details as the target design.

### Conventions

| Thing | Pattern | Example |
|---|---|---|
| Ability name | `{slug}/{verb}-{noun}` | `location-weather/list-weather-templates` |
| Ability category | `{slug}` | `location-weather` |
| MCP endpoint | `/wp-json/{slug}/mcp` | `/wp-json/location-weather/mcp` |
| Pro tools register on | action `{prefix}/mcp_loaded` | `splw/mcp_loaded` |
| Pro tool names join via | filter `{prefix}/mcp_ability_names` | `splw/mcp_ability_names` |
| On/off switch | option `{prefix}_mcp_enabled`, **default `'no'`** | `splw_mcp_enabled` |
| Listed in the Kit dashboard via | filter `shaped_kit/products` | — |

The slug, endpoint and on/off option are **edition-neutral** (no `-pro`), so an upgrade never breaks a connected client.

### Shape of a product module

```php
// 1. Gate: silent unless WordPress 6.9+ and the owner switched MCP on.
add_action( 'init', function () {
	if ( ! function_exists( 'wp_register_ability' ) || 'yes' !== get_option( 'splw_mcp_enabled', 'no' ) ) {
		return;
	}
	( new MCPInit() )->init();
}, 5 );

// 2. Register abilities, then let Pro add its own.
add_action( 'wp_abilities_api_init', function () {
	AbilitiesRegistrar::register(); // each with permission_callback, annotations,
	                                // meta: show_in_rest false, mcp.public false
	do_action( 'splw/mcp_loaded' );
} );

// 3. A dedicated MCP server for this product.
add_action( 'mcp_adapter_init', function ( $adapter ) {
	$names = apply_filters( 'splw/mcp_ability_names', AbilitiesRegistrar::names() );

	$adapter->create_server(
		'location-weather', 'location-weather', 'mcp',
		'Location Weather MCP Server', 'AI agent tools for Location Weather.', SPLW_VERSION,
		array( '\WP\MCP\Transport\HttpTransport' ),
		'\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler',
		null,
		$names,
		array(),
		array(),
		$transport_permission_callback // the on/off switch and base capability, on every request
	);
} );
```

A Pro-only tool joins the same endpoint with two hooks:

```php
add_action( 'splw/mcp_loaded', function () {
	wp_register_ability( 'location-weather/get-cloud-status', array( /* … */ ) );
} );
add_filter( 'splw/mcp_ability_names', function ( $names ) {
	$names[] = 'location-weather/get-cloud-status';
	return $names;
} );
```

### Rules every tool follows

- **Permissions:** a `permission_callback` mapped to the product's own capability. Never a blanket `manage_options` on read tools, never `__return_true`.
- **Annotations:** `readonly`, `destructive`, `openWorldHint` declared explicitly, plus `bulk` for multi-item writes.
- **Errors:** every tool runs inside a try/catch. The agent gets a generic message with an `error_id`; the exception detail goes only to the server log.
- **Short descriptions** (about 30 tokens or fewer), to keep the tool list cheap for the model.
- **A `{slug}/get-context` tool** in every product, so the agent can orient itself before anything else.
- **Endpoint only:** abilities use `show_in_rest: false` and `mcp.public: false`. They are reachable only through the product's own endpoint, not core's Abilities REST API or the adapter's shared default server.
- **Adapter version:** a product creates its server only if the loaded adapter is 0.4.1 or later, and otherwise says why in its settings.

---

## Security model

| Layer | Behaviour | State |
|---|---|---|
| Authentication | WordPress Application Passwords; one per AI client, individually revocable | Core WordPress |
| Off by default | Each product's MCP switch defaults to off; installing the Kit enables nothing, including the adapter's default server | Kit: built. Products: planned |
| Transport check | Every request to a product endpoint checks the switch and a base capability, replacing the adapter's default `read` | Planned |
| Per-tool permission | Each tool's own `permission_callback` | Planned |
| Destructive confirmation | A destructive tool first returns a preview and a single-use token bound to the user, the tool and the exact arguments. Only a second call with that token makes the change. | Planned |
| Audit log | Every agent call recorded (who, which tool, outcome, argument names, never values); 90-day retention | Planned |

---

## Development

### Layout

```
shaped-kit.php                  Plugin header, class autoloader, adapter load, admin page
src/Mcp/AdapterBootstrap.php    When to load the bundled adapter; status() for the dashboard
src/Admin/DashboardPage.php     ShapedPlugin → AI & MCP (React mount point)
tests/unit/                     Dependency-free tests (no WordPress, no packages)
libs/mcp-adapter/               Official WordPress MCP Adapter 0.7.0, unmodified
```

### Tests

The tests use no PHPUnit and no third-party packages. Run them with plain PHP:

```bash
php tests/unit/AdapterBootstrapTest.php   # the loader's decisions (44 cases)
php tests/unit/AdapterLoadTest.php        # whole-loader scenarios (15), each in a fresh PHP process
```

`AdapterLoadTest.php` builds a throwaway site tree in the system temp directory, fakes the handful of WordPress functions the loader touches, and runs every scenario in its own process, because classes and constants cannot be undefined. It cleans up after itself. Both scripts exit non-zero on failure.

### Upgrading the bundled adapter

1. Download `mcp-adapter.zip` from the official [releases](https://github.com/WordPress/mcp-adapter/releases).
2. Replace `libs/mcp-adapter/` **as a whole**. Never edit files inside it.
3. Confirm the copy is unmodified: `diff -rq <unzipped>/mcp-adapter libs/mcp-adapter` prints nothing.
4. Check `create_server()`'s signature and the `mcp_adapter_create_default_server` filter still exist.
5. Run both test scripts, then probe a site with and without another adapter present.

### Coding standards

- **PHP 7.4 compatible:** no `match`, nullsafe `?->`, `str_starts_with()`, constructor promotion or `readonly`.
- WordPress Coding Standards: escape output, sanitise input, `$wpdb->prepare()` always.
- Every new endpoint is a REST route with an explicit `permission_callback`; no new `admin-ajax` actions.
- Admin screens are React mount points; PHP renders no UI markup.

---

## Roadmap

| Step | Deliverable |
|---|---|
| ✅ 1 | Kit skeleton, bundled adapter, conflict-proof loader, tests |
| 2 | Location Weather (free) — read-only tools; becomes the module template |
| 3 | Location Weather Pro — a Pro-only tool joining the same endpoint |
| 4 | Real Testimonials (free + Pro) |
| 5 | Dashboard — products, adapter status, connection snippets, per-product switches |
| 6 | Audit log |
| 7 | First write tools, with server-side confirmation |
| 8 | Other ShapedPlugin products, from the module template |

---

## License

Shaped Kit is licensed under **GPLv2 or later**.

The bundled WordPress MCP Adapter (`libs/mcp-adapter/`) is licensed under **GPL-2.0-or-later** by its contributors; see `libs/mcp-adapter/LICENSE.md`.
