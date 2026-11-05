=== ARWP Agents ===
Contributors: artisanswp
Tags: ai, chatbot, chat, openrouter, agents
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Put a chat on any page. Replies come from OpenRouter, using your own API key.

== Description ==

ARWP Agents adds a chat to a page or post. You create an agent, write how it should behave, and paste its shortcode. Visitors type in the chat. The reply is written by a model on OpenRouter and charged to the API key you save in the plugin.

The chat lives on your site. Each conversation is stored in your database, and you can read or delete it later.

= Connect OpenRouter =

Go to ARWP Agents → Settings. Paste your API key, choose a default model, and use Test Connection. A working key loads the model list from your OpenRouter account. That list is kept for a week.

You can create a key at https://openrouter.ai/

= Create an agent =

Go to ARWP Agents → Add Agent. Name it, then set the fields under Agent & Model.

* System prompt. The instruction the model follows. A new agent starts with "You are a helpful and professional AI assistant."
* Model. Use the site default, or pick a different model.
* Temperature, from 0 to 2. Lower answers vary less. The starting value is 0.7.
* Max tokens. The longest reply the model may write. The starting value is 1000.
* Cooldown. How many seconds someone must wait between messages in the same chat. It starts at 5. Set it to 0 to turn the wait off.
* Messages per visitor, per hour. Counted by IP address. It starts at 30. Set it to 0 for no cap.
* Messages for this agent, per hour. Shared by everyone using that agent. It starts at 200. Set it to 0 for no cap.
* History. How many earlier messages are included with each new one. Leave it empty to send the last 40. A longer history costs more.

Chat Appearance is separate from the prompt.

* Light or dark. Dark is the default.
* The title shown in the header. If you clear it, the agent name is used. Otherwise it starts as "AI Assistant".
* A greeting, shown only when the chat has no messages yet. It starts as "Hello! How can I help you today?"
* The placeholder in the message box.
* A color for the send button and the visitor's messages. It starts as blue.
* A featured image, used as the avatar.

Publish the agent. The shortcode is in the sidebar, and again in the agents list:

`[arwp id="123"]`

Use the ID shown for that agent. Paste the shortcode into any page or post. A draft is shown only to people who can edit it.

= What people see =

The greeting appears first. Replies arrive as they are written, and a visitor can stop one that is still coming in. Messages can include Markdown. There is a copy button on the agent's replies, and a button to open the chat full screen. One message can be up to 8,000 characters.

The browser stores a token for that chat, so the same visitor on the same device picks up the conversation later. Anyone who can open the page can write a message, including people who are not logged in.

= Read the chats =

ARWP Agents → Chats lists each conversation, the agent, whether the visitor was a guest or a logged-in user, the number of messages, and the tokens used. Open a row to read it, or delete it.

Only administrators can create agents, change the API key, or open Chats.

= What leaves your site =

A message is sent to OpenRouter so the chosen model can reply. The request includes that message, the agent's system prompt, the recent messages from the same chat, and the model settings for that agent. Nothing is sent until someone uses a published shortcode.

OpenRouter's terms are at https://openrouter.ai/terms and its privacy policy is at https://openrouter.ai/privacy.

If a reply fails, visitors see a short note that the assistant is unavailable. An administrator who is logged in sees the actual error.

== Installation ==

1. Install and activate ARWP Agents.
2. Go to ARWP Agents → Settings. Save your OpenRouter API key and choose a default model.
3. Go to ARWP Agents → Add Agent. Write the prompt, then publish.
4. Copy the shortcode, such as `[arwp id="123"]`, and paste it into a page or post.

== Frequently Asked Questions ==

= Do I need an OpenRouter account? =

Yes. The plugin does not include a key. Create one at https://openrouter.ai/ and save it under ARWP Agents → Settings. Replies are charged to that account.

= Where do I put the chat? =

In the content of any page or post, using that agent's shortcode. Each agent has its own.

= Can each agent use a different model? =

Yes. An agent can follow the default model from Settings, or use another model from the list. If no default is saved, the plugin uses `openai/gpt-4o`.

= Who can send a message? =

Anyone who can view the page. They do not need a WordPress account. Only administrators can create or edit agents.

= Can visitors spend my OpenRouter credit? =

Yes, when the shortcode is on a public page. A new agent waits 5 seconds between messages, allows 30 messages per visitor each hour, and 200 messages an hour for that agent. Change the numbers on the agent. Set one to 0 to remove that limit.

= Where are the conversations stored? =

On your site. The visitor's browser keeps a token so the chat can continue. Administrators can read and delete conversations under ARWP Agents → Chats. Deleting the plugin also deletes the agents, the chats, and the saved API key.

= What if the key is missing or OpenRouter does not answer? =

The chat box still appears. The reply does not. Visitors see a general message. Logged-in administrators see the specific error. The agents screen also links to Settings when no key is saved.

== Screenshots ==

1. Settings, where you save the OpenRouter key and choose a default model.
2. A new agent, with its prompt, model, and message limits.
3. The chat, using the dark theme.
4. The same chat placed on a page with the shortcode.

== Changelog ==

= 1.5.0 =
* Renamed the plugin to ARWP Agents.
* Lengthened internal prefixes on PHP, options, hooks, custom tables, and the chat script.
* The chat shortcode is now `[arwp id="123"]`.
* Updated marked to 18.1.3 and Select2 to 4.1.0.

= 1.4.0 =
* First public release, with an OpenRouter connection, a chat for each agent, shortcode placement, and a conversation log.
* Only administrators can create or edit agents.
* Cooldown and hourly limits are on by default.
* Visitors see a general message when a reply fails. Administrators still see the detail.

= 1.1.1 =
* Hardened database queries.
* Sanitized the remaining request inputs.
* Bundled Select2 with the plugin instead of loading it from a third party.
