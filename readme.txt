=== Ai Connect ===
Contributors: aymnali
Donate link: https://aymnali.com/
Tags: mcp, ai, claude, cursor, copilot, developer-tools, automation, llm, wordpress-ai
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect AI coding agents (Claude, Cursor, Copilot, Antigravity) to WordPress over MCP with safe, granular access to files, database, and settings.

== Description ==

**Ai Connect** transforms your WordPress site into a high-performance **Model Context Protocol (MCP)** server. It provides AI coding assistants—such as **Claude Code, Cursor, Windsurf, GitHub Copilot / VS Code, Claude Desktop, ChatGPT Desktop, Google Antigravity, and OpenCode**—with direct, structured, and gated access to inspect, develop, debug, and maintain WordPress websites in real time.

Instead of pasting code snippets back and forth or explaining your site structure manually, Ai Connect empowers your AI assistant to read files, run database queries, manage plugins and themes, inspect system health, verify design tokens, and execute background tasks safely under strict administrative supervision.

Ai Connect is completely free, open-source (GPLv2+), and runs entirely on your own WordPress installation. There are no external SaaS dependencies, no subscription fees, no proxy servers, and no telemetry tracking.

---

### Key Capabilities at a Glance

* **Native MCP Streamable HTTP Endpoint**: Fast, stateless JSON-RPC responses over `/wp-json/ai-connect/v1/mcp` supporting MCP specifications `2025-06-18`, `2025-03-26`, and `2024-11-05`. Includes backward-compatible `/sitewright/v1/mcp` routing.
* **43 Built-In Specialized Tools**: Comprehensive coverage across site inspection, posts, taxonomies, comments, media upload, file management, SQL querying, WP-CLI execution, PHP evaluation, design system tokens, and background jobs.
* **Granular 3-Tier Risk Hierarchy**: Tools are strictly categorized as `read`, `write`, or `dangerous`, protected by a global Read-Only switch, a Dangerous-Tools kill switch, and per-tool administrative toggles.
* **Master Emergency Switch**: Instantly disable the entire MCP endpoint and pause all background jobs with a single click, returning immediate `403 Forbidden` responses without deactivating the plugin or losing settings.
* **Cryptographic Token Security**: Connect agents using hashed bearer tokens (`swt_...`). The database stores only one-way SHA-256 hashes. Supports per-token read-only flags, custom validity periods, and instant single or bulk revocation.
* **Brute-Force & Flood Protection**: Integrated per-IP rate limiting automatically blocks authentication attacks (100 failed attempts within 5 minutes results in an HTTP 429 response with `Retry-After`).
* **Crash-Safe PHP Sandbox**: Executable PHP created by agents is confined to `wp-content/sitewright-sandbox/` by default and isolated by a dedicated crash-recovery loader. If a script triggers a fatal PHP error, sandbox execution is automatically paused to keep your admin dashboard accessible.
* **Strict Filesystem & SQL Guardrails**: Path Guard enforces directory jails (ABSPATH or wp-content), blocks symlinks and directory traversal, shields sensitive files (`wp-config.php`), and prevents Windows NTFS alternate-stream injections. SQL Guard enforces single statements, denies access to system schemas (`information_schema`, `mysql`), and blocks dangerous file I/O operations (`INTO OUTFILE`, `LOAD DATA`).
* **Asynchronous Background Jobs**: Long-running operations such as plugin installations, theme updates, and complex WP-CLI tasks run asynchronously in the background (`job_start`, `job_status`, `job_cancel`), eliminating HTTP and proxy gateway timeouts.
* **WordPress Abilities API Bridge**: Native integration with WordPress 6.9+ Abilities API (`discover_abilities`, `run_ability`) with graceful fallback on earlier WordPress versions.
* **Dynamic Design System Engine**: Automatically seeds and exposes your site's color palette, typography scales, spacing units, and component guidelines directly from `theme.json` or active stylesheets, ensuring AI-generated UI matches your exact brand aesthetic.
* **Privacy-First Audit Log**: Records every tool invocation, timestamp, risk level, duration, and status without logging query arguments or confidential payloads.
* **Modern Administration Dashboard**: Seven clean, responsive admin screens designed with Google's design language: Overview, Connect AI, Tools, Design System, Diagnostics, Security, and Settings.

