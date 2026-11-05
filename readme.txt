=== ARWP Agents ===
Contributors: artisanswp
Tags: ai, chatbot, chat, openrouter, agents
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted AI chat agents for WordPress, using your own OpenRouter API key.

== Description ==

ARWP Agents lets you create AI chat agents, add them to any page, and review conversations from one place. Each agent can be configured with its own system prompt, model, and generation parameters.

You connect one OpenRouter API key, create as many agents as you need, and place each chat with a shortcode: `[arwp id="123"]`.

Features:

* Streaming chat powered by OpenRouter models.
* Per-agent system prompt, model, temperature, token limit, and light or dark appearance.
* Per-agent cooldown and hourly message caps, with safe defaults.
* Conversation history and an admin conversation log.
* Shortcode placement on any page or post. Only administrators can create or edit agents.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/arwp-agents`, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Go to ARWP Agents → Settings and add your OpenRouter API key.
4. Create an agent under ARWP Agents → Add Agent and place it on a page with the shortcode.

== Frequently Asked Questions ==

= Does this plugin require an external API key? =

Yes. Chat responses are generated through the OpenRouter API, which requires your own API key configured under ARWP Agents → Settings.

= Where do visitor messages go? =

Each chat message is sent to OpenRouter (https://openrouter.ai/) so the selected model can generate a reply. The request includes the message, the agent system prompt, and recent chat history. OpenRouter's terms of service are at https://openrouter.ai/terms and its privacy policy is at https://openrouter.ai/privacy. Do not place a published shortcode on a public page unless you are comfortable with that data leaving your WordPress site.

= Can visitors use my OpenRouter credits? =

Yes. Anyone who can load a published agent shortcode can start a chat billed to your OpenRouter account. Each agent defaults to a 5 second cooldown, 30 messages per visitor per hour, and 200 messages per hour for that agent. Change those under the agent settings. Set a value to 0 to turn that cap off. Only publish agents you intend to expose.

== Changelog ==

= 1.5.0 =
* Renamed the plugin to ARWP Agents.
* Lengthened internal prefixes on PHP, options, hooks, custom tables, and the chat script.
* The chat shortcode is now [arwp id="123"].
* Updated marked to 18.1.3 and Select2 to 4.1.0.

= 1.4.0 =
* Initial public release: OpenRouter connection, per-agent chat, shortcode placement, and conversation log.
* Administrators are the only role that can create or edit agents.
* Per-agent cooldown and hourly message caps, with safe defaults.
* Visitors see a generic message when the upstream API fails. Administrators still see the detail.

= 1.1.1 =
* Hardened database queries with identifier-safe `$wpdb->prepare()` placeholders.
* Sanitized and unslashed remaining request inputs across admin and AJAX handlers.
* Vendored the Select2 admin asset locally instead of loading it from a third-party CDN.
* Escaped inline nonce output and added missing translator comments.
