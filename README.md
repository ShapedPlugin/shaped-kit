# Shaped Kit

**Unified AI & MCP Workspace for ShapedPlugin Products**

[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net/)
[![MCP Adapter](https://img.shields.io/badge/MCP%20Adapter-v0.7.0-green.svg)](https://github.com/WordPress/mcp-adapter)
[![License: GPLv2+](https://img.shields.io/badge/License-GPL%20v2%2B-orange.svg)](LICENSE)

Shaped Kit is the official companion and bridge plugin for ShapedPlugin products. It provides a unified admin workspace, bundles the official WordPress Model Context Protocol (MCP) Adapter, coordinates per-product MCP endpoints and ability switches, powers AI-driven Gutenberg block building and layout pattern application, hosts client configuration generators, enforces WriteGuard destructive action safety, and delivers an immutable AI activity audit log—all from your WordPress dashboard.

---

### Highlights

#### 🎛️ Unified Workspace & Controls

- **Unified AI & MCP Dashboard:** A dedicated command center in WordPress Admin (**ShapedPlugin → AI & MCP**) with live statistics, product cards, ability selectors, and connection wizards.

- **Dedicated Product Endpoints:** Every product exposes its own isolated MCP server endpoint (`/wp-json/{slug}/mcp`), preventing namespace collisions and token bloating across plugins.

- **Built-in Tool Explorer, Tester & Starter Rails:** Interactive Try-a-Tool inspector, live Connection Tester, and one-click **Starter Pills** (`Try a starter:`) that immediately populate valid, pre-tested JSON configurations without manual typing.

- **Client Snippet Generator & Deep-Linking:** One-click configurations tailored for **Claude Code, Claude Desktop, Cursor, OpenAI Codex, GitHub Copilot, Gemini CLI**, and generic HTTP MCP clients, with deep-linking from product settings tabs directly into the connect drawer.

#### 🤖 Autonomous AI Block Builder & Prompts

- **Build with AI (Block Builder & Ready Patterns):** Enables AI clients to inspect block schemas (`get-block-catalog`), validate and sanitize attribute sets without editor corruption (`validate-block-attributes`), safely insert Gutenberg blocks (`insert-block`), apply custom free-form color palettes, and use designer-crafted layout patterns (`list-ready-patterns`, `insert-ready-pattern`).

- **Native MCP Prompts Protocol:** First-class support for MCP Prompts (`prompts/list`, `prompts/get`), serving interactive, step-by-step guidance prompts (such as `build-testimonial-block`) to steer AI agents through complex multi-stage tasks.

- **Opt-In Ability Toolsets & Token Economy:** Granular controls to expose only relevant toolsets (`Core / Read-Only`, `Management`, `Destructive`, `Builder`), reducing LLM context window token consumption by up to 70%.

#### 🛡️ Enterprise Safety & Observability

- **WriteGuard Two-Step Confirmation:** Mutating or destructive actions (such as permanent deletion, existing page modifications, or global cache purges) cannot be triggered in a single hit. The AI client must execute a preview dry-run to receive an HMAC-bound 32-character confirmation token before confirming.

- **TransportGuard & Clean-Transport Shield (E88):** Strips any stray PHP notices, warnings, or plugin markup printed prior to or during MCP REST responses, guaranteeing pristine JSON-RPC / SSE transport streams for AI clients.

- **Activity Log & Audit Trail:** Real-time oversight of all AI tool invocations. Records timestamp, client identity, ability name, execution latency, and result status (`ok`, `confirmed`, `denied`, `confirmation_required`, `error`) with strict zero-PII masking (parameter keys only).

#### 🔌 Architecture & Ecosystem

- **Replaceable MCP Adapter Fallback:** Bundles the official `WordPress/mcp-adapter` (pinned to v0.7.0) with conflict-free step-aside logic if another plugin supplies the adapter.

- **Products & Store Track:** Offline-first catalog loader with Ed25519 cryptographic detached signatures and SHA-256 package hash verification for ShapedPlugin betas, addons, and updates.

---

### Supported Products & MCP Abilities

| Product | Edition | Dedicated Endpoint | Abilities Count | Toolset Groups |
|---|---|---|---|---|
| **Real Testimonials** | Free & Pro | `/wp-json/real-testimonials/mcp` | Free: 16 / Pro: 20 + 1 Prompt | `Core` (Views, Categories, Forms, Context), `Management` (Create, Update, Bulk Import), `Destructive` (Delete with WriteGuard), `Builder` (Catalog, Validation, Custom Colors, Block & Pattern Insertion, MCP Prompt) |
| **Location Weather** | Free & Pro | `/wp-json/location-weather/mcp` | Free: 10 / Pro: 11 | `Core` (Templates, Context, Cache), `Management` (Create Celsius/Fahrenheit, Update, Duplicate), `Destructive` (Delete, Global Cache Purge with WriteGuard), `Cloud` (Pro Styble Cloud & AI Assistant) |
| **WP Carousel** | *Coming Soon* | `/wp-json/wp-carousel/mcp` | In Development | Layout templates, carousel blocks, and media query controls |

---

### Features & Architecture

#### 1. MCP Connector & Client Generator
Connect any AI assistant using standard WordPress Application Passwords:
- **Instant Mints & Time-Limited Persistence:** Generate a dedicated Application Password with one click, returned once by the server (`Cache-Control: no-store`). WordPress stores only its hash. So a refresh does not lose it, the browser keeps it in `localStorage` for up to 8 hours, then drops it; **Clear** removes it at once. A new password is saved before the earlier Shaped Kit ones are revoked, and passwords you made yourself are never touched.
- **One-Click Credential Pruning:** Revoke every Shaped Kit application password with the **Revoke all Shaped Kit passwords** button to keep your user profile clean. Passwords you made yourself are never touched.
- **Product Settings Tab Deep-Linking:** Direct deep-link button from each product's "MCP for AI Agents" settings tab to Shaped Kit's connection wizard with pre-selected product context.
- **Client Configuration Snippets:**
  - **Claude Code CLI:** Ready-to-run terminal commands using `@automattic/mcp-wordpress-remote`.
  - **Claude Desktop & Cursor:** Formatted JSON configuration blocks ready to paste into `claude_desktop_config.json` or `.cursor/mcp.json`.
  - **OpenAI Codex & GitHub Copilot:** CLI flags and environment configurations.
  - **Gemini CLI:** Complete flags for Google Gemini CLI (`gemini mcp add`).
  - **Generic HTTP:** Standard HTTP SSE/REST MCP schema details.

#### 2. Opt-In Toolsets & Token Budgeting
In MCP, every registered ability and its complete JSON schema is injected into the LLM system prompt on every turn. Exposing dozens of unused abilities consumes 6k–10k tokens before a conversation starts.
- **Granular Grouping:** Toolsets allow site owners to keep safe read-only queries permanently active while toggling `Management`, `Destructive`, or `Builder` abilities only when actively using them.
- **Always-On Core Guard:** Essential context inspection and listing abilities remain locked on for orientation.
- **Dynamic Adapter Filtering:** Disabled toolsets are stripped from the server schema entirely, ensuring token frugality and strict blast-radius isolation.

#### 3. WriteGuard Safety Engine
Prevents accidental or rogue data loss by AI models:
- When an AI agent attempts a destructive action (such as deleting an item, modifying existing pages, or purging site-wide transients), the server rejects execution with a `confirmation_required` error.
- The response returns a detailed **impact preview** (affected IDs, entities, side effects) and a cryptographically secure, time-limited confirmation token.
- The token is HMAC-SHA256 bound to the authenticated user ID, canonical argument hash, and entity fingerprint.
- The action only executes when the agent explicitly repeats the call with the valid confirmation token.

#### 4. AI Block Builder & Ready Patterns (`Builder` Toolset)
Unlocks Gutenberg block assembly for AI models without opening the block editor:
- **`get-block-catalog`:** Introspects block schemas, supported attributes, design rules, layout presets, and card design options without loading Gutenberg JS.
- **`validate-block-attributes`:** Performs dry-run attribute sanitization against the catalog schema. Supports free-form custom styling (dark backgrounds, custom title/text/star colors) while safely dropping unknown or invalid keys.
- **`insert-block`:** Inserts or replaces blocks in target pages with atomic database transactions, block markup verification, and post lock checks. Protected by WriteGuard when editing existing published content.
- **`list-ready-patterns`:** Searches and filters the designer pattern library with layout categories, tags, and edition paywall indicators.
- **`insert-ready-pattern`:** Instantly applies a selected ready-made pattern into a target page with atomic safety.
- **Starter Rails (`Try a starter:`):** Quick-load pills (`[ Autoplay Carousel ]`, `[ Four-Column Grid ]`, `[ Dark Theme & Colors ]`) ready to run directly in the dashboard.

#### 5. Native MCP Prompts Protocol (`prompts/list` & `prompts/get`)
Provides interactive prompt templates projected through the official MCP Prompts specification (adapter $\ge$ 0.5.0):
- **Guided Workflows:** Abilities registered with `meta.mcp.type = 'prompt'` (e.g. `build-testimonial-block`) serve structured prompt messages instructing the AI model through a safe sequence: `get-block-catalog` $\rightarrow$ `validate-block-attributes` $\rightarrow$ `insert-block`.
- **Zero Prompt-Engineering Overhead:** Users select the prompt in their AI client to trigger structured, validated block building without typing lengthy setup instructions.
- **Dynamic Toolset Gating:** Prompt abilities are strictly tied to their underlying toolset (`builder`), ensuring they vanish from `prompts/list` when the toolset is inactive.

#### 6. Activity Log & Audit Trail
Maintains a tamper-resistant database log (`{$wpdb->prefix}shaped_kit_mcp_log`):
- **Full Observability:** Logs timestamp, authenticated user, Application Password name, ability called, latency, and status.
- **Zero-PII Guarantee:** Only parameter keys (e.g. `['id', 'status']`) are stored—sensitive values, customer reviews, API keys, or payload contents are never persisted.
- **Maintenance:** Automatic background pruning keeps the log lean (90-day retention).

#### 7. Official WordPress MCP Adapter & TransportGuard
Shaped Kit bundles the official `WordPress/mcp-adapter` (currently pinned to upstream 0.7.0):
- **Conflict-Free Bootstrap (`AdapterBootstrap`):** Detects existing active adapter plugins, CLI activations, and third-party vendor bundles. If another compatible adapter is active, Shaped Kit gracefully steps aside.
- **TransportGuard (E88):** Discards any stray PHP notices, warnings, or plugin output before MCP REST responses, ensuring valid JSON-RPC / SSE streams.
- **Module Ownership Handoff (E119):** Manages clean delegation between Free and Pro editions without duplicate endpoint registration or conflicting toggle hooks.
- **WordPress 6.9+ Floor:** Leverages the native core WordPress Abilities API. Silently disables on older WordPress versions without crashing.
- **Safe Defaults:** MCP is strictly off-by-default until explicitly enabled by an administrator.

#### 8. Reusable Product Module Architecture (`templates/product-module/`)
Provides a shared, robust foundation for all ShapedPlugin products:
- **Single-Source Parity (PRD §20):** Free and Pro editions run identical engine code with edition differences declared strictly through metadata and feature flags.
- **Modular Sub-packages:** Reusable `Builder/` sub-package (`Catalog`, `ValueValidator`, `AttributePatch`, `BlockInserter`, `PatternLibrary`, `BuildPipeline`) allows rapid rollout to subsequent products (WP Carousel, etc.) with zero code duplication.

---

### Dashboard Navigation

The admin interface is mounted under **ShapedPlugin → AI & MCP** with three primary views:

1. **AI & MCP Products:**
   - Quick Product Selector bar (`[ All ] [ Real Testimonials ] [ Location Weather ]`) with automatic live runner synchronization.
   - Overview strip showing active adapter version, products on/off count, total available tools, and registered prompt templates.
   - Per-product cards with dedicated endpoint URLs, individual on/off toggles, and tool counters.
   - **"View tools & sample prompts"** drawer displaying all registered abilities with human-readable explanations, starter preset shortcuts, and 3+ realistic copy-paste prompts per tool.
   - Interactive **Connect a client** drawer (with Application Password generation, refresh persistence, and 1-click revocation), **Connection Tester**, and **Try a tool** live runner with auto-filled sample JSON and starter pills.
2. **Products & Store** *(off until the release pipeline is live; the tab, the Store routes and the update checks stay hidden or answer 503)*:
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
1. Download the release zip, `shaped-kit-<version>.zip`, from the [Releases page](https://github.com/ShapedPlugin/shaped-kit/releases). **Do not use GitHub's "Source code (zip)" or the green "Code → Download ZIP" button:** they leave out the built dashboard (`build/` is not in git), so the page would show no dashboard.
2. In WordPress Admin, navigate to **Plugins → Add New → Upload Plugin**.
3. Select the zip and click **Install Now**, then **Activate Plugin**.
4. Access the dashboard under **ShapedPlugin → AI & MCP**.

#### Running from source
If you clone the repository instead of using a release zip, build the dashboard once: `npm ci && npm run build`. To make an installable zip from your checkout, run `npm run package` (it builds, zips and verifies the package).

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
- **Application Passwords:** WordPress shows a new password only once and stores only a hash of it. The dashboard keeps the plain text in this browser for up to 8 hours (so a refresh does not lose it), never on the server, and removes it on expiry, on **Clear**, and when you revoke.
- **Zero-PII Logs:** The internal activity log strips all values, storing only argument schema keys.

---

### License

Shaped Kit is licensed under **GPLv2 or later**; see [`LICENSE`](LICENSE) for details.  
The bundled WordPress Model Context Protocol Adapter is copyright by its respective contributors under **GPL-2.0-or-later**.