---

### The Complete 43 MCP Tools Catalog

Ai Connect equips your AI agent with 43 precision tools grouped into 9 specialized domains:

#### 1. Meta & Context Tools
* `agent_context` *(read)*: Orientation tool returning site identity, WordPress & PHP versions, active execution modes, design guidance, and system instructions. Recommended as the first call in every AI session.
* `discover_abilities` *(read)*: Discovers abilities registered through the WordPress Abilities API (WordPress 6.9+).
* `run_ability` *(write)*: Executes a registered WordPress ability by name with custom payload arguments.

#### 2. Site Inspection & Lifecycle Tools
* `site_info` *(read)*: Comprehensive environment report (WP/PHP versions, active theme, plugin counts, detected builders like Elementor/WooCommerce, debug flags, PHP memory limits).
* `list_plugins` *(read)*: Lists all installed plugins, active states, version numbers, and update availability flags.
* `install_plugin` *(dangerous)*: Installs a plugin directly from the WordPress.org repository by slug or from an external ZIP URL.
* `update_plugin` *(dangerous)*: Updates an installed plugin to its latest available release.
* `activate_plugin` *(write)*: Activates an installed plugin by file key.
* `deactivate_plugin` *(write)*: Deactivates an active plugin cleanly.
* `list_themes` *(read)*: Lists installed themes, child/parent relationships, versions, and current active theme.
* `list_users` *(read)*: Lists users with IDs, usernames, display names, and roles (emails optional).
* `diagnostics` *(read)*: Automated system audit checking HTTPS, REST API reachability, database integrity, sandbox status, WP-CLI availability, disk space, and cron health with actionable remedies.
* `get_option` *(read)*: Reads values from `wp_options` (including serialized arrays and objects).
* `update_option` *(write)*: Updates safe WordPress options while blocking critical core keys (`siteurl`, `home`, `active_plugins`, `ai_connect_*`).
* `clear_cache` *(write)*: Flushes WordPress object cache and purges third-party page caches (LiteSpeed, WP Rocket, W3 Total Cache, Cache Enabler, Breeze, SiteGround Optimizer, Autoptimize).

#### 3. Content Management Tools
* `get_posts` *(read)*: Queries posts, pages, and custom post types with status, category, taxonomy, author, search, and pagination filters.
* `get_post` *(read)*: Retrieves a single post with complete content, excerpt, post meta, terms, and author details.
* `create_post` *(write)*: Publishes or drafts posts, pages, or custom post types with metadata and terms.
* `update_post` *(write)*: Modifies fields, content, or metadata of existing posts.
* `delete_post` *(write)*: Sends posts to the trash or deletes permanently (`force=true`).
* `list_terms` *(read)*: Lists taxonomy terms (categories, tags, custom taxonomies) with hierarchy and counts.
* `create_term` *(write)*: Creates new taxonomy terms idempotently without duplicating existing slugs.
* `list_comments` *(read)*: Lists comments filtered by post, status, or author with pagination.
* `moderate_comment` *(write)*: Approves, unapproves, spams, trashes, or permanently removes comments.

#### 4. Media Library Tools
* `list_media` *(read)*: Lists media library attachments with URLs, MIME types, dimensions, and file sizes.
* `upload_media` *(write)*: Uploads files directly into the media library from a public URL or base64-encoded payload (up to 10 MB).

#### 5. Filesystem Operations Tools
* `list_directory` *(read)*: Traverses directories within the jail boundary with customizable depth and entry limits.
* `read_file` *(read)*: Reads file contents with byte offsets and maximum size safeguards (binary files returned as base64).
* `write_file` *(write)*: Writes full UTF-8 file contents (creates parent folders; PHP restricted to sandbox).
* `edit_file` *(write)*: Performs precise, chunk-based string replacements within existing files (fails if target chunk is missing or ambiguous).
* `delete_file` *(write)*: Removes a single file or empty directory (strictly protects core WordPress folders).
* `rename_file` *(write)*: Renames or moves files and directories (supports `.php.disabled` extension toggle to neutralize scripts safely).

