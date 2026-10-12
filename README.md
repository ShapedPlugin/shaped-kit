# Shaped Kit

**Connect your AI assistant to your WordPress site and your ShapedPlugin products.**

Shaped Kit is a free companion plugin for ShapedPlugin products. It lets AI assistants such as Claude, Cursor, OpenAI Codex and GitHub Copilot look at, and where a product allows it work with, the ShapedPlugin products on your site, through the open **Model Context Protocol (MCP)**.

---

## The problem it solves

More site owners now run their work through an AI assistant. Without a connection, an assistant can't see anything inside your plugins. To ask "where is my London weather widget used?" or "how many testimonials do I have?", you have to open each plugin's screens and look it up yourself, then paste the answer into the chat.

Setting up that connection by hand is hard. It needs an MCP server, the right permissions, a safe way to sign in, and a different configuration for every AI app. It is also easy to get wrong in a way that exposes your site.

Shaped Kit does that part once, for every ShapedPlugin product on the site:

- **One install** makes every supported ShapedPlugin product reachable by your AI assistant.
- **Off until you turn it on.** Nothing is exposed by default.
- **Guided setup.** The dashboard creates the sign-in and gives you a ready-to-paste configuration for your AI app.
- **Your rights, nothing more.** The assistant works as a WordPress user you choose, with only that user's permissions.

---

## What you get

A new screen in WordPress Admin: **ShapedPlugin → AI & MCP**.

- **Your products at a glance.** Every ShapedPlugin product that supports MCP is listed with its status, its address and the number of tools it offers, plus an on/off switch for each.
- **Connect an AI app.** Create a sign-in (a WordPress Application Password) with one click. Copy the ready-made configuration for **Claude Code, Claude Desktop, Cursor, OpenAI Codex, GitHub Copilot**, or any other app that speaks MCP over HTTP.
- **Test connection.** Checks that the sign-in works, and explains the usual causes if it doesn't.
- **Try a tool.** Lists a product's tools and lets you run one yourself, so you can see exactly what an assistant would receive before you connect one.
- **Adapter status.** Shows which MCP adapter your site is using and whether it is compatible.

---

## How it works

An assistant never connects to your site directly. The AI app you use (for example Claude Desktop) sits in the middle:

1. You switch MCP on for a product and create a sign-in on the Shaped Kit screen.
2. You paste the configuration into your AI app.
3. When you ask the assistant a question, it picks a tool from the product's tool list. Your AI app calls your site, WordPress checks that user's permissions, and the product returns the answer.

Each ShapedPlugin product provides its own tools and its own address, so one product never adds clutter to another's tool list. The Pro edition of a product adds its tools to the same address as the free one, so upgrading doesn't break a connected app.

Shaped Kit itself carries the shared parts: the official **WordPress MCP Adapter** (bundled, unmodified), the dashboard and the connection help. Products also work with any other MCP adapter already on your site, such as the standalone adapter plugin, or one that another plugin includes. Shaped Kit steps aside rather than load a second copy.

---

## Privacy and security

**What stays where**

- **Shaped Kit runs no AI model, calls no AI provider, and sends nothing to ShapedPlugin.** It makes no outside requests of its own.
- **Your AI app and its provider do the thinking.** When you ask a question, whatever the tools return is processed by that provider, under your agreement with them. Switch a product on only if you are comfortable with that.
- **There is no chat, API key or account inside Shaped Kit.** You need an AI app and your own account with it.

**Sign-in**

- Access uses **WordPress Application Passwords**: one per AI app, each shown only once when you create it and individually revocable under Users → Profile.
- Shaped Kit never stores the password. It exists only in your browser while the screen is open. It is sent only to your own site's address, and the response that carries it is marked not to be cached.
- By default WordPress allows Application Passwords only on HTTPS sites, or on local development sites. If they are unavailable, the screen tells you why.
- Test connection and Try a tool run in your browser and send the password only to your own site.

**What an assistant can do**

- **Off by default.** Every product's MCP switch starts off. Installing Shaped Kit exposes nothing, including the default server that the MCP adapter would normally start.
- **Only administrators** can use the Shaped Kit screen, switch a product on or off, or create a sign-in from it.
- **An assistant is only as powerful as the user it signs in as.** Create the sign-in under an account with only the access you want the assistant to have, not necessarily your administrator account. A product's tools also check that user's permissions.
- **Switching a product off closes its address straight away,** even for an app that is already connected. Revoking the Application Password cuts off that one app.
- Treat a tool that can change data as you would an assistant with that user's access, and review what your AI app asks to run.

---

## Requirements

- **WordPress 6.9 or later**, which ships the Abilities API that MCP tools are built on.
- **PHP 7.4 or later.**
- A ShapedPlugin product that supports MCP.
- An AI app that supports MCP, with its own account.

---

## Installation

Shaped Kit is installed from GitHub.

1. Open the [Releases page](https://github.com/ShapedPlugin/shaped-kit/releases) and download **`shaped-kit.zip`** from the latest release.
2. In WordPress Admin go to **Plugins → Add New → Upload Plugin**, choose the zip, and click **Install Now**.
3. Click **Activate Plugin**.

Use the `shaped-kit.zip` attached to a release. Do not use **Code → Download ZIP** on the repository page: that file does not include the compiled dashboard, so the Shaped Kit screen would be empty.

To update, download the newer `shaped-kit.zip` and upload it the same way. WordPress asks whether to replace the current version.

---

## Getting started

1. Open **ShapedPlugin → AI & MCP**.
2. Switch MCP on for a product.
3. Choose **Create Application Password**, then pick your AI app and copy its configuration.
4. Paste it into your AI app, use **Test connection**, then ask a question in your usual chat.

You type normal sentences in your AI app, not tool names. The assistant picks the tool.

---

## License

Shaped Kit is licensed under **GPLv2 or later**; the full text is in [`LICENSE`](LICENSE).

The bundled WordPress MCP Adapter is licensed under **GPL-2.0-or-later** by its contributors.
