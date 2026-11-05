=== ARWP Agents ===
Contributors: artisanswp
Tags: ai, chatbot, chat, openrouter, agents
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add a chat to any page, with your own OpenRouter key.

== Description ==

ARWP Agents lets you keep AI chat on your own site. You hold the key, decide how each agent should sound, and place the chat where you want it.

Use the shortcode `[arwp id="123"]`.

* One OpenRouter key for every agent.
* A prompt, model, temperature, and reply length for each one.
* Light or dark.
* A short pause between messages, and hourly limits, so a public page cannot run through your credits.
* A conversation log in wp-admin.

Only administrators can create or edit agents.

A message is sent to OpenRouter with the agent's prompt and the recent turns of that chat, so the model can reply. OpenRouter's terms are at https://openrouter.ai/terms and its privacy policy is at https://openrouter.ai/privacy.

== Installation ==

1. Install and activate ARWP Agents.
2. Open ARWP Agents → Settings and save your OpenRouter key.
3. Create an agent, then add `[arwp id="123"]` to a page.

== Frequently Asked Questions ==

= Do I need an API key? =

Yes. Replies come from OpenRouter, using the key you save under ARWP Agents → Settings.

= Where do messages go? =

To OpenRouter, with the agent's prompt and recent messages from that conversation. Nothing is sent until someone uses a published shortcode.

= Can visitors spend my credits? =

Yes, if they can open a page with the shortcode. A new agent waits 5 seconds between messages, allows 30 messages per visitor each hour, and 200 messages an hour in total. Set a limit to 0 to turn it off.

== Screenshots ==

1. Save your OpenRouter key and choose a default model.
2. Give an agent its prompt, model, and limits.
3. The chat, in dark mode.
4. The same chat placed on a page.

== Changelog ==

= 1.5.0 =
* Renamed the plugin to ARWP Agents.
* The shortcode is now `[arwp id="123"]`.
* Updated marked to 18.1.3 and Select2 to 4.1.0.

= 1.4.0 =
* First public release: OpenRouter, per-agent chat, shortcode placement, and a conversation log.
* Only administrators can create or edit agents.
* Cooldown and hourly limits, on by default.
* Visitors see a general message when a reply fails. Administrators still see the detail.

= 1.1.1 =
* Safer database queries, cleaned request handling, and a local copy of Select2.