#### 6. Database Tools
* `db_query` *(read)*: Executes read-only SQL queries (`SELECT`, `SHOW`, `DESCRIBE`, `EXPLAIN`) with `{prefix}` table placeholder resolution, prepared statement support, and automatic 200-row limits.
* `db_execute` *(dangerous)*: Runs write/DDL queries (`INSERT`, `UPDATE`, `DELETE`, `CREATE`, `ALTER`). Blocks multi-queries, system databases, and administrative commands (`GRANT`, `DROP DATABASE`).
* `db_tables` *(read)*: Lists all database tables with storage engines, row estimates, and data size.
* `db_describe` *(read)*: Inspects table schemas, column types, keys, and indexes.

#### 7. Code Execution & WP-CLI Tools
* `run_php` *(dangerous)*: Evaluates PHP snippets within the live WordPress runtime with full access to `$wpdb` and loaded core functions.
* `run_wp_cli` *(dangerous)*: Executes WP-CLI commands natively via `WP_CLI::runcommand` or system shell fallback with configurable timeouts.

#### 8. Design System Engine Tools
* `design_get` *(read)*: Reads the active design tokens (colors, typography scales, spacing, border radii, components, design rules) and stack-specific hints (Block theme, Elementor, WooCommerce, Astra).
* `design_update` *(write)*: Updates design tokens or re-seeds them automatically from `theme.json` or CSS stylesheets.

#### 9. Background Jobs Engine
* `job_start` *(dangerous)*: Queues long operations (plugin installs, heavy WP-CLI commands, batch operations) for asynchronous execution, returning a `job_id` immediately.
* `job_status` *(read)*: Checks job execution status (`queued`, `running`, `done`, `error`, `blocked`, `cancelled`), retrieving payloads upon completion while actively nudging the cron worker.
* `job_cancel` *(write)*: Cancels a queued background job before execution.

---

### Multi-Layer Security Architecture

Ai Connect is built specifically for **development, staging, and sandboxed sites**. Because AI coding agents are given real power, security is enforced through multiple defensive perimeters:

1. **Authentication Layer**: Bearer tokens (`swt_...`) are hashed using SHA-256 before storage. Plaintext keys are shown only once upon generation.
2. **Access Control & Permissions**: Every tool call validates capabilities (`manage_options`). Individual tokens can be restricted to Read-Only mode and assigned expiration dates.
3. **Execution Mode Gating**:
   * *Master Switch*: Turns off the MCP server entirely, stopping all agent traffic and background jobs.
   * *Global Read-Only Mode*: Instantly strips write and dangerous permissions from all connected agents.
   * *Dangerous Tools Kill Switch*: Disables PHP execution, SQL writing, WP-CLI, and plugin installations.
   * *Individual Tool Toggles*: Enable or disable any of the 43 tools individually.
4. **Filesystem Jail & Path Guard**: Restricts file access to ABSPATH or `wp-content`. Rejects relative traversals (`../`), null bytes, Windows trailing spaces/dots, and NTFS streams. `wp-config.php` and `.htaccess` are protected by default.
5. **Crash-Resistant Sandbox**: Restricts agent-written PHP files to `wp-content/sitewright-sandbox/`. A standalone bootstrap loader detects fatal crashes, registers error logs, and automatically deactivates sandbox execution to prevent site downtime.
6. **SQL Sanitization Guard**: Disallows multiple queries, disallows server file reading/writing (`INTO OUTFILE`, `LOAD_FILE`), and blocks queries targeting MySQL internal schemas.
7. **Rate Limiting**: Protects against brute-force password guessing by throttling failed requests (100 failures per 5 minutes triggers HTTP 429).

