=== Ai Connect ===
Contributors: aymnali
Tags: mcp, ai, claude, developer-tools, wp-cli, automation
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect AI coding agents to WordPress over MCP with real, gated access to the site.

== Description ==

Ai Connect turns a WordPress site into an MCP (Model Context Protocol) server so AI coding
agents (Claude Code, Claude Desktop, ChatGPT Desktop, VS Code / GitHub Copilot, Cursor,
Windsurf, Google Antigravity, OpenCode, and any MCP-capable client) can inspect and
operate the site for real.

Core capabilities:

* **MCP Streamable HTTP endpoint** (stateless JSON responses) at `/ai-connect/v1/mcp`,
  protocol versions 2025-06-18, 2025-03-26, and 2024-11-05. The pre-1.1.0
  `/sitewright/v1/mcp` route stays registered so existing client setups keep working.
* **Connection tokens** (`swt_...`) shown once; authentication stores only SHA-256
  hashes. The newest token is also kept server-side so the Connect AI page can pre-fill
  its ready-made prompts — it is replaced by the next token, deleted on revocation or
  expiry, and removed on uninstall. Per-token read-only mode, expiry, and instant
  revocation. Failed authentications are throttled per IP (429 + Retry-After).
* **43 built-in tools**: site inspection, plugin/theme/user management, options,
  content (posts, terms, comments, media), filesystem tools, database tools, WP-CLI,
  PHP execution, a design system, diagnostics, the WordPress Abilities API bridge, and
  background jobs (`job_start` / `job_status` / `job_cancel`) for long operations that
  would otherwise die on HTTP or proxy timeouts.
* **Three risk levels** (read / write / dangerous) with a global read-only mode, a
  dangerous-tools kill switch, per-tool enable/disable, and MCP readOnlyHint annotations.
* **Master on/off switch** on the Connect AI page: stop the whole integration without
  deactivating the plugin. While off, every MCP request gets a clear 403 error *before*
  authentication and background jobs neither start nor run; tokens and settings are kept,
  and everything resumes the moment it is switched back on.
* **A crash-safe PHP sandbox**: agent-written PHP may only be written to
  `wp-content/sitewright-sandbox/` by default (directory name kept from 1.0.0 for update
  compatibility) and is loaded with safe-mode recovery if it ever fatals.
* **Path and SQL guards**: jail, traversal/absolute/symlink protection, protected paths
  (wp-config.php by default), Windows trailing-dot/space and NTFS alternate-data-stream
  rejection, single-statement SQL classification, MySQL system-schema refusal, and
  server-side file IO refusal (`INTO OUTFILE`, `INTO DUMPFILE`, `LOAD_FILE()`,
  `LOAD DATA`).
* **Activity log** of every tool call (no arguments, no secrets) with retention limits.
* **Design system** agents must follow: palette, type scale, spacing, radii, components,
  guidelines, seeded from theme.json / theme CSS when available.
* **Seven admin pages**: Overview, Connect AI, Tools, Design System, Diagnostics,
  Security, Settings — all functional, styled after Google's design language.

Ai Connect is free and open source (GPL-2.0-or-later). There is no license server, no
account, and no paid tier.

Built for **development, staging, and disposable sites**. Agents are powerful by design;
do not enable dangerous tools on production sites you cannot restore.

== Installation ==

1. Upload the plugin ZIP via Plugins → Add New → Upload Plugin, or copy the `ai-connect`
   folder into `/wp-content/plugins/`.
2. Activate the plugin. The tokens, log, and jobs tables are created automatically.
3. Open **Ai Connect → Connect AI**, create a token (read-only recommended), then copy the
   ready-made configuration for your AI client.
4. Ask the agent to call `agent_context` first.

**Upgrading from version 1.0.0 (formerly Sitewright):** install this version next to the
old one or replace its folder, then activate. Tokens, settings, the design system, and
the activity log carry over: legacy tables/options are copied forward on first load and
never deleted, and the old REST route, option keys, table names, admin URLs, and cron
hook keep working. No client reconfiguration is required.

== Frequently Asked Questions ==

= Which AI clients work? =

Any MCP client with Streamable HTTP support. Ready-made configurations are shown for
Claude Code, Claude Desktop (via `npx mcp-remote`), ChatGPT Desktop (via its shared Codex
config), VS Code / GitHub Copilot, Cursor, Windsurf, Google Antigravity, and OpenCode —
plus generic instructions for any other MCP client. Cards for ChatGPT Web and Gemini
Web / Desktop are already visible but marked **Coming Soon**.

= What is the fastest way to connect an agent? =

Create a token, then copy the "let your AI connect itself" prompt from the
Connect AI page and paste it into your assistant's chat. The prompt contains the
endpoint, the token, per-client setup snippets, and verification steps, so the
assistant can configure its own MCP connection and prove it works. If it cannot
edit its own settings, it will tell you exactly what to change.

= What does read-only mode do? =

