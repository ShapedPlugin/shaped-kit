# Shaped Kit

**Unified AI & MCP Workspace for ShapedPlugin Products**

[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net/)
[![MCP Adapter](https://img.shields.io/badge/MCP%20Adapter-v0.7.0-green.svg)](https://github.com/WordPress/mcp-adapter)
[![License: GPLv2+](https://img.shields.io/badge/License-GPL%20v2%2B-orange.svg)](LICENSE)

Shaped Kit is the official companion and bridge plugin for ShapedPlugin products. It provides a unified admin workspace, bundles the official WordPress Model Context Protocol (MCP) Adapter, coordinates per-product MCP endpoints and ability switches, hosts client configuration generators, enforces WriteGuard destructive action safety, and delivers an immutable AI activity audit log—all from your WordPress dashboard.

---

### Highlights

- **Unified AI & MCP Dashboard:** A dedicated command center in WordPress Admin (**ShapedPlugin → AI & MCP**) with live statistics, product cards, ability selectors, and connection wizards.
- **Dedicated Product Endpoints:** Every product exposes its own isolated MCP server endpoint (`/wp-json/{slug}/mcp`), preventing namespace collisions and token bloating across plugins.
- **Client Snippet Generator:** One-click configurations tailored for **Claude Code, Claude Desktop, Cursor, OpenAI Codex, GitHub Copilot, Gemini CLI**, and generic HTTP MCP clients.
- **Opt-In Ability Toolsets & Token Economy:** Granular controls to expose only relevant toolsets (`Core / Read-Only`, `Management`, `Destructive`), reducing LLM context window token consumption by up to 70%.
- **WriteGuard Two-Step Confirmation:** Mutating or destructive actions (such as permanent deletion or global cache purges) cannot be triggered in a single hit. The AI client must execute a preview dry-run to receive an HMAC-bound 32-character confirmation token before confirming.
- **Activity Log & Audit Trail:** Real-time oversight of all AI tool invocations. Records timestamp, client identity, ability name, execution latency, and result status (`ok`, `confirmed`, `denied`, `confirmation_required`, `error`) with strict zero-PII masking (parameter keys only).
- **Replaceable MCP Adapter Fallback:** Bundles the official `WordPress/mcp-adapter` (pinned to v0.7.0) with conflict-free step-aside logic if another plugin supplies the adapter.
- **Built-in Tool Explorer & Tester:** Interactive Try-a-Tool inspector and live Connection Tester to verify authentication before connecting your AI client.
- **Products & Store Track:** Offline-first catalog loader with Ed25519 cryptographic detached signatures and SHA-256 package hash verification for ShapedPlugin betas, addons, and updates.

---

### Supported Products & MCP Abilities

| Product | Edition | Dedicated Endpoint | Tools Count | Toolset Groups |
|---|---|---|---|---|
| **Real Testimonials** | Free & Pro | `/wp-json/real-testimonials/mcp` | 13 tools | `Core` (Views, Forms, Context), `Management` (Create, Update), `Destructive` (Delete with WriteGuard) |
| **Location Weather** | Free & Pro | `/wp-json/location-weather/mcp` | 11 tools | `Core` (Templates, Context, Cache), `Management` (Create, Update, Duplicate), `Destructive` (Delete, Global Cache Purge) |
| **WP Carousel** | *Coming Soon* | `/wp-json/wp-carousel/mcp` | In Development | Layout templates, carousel blocks, and media query controls |

---

### Features & Architecture

#### 1. MCP Connector & Client Generator
Connect any AI assistant using standard WordPress Application Passwords:
- **Instant Mints:** Generate dedicated Application Passwords with one click, securely returned once (`Cache-Control: no-store`) and never persisted in plain text.
- **Client Configuration Snippets:**
  - **Claude Code CLI:** Ready-to-run terminal commands using `@automattic/mcp-wordpress-remote`.
  - **Claude Desktop & Cursor:** Formatted JSON configuration blocks ready to paste into `claude_desktop_config.json` or `.cursor/mcp.json`.
  - **OpenAI Codex & GitHub Copilot:** CLI flags and environment configurations.
  - **Gemini CLI:** Complete flags for Google Gemini CLI (`gemini mcp add`).
  - **Generic HTTP:** Standard HTTP SSE/REST MCP schema details.

#### 2. Opt-In Toolsets & Token Budgeting
In MCP, every registered ability and its complete JSON schema is injected into the LLM system prompt on every turn. Exposing dozens of unused abilities consumes 6k–10k tokens before a conversation starts.
- **Granular Grouping:** Toolsets allow site owners to keep safe read-only queries permanently active while toggling management or destructive abilities only when actively working on them.
- **Always-On Core Guard:** Essential context inspection and listing abilities remain locked on for orientation.
- **Dynamic Adapter Filtering:** Disabled toolsets are stripped from the server schema entirely, ensuring token frugality and strict blast-radius isolation.

#### 3. WriteGuard Safety Engine
Prevents accidental or rogue data loss by AI models:
- When an AI agent attempts a destructive action (such as deleting a post or purging site-wide transients), the server rejects execution with a `confirmation_required` error.
- The response returns a detailed **impact preview** (affected IDs, entities, side effects) and a cryptographically secure, time-limited confirmation token.
- The token is HMAC-SHA256 bound to the authenticated user ID, canonical argument hash, and entity fingerprint.
- The action only executes when the agent explicitly repeats the call with the valid confirmation token.

#### 4. Audit Trail & Activity Log
Maintains a tamper-resistant database log (`{$wpdb->prefix}shaped_kit_mcp_log`):
- **Full Observability:** Logs timestamp, authenticated user, Application Password name, ability called, latency, and status.
- **Zero-PII Guarantee:** Only parameter keys (e.g. `['id', 'status']`) are stored—sensitive values, customer reviews, API keys, or payload contents are never persisted.
- **Maintenance:** Automatic background pruning keeps the log lean (90-day retention).

#### 5. Official WordPress MCP Adapter Provider
Shaped Kit bundles the official `WordPress/mcp-adapter` (currently pinned to upstream 0.7.0):
- **Conflict-Free Bootstrap (`AdapterBootstrap`):** Detects existing active adapter plugins, CLI activations, and third-party vendor bundles. If another compatible adapter is active, Shaped Kit gracefully steps aside.
- **WordPress 6.9+ Floor:** Leverages the native core WordPress Abilities API. Silently disables on older WordPress versions without crashing.
- **Safe Defaults:** MCP is strictly off-by-default until explicitly enabled by an administrator.

---

### Dashboard Navigation

The admin interface is mounted under **ShapedPlugin → AI & MCP** with three primary views:

1. **AI & MCP Products:**
   - Overview strip showing active adapter version, products on/off count, and total available tools.
   - Per-product cards with dedicated endpoint URLs, individual on/off toggles, and tool counters.
   - **"View tools & sample prompts"** drawer displaying all registered abilities with human-readable explanations and 3+ realistic copy-paste prompts per tool.
   - Interactive **Connect a client** drawer, **Connection Tester**, and **Try a tool** live runner.
2. **Products & Store:**
   - Catalog browser for ShapedPlugin ecosystem plugins, beta builds, and addons.
   - Pinned Ed25519 public key verification and SHA-256 package hash integrity validation.
3. **Activity Log:**
   - Real-time audit table of recent AI invocations with status filters and outcome badges.

---

### Installation & Requirements

#### Requirements
- **WordPress:** 6.9 or higher (with native Abilities API support).
- **PHP:** 7.4 or higher (ext-sodium required for Store Track verification).
- One or more MCP-compatible ShapedPlugin products (e.g., Location Weather, Real Testimonials).
- Any MCP-compliant AI client (Claude Code, Claude Desktop, Cursor, Copilot, etc.).

#### Installation
1. Download the latest `shaped-kit.zip` from the [Releases page](https://github.com/ShapedPlugin/shaped-kit/releases).
2. In WordPress Admin, navigate to **Plugins → Add New → Upload Plugin**.
3. Select `shaped-kit.zip` and click **Install Now**, then **Activate Plugin**.
4. Access the dashboard under **ShapedPlugin → AI & MCP**.

---

### Development & Toolchains

Shaped Kit maintains isolated, project-local development toolchains:

```bash
# Install isolated PHPUnit & PHPCS toolchains
composer tools:install

# Run all test suites (loader tests, unit tests, WP integration tests, and the same integration tests on a real multisite)
composer test

# Run the integration tests on a real multisite only
composer test:multisite

# Run unit tests only
composer test:unit

# Run dependency-free loader tests
composer test:scripts

# Run Jest tests for React dashboard components
npm run test:js

# Compile production React admin bundle
npm run build

# Check code standards (WordPress Coding Standards + PHPCompatibilityWP)
composer lint

# Automatically fix code style violations
composer lint:fix

# Verify package zip against strict allow/denylists
npm run package:verify
```

---

### Privacy & Data Protection

- **No Remote Telemetry:** Shaped Kit makes zero outbound calls to ShapedPlugin servers.
- **Local Execution:** All MCP operations run locally between your WordPress server and your authenticated AI client.
- **Application Passwords:** Keys are displayed only once in your browser session and are never written to unencrypted database tables.
- **Zero-PII Logs:** The internal activity log strips all values, storing only argument schema keys.

---

### License

Shaped Kit is licensed under **GPLv2 or later**; see [`LICENSE`](LICENSE) for details.  
The bundled WordPress Model Context Protocol Adapter is copyright by its respective contributors under **GPL-2.0-or-later**.