== Installation ==

### Method 1: WordPress Admin Upload
1. Download the `ai-connect.zip` file.
2. In your WordPress dashboard, navigate to **Plugins → Add New → Upload Plugin**.
3. Choose the ZIP file and click **Install Now**.
4. Click **Activate Plugin**.

### Method 2: Manual Installation via FTP/SSH
1. Extract `ai-connect.zip`.
2. Upload the `ai-connect` directory into your `/wp-content/plugins/` folder.
3. In your WordPress dashboard, go to **Plugins** and click **Activate** under **Ai Connect**.

---

### Quick Start: Connecting Your First AI Agent

1. In your WordPress sidebar, open **Ai Connect → Connect AI**.
2. Under **Enable Ai Connect**, ensure the master switch is checked.
3. Under **Create a connection token**, enter a label (e.g., `Cursor - MacBook`), choose an expiration timeframe, and click **Create token**.
4. Copy the generated token immediately (it will not be shown again in full).
5. Scroll down to the **Fastest: let your AI connect itself** section:
   * Select your preferred AI assistant (Claude Code, Cursor, Windsurf, VS Code Copilot, Claude Desktop, Antigravity, etc.).
   * Click **Copy prompt** and paste it directly into your AI assistant's chat window!
6. The AI assistant will automatically configure its MCP settings and verify the connection by calling `agent_context`.

---

### Manual Client Configuration Examples

If you prefer to configure your AI assistant manually, use the following snippets:

#### Claude Code (CLI)
Run the following command in your terminal:
`claude mcp add --transport http ai-connect https://example.com/wp-json/ai-connect/v1/mcp --header "Authorization: Bearer swt_YOUR_TOKEN_HERE"`

#### Cursor (`~/.cursor/mcp.json`)
Add this entry to your Cursor MCP settings file:
```json
{
  "mcpServers": {
    "ai-connect": {
      "url": "https://example.com/wp-json/ai-connect/v1/mcp",
      "headers": {
        "Authorization": "Bearer swt_YOUR_TOKEN_HERE"
      }
    }
  }
}
```

#### VS Code / GitHub Copilot (`.vscode/mcp.json`)
Add this configuration to your workspace or user settings:
```json
{
  "mcpServers": {
    "ai-connect": {
      "type": "http",
      "url": "https://example.com/wp-json/ai-connect/v1/mcp",
      "headers": {
        "Authorization": "Bearer swt_YOUR_TOKEN_HERE"
      }
    }
  }
}
```

#### Windsurf (`~/.codeium/windsurf/mcp_config.json`)
Add this server definition:
```json
{
  "mcpServers": {
    "ai-connect": {
      "serverUrl": "https://example.com/wp-json/ai-connect/v1/mcp",
      "headers": {
        "Authorization": "Bearer swt_YOUR_TOKEN_HERE"
      }
    }
  }
}
```

#### Claude Desktop & Google Antigravity (via stdio `mcp-remote` bridge)
Because desktop clients using stdio transport cannot connect to remote HTTP endpoints directly, use the lightweight `mcp-remote` bridge:
```json
{
  "mcpServers": {
    "ai-connect": {
      "command": "npx",
      "args": [
        "-y",
        "mcp-remote",
        "https://example.com/wp-json/ai-connect/v1/mcp",
        "--header",
        "Authorization: Bearer swt_YOUR_TOKEN_HERE"
      ]
    }
  }
}
```

#### ChatGPT Desktop (Codex `~/.codex/config.toml`)
Add this block to your Codex configuration file:
```toml
[mcp_servers.ai-connect]
url = "https://example.com/wp-json/ai-connect/v1/mcp"
http_headers = { Authorization = "Bearer swt_YOUR_TOKEN_HERE" }
```

---

### Upgrading from Sitewright 1.0.0