It blocks every write and dangerous tool for all tokens, regardless of their own settings.
Use it when an agent should only inspect.

= How do I switch Ai Connect off without deactivating the plugin? =

Open **Ai Connect → Connect AI** and untick *Turn on Ai Connect for this site*, then save.
While off, every MCP request gets a clear "turned off" error before any token check runs,
and background jobs do not start or run. Your tokens and settings are kept, and everything
resumes as soon as you tick it back on.

= Where can agents write PHP? =

By default only to `wp-content/sitewright-sandbox/`, which is loaded at startup with
crash recovery. On disposable sites, experts can relax this by setting
`php_file_writes` to `anywhere` (not recommended for production).

= Is my token stored? =

Authentication stores only its SHA-256 hash. The plaintext is displayed exactly once at
creation, and the token you generated last is additionally kept server-side so the
Connect AI page can fill it into the ready-made agent prompts automatically. That copy
is replaced when you generate a new token and deleted as soon as the token is revoked
(individually, in bulk, or by expiry) — and everything is removed on uninstall.

= My client times out during plugin updates or WP-CLI commands. What now? =

Use `job_start` for long operations (`install_plugin`, `update_plugin`, `run_wp_cli`,
`run_php`). It returns a job id immediately and keeps working in the background; poll
`job_status` every few seconds until it finishes. The token and all permissions are
re-validated when the job actually runs.

= Does this work without the WordPress Abilities API? =

Yes. `discover_abilities` and `run_ability` report availability and everything else works
normally on WordPress 6.0–6.8.

= I upgraded from 1.0.0 — must I change my MCP client setup? =

No. The legacy `/sitewright/v1/mcp` route and the `swt_` token format both still work.
Switch your client to `/ai-connect/v1/mcp` whenever convenient.

== Changelog ==

= 1.1.0 =
* Rebranded to **Ai Connect** (formerly Sitewright) — a project by Ayman Ali
  (https://aymnali.com/). Plugin name, admin UI, docs, logo, and the MCP endpoint
  (`/ai-connect/v1/mcp`) all use the new identity.
* Safe migration with zero data loss: tokens, settings, design system, and activity log
  carry over; legacy tables/options are copied forward and kept as a rollback backup;
  the legacy REST route, option keys, table names, admin URLs, and cron hook keep
  working.
* New: background jobs for long-running tools (`job_start`, `job_status`, `job_cancel`)
  with execution-time token re-validation, stale-job recovery (failed after 15 minutes),
  per-token concurrency limits, and 7-day pruning.
* New: one-time token display is now a single render — the cached payload is deleted as
  soon as it has been shown.
* Security: SQL tools refuse server-side file IO (`INTO OUTFILE`, `INTO DUMPFILE`,
  `LOAD_FILE()`, `LOAD DATA`).
* Security: path guard rejects Windows trailing-dot/space segments and NTFS alternate
  data stream colons.
* Security: per-IP throttling for failed authentications (100 failures / 5 minutes →
  429 with Retry-After).
* Fixed: free-form argument objects (post meta maps, term maps, ability payloads, job
  arguments) are passed through schema validation instead of being silently emptied.
* Uninstall now removes both table families, both option families, cached secret
  transients, and sandbox files when "Delete all Ai Connect data on uninstall" was
  enabled.
* New: the newest generated token is kept server-side so the Connect AI page can
  pre-fill its ready-made prompts; it is replaced by the next token and deleted on
  revocation or expiry.
* Logo and icon integrated across the admin (menu icon, page headers, Overview hero).
  The Overview page shows the full branding lockup (icon + wordmark + tagline) as its
  only logo; the sidebar menu icon is constrained to WordPress's standard 20px slot
  (kept at 2x for retina sharpness) so it aligns with core icons.
* Connect AI page: the automatic card now lists every supported agent — Claude Code,
  VS Code / GitHub Copilot, Cursor, Windsurf, Claude Desktop, ChatGPT Desktop, Google
  Antigravity, OpenCode, and any other MCP client — each with a short prompt that
  connects the agent by itself. Cards for ChatGPT Web and Gemini Web / Desktop are
  visible but disabled and labeled **Coming Soon** until those platforms support
  custom-header connections. The manual-setup card is gone. A note explains that the
  last token you generated is added automatically and shows where to change it
  (highlighted red inside every prompt).
* New: **Report a problem** card on the Overview page — sends a short description by
  email to support@aymnali.com together with the site URL and version details
  (nonce-protected form, administrators only, nothing stored).
* New: master on/off switch (Connect AI page) — stops the endpoint with a clear 403
  before authentication and stops background jobs, without deactivating the plugin or
  touching tokens/settings. The card carries the red **Note:** prefix and the usage
  note; Overview shows the state and Connect AI warns while off.
* Docs: README, architecture, security model, client guide, changelog, security audit
  report, and verification report.

= 1.0.0 =
* Initial release: MCP endpoint, tokens, 40 tools, sandbox, design system, diagnostics,
  seven admin pages.