Ai Connect is the direct continuation of the Sitewright project with complete backward compatibility:
* **Zero Data Loss**: All existing tokens, settings, design tokens, and logs are automatically preserved.
* **Dual Endpoint Routing**: The legacy `/wp-json/sitewright/v1/mcp` endpoint remains registered and functional, so existing client setups will not break.
* **Safe Migration**: Old database tables and options are copied forward and kept intact as a rollback safety measure.

== Frequently Asked Questions ==

= What is Model Context Protocol (MCP)? =
Model Context Protocol (MCP) is an open standard developed by Anthropic that allows AI models to interact securely with external tools, APIs, and data sources. Ai Connect turns your WordPress site into a compliant MCP server so modern AI coding assistants can directly interact with your site.

= Is Ai Connect safe to use on production websites? =
Ai Connect is engineered primarily for **development, staging, local, and disposable sites**. While it provides enterprise-grade safety layers (path jails, SQL guards, rate limits, token hashing, and risk gating), giving an AI coding assistant write or dangerous access on a live production site carries inherent risks. If you use it on production, enable **Read-Only mode**, keep dangerous tools turned off, ensure HTTPS is active, and always maintain current database and file backups.

= What is the difference between Read, Write, and Dangerous tools? =
* **Read (Safe)**: Tools that inspect information without altering state (`site_info`, `get_posts`, `read_file`, `db_query`, `diagnostics`).
* **Write (Moderate)**: Tools that create or update standard WordPress data (`create_post`, `edit_file`, `upload_media`, `update_option`).
* **Dangerous (Elevated)**: Tools that execute arbitrary code or alter system infrastructure (`run_php`, `db_execute`, `run_wp_cli`, `install_plugin`, `job_start`). These can be disabled entirely with the Dangerous Tools kill switch.

= What happens when I turn off the Master Switch? =
Turning off the master switch on the **Connect AI** page cuts off the MCP endpoint immediately. Any incoming agent request is rejected with an HTTP `403 Forbidden` response *before* token authentication runs, and background jobs are halted. Your tokens and settings are safely retained, and the service resumes instantly once toggled back on.

= How do background jobs prevent timeouts? =
Operations like installing plugins or executing intensive WP-CLI scripts can take longer than the typical 30-second PHP or web server timeout. The `job_start` tool registers the task, returns a `job_id` within milliseconds, and executes the operation asynchronously via WordPress cron. The AI assistant polls `job_status` every few seconds until completion.

= What happens if an agent writes PHP that triggers a fatal error? =
By default, agents can only create PHP files inside `wp-content/sitewright-sandbox/`. This folder is managed by a crash-recovery loader. If any sandbox file causes a fatal PHP error, Ai Connect catches the crash, records the error message, and enters **Safe Mode**, preventing the faulty file from loading again so your WordPress admin dashboard remains functional.

= Does Ai Connect support plain (non-pretty) permalinks? =
Yes! While pretty permalinks (`/wp-json/...`) are cleaner, Ai Connect supports plain permalinks (`?rest_route=/ai-connect/v1/mcp`). All MCP clients handle query parameters seamlessly.

= How is token privacy preserved? =
Authentication tokens are shown in full only once during generation. The database stores only cryptographic SHA-256 hashes. Furthermore, the activity log only tracks tool names, timestamps, and execution results—it never stores arguments, database queries, file contents, or credentials.

= How does the Design System engine help the AI? =
The Design System inspects your active theme, block styles, and `theme.json` to extract color palettes, font families, font sizes, line heights, spacing increments, and component styles. When an AI generates a new page layout, block, or CSS, calling `design_get` provides it with the exact design tokens required to match your site's aesthetic seamlessly.

= Does Ai Connect work with WordPress Abilities API? =
Yes! Ai Connect includes bridge tools (`discover_abilities` and `run_ability`) that connect directly to the WordPress Abilities API introduced in WordPress 6.9+. On earlier WordPress versions (6.0–6.8), it reports clean availability status without errors.

= Can I restrict an agent to specific files or directories? =
Yes. In **Ai Connect → Settings**, you can restrict the filesystem jail to `wp-content` instead of the WordPress root, limit maximum read/write file sizes, and configure a list of protected files (such as `wp-config.php`, `.htaccess`, or custom secrets) that agents can never read, modify, or delete.

== Screenshots ==

1. **Overview Dashboard**: System status, active token count, modes summary (Master Switch, Read-Only, Dangerous Tools), recent activity log, and problem reporting form.
2. **Connect AI**: Master on/off toggle, token creator with custom expiration, active tokens list, and one-click prompts for all supported AI agents.
3. **Interactive Tools Registry**: Full catalog of 43 tools displaying risk badges, descriptions, and administrative enable/disable toggles.
4. **Design System Studio**: Color swatches, typography rules, spacing tokens, and component guidelines automatically seeded from the active theme.
5. **Environment Diagnostics**: In-depth health checks covering WordPress/PHP versions, REST API accessibility, database tables, sandbox status, WP-CLI, disk space, and cron workers.
6. **Security & Modes Control**: Global Read-Only toggle, Dangerous Tools kill switch, active token revocation, and comprehensive activity log.
7. **Settings & Guardrails**: Filesystem jail configuration (ABSPATH vs wp-content), PHP sandbox restrictions, protected path definitions, execution limits, and uninstall cleanup settings.

== Changelog ==

= 1.1.0 =
* **Official Rebrand**: Renamed from Sitewright to **Ai Connect** by Ayman Ali (https://aymnali.com/) with updated admin interface, modern branding, and dedicated REST routes (`/ai-connect/v1/mcp`).
* **Zero-Downtime Migration**: Automatic upgrade path carrying over all tokens, settings, design data, and activity logs. Legacy `/sitewright/v1/mcp` endpoint and `swt_` tokens remain fully functional.
* **Background Jobs Engine**: Added asynchronous job execution (`job_start`, `job_status`, `job_cancel`) with token re-validation at run-time, stale-job auto-recovery, concurrency caps, and automatic 7-day log pruning.
* **Enhanced Security Hardening**:
  * Added server-side SQL file I/O blocking (`INTO OUTFILE`, `INTO DUMPFILE`, `LOAD_FILE()`, `LOAD DATA`).
  * Enhanced Path Guard to block Windows trailing dot/space bypasses and NTFS alternate data stream colons (`::$DATA`).
  * Integrated brute-force IP rate limiting (100 failed auths per 5 minutes triggers HTTP 429).
  * Implemented strict single-render token display transient destruction.
* **Master On/Off Switch**: Added site-wide kill switch on the Connect AI page that instantly returns HTTP 403 before authentication without touching stored configurations.
* **One-Click Agent Prompts**: Enhanced Connect AI interface with dedicated, self-connecting prompts for Claude Code, Cursor, Windsurf, VS Code / Copilot, Claude Desktop, ChatGPT Desktop, Google Antigravity, and OpenCode.
* **Integrated Support Card**: Added direct problem reporting tool on Overview dashboard emailing diagnostic context directly to support@aymnali.com.
* **Schema Validation Fix**: Resolved issue where free-form objects (meta maps, term maps, job arguments) were filtered out during parameter validation.
* **Clean Uninstaller**: Added option to cleanly delete all database tables, option keys, transients, and sandbox files upon plugin deletion.

= 1.0.0 =
* Initial public release as Sitewright.
* Core MCP Streamable HTTP endpoint supporting JSON-RPC specifications.
* SHA-256 hashed token authentication with instant revocation.
* 40 built-in developer tools covering site inspection, content, media, files, database, WP-CLI, and PHP.
* Crash-safe PHP sandbox with automatic recovery.
* Design system token extractor and editor.
* Comprehensive diagnostic test suite.
* Seven responsive admin administration views.

== Upgrade Notice ==

= 1.1.0 =
This major release introduces the **Ai Connect** rebrand, asynchronous background jobs to prevent HTTP timeouts, heightened security safeguards, and one-click prompts for all major AI coding agents. Upgrading from version 1.0.0 is completely seamless and maintains 100% backward compatibility for all existing tokens, endpoints, and settings.
