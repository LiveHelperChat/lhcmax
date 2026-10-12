# Max Messenger Integration for Live Helper Chat

Standalone two-way integration between Live Helper Chat and Max Messenger.

## Features

- Incoming Max messages through an LHC incoming webhook.
- Operator and bot text delivery through the configured LHC REST API action.
- Native three-step media delivery: create an upload session, upload the local file, then send a message with the returned token.
- Per-file delivery tracking prevents duplicate uploads when LHC retries a message event, while allowing partially failed multi-file messages to resume.
- Reply synchronization through Max message IDs, including native media replies.
- One-click creation or update of the incoming webhook and the optional REST action used for typing indicators.
- No Live Helper Chat core changes required.

## Install

Clone the extension into the LHC extension directory:

    cd /path/to/livehelperchat/lhc_web/extension
    git clone https://github.com/LiveHelperChat/lhcmax.git lhcmax

Enable it in lhc_web/settings/settings.ini.php:

    'extensions' => array(
        'lhcmax',
    )

Then open /index.php/site_admin/max/settings:

1. Enter the Max API host and bot token.
2. Leave the fallback department ID at 0 when the webhook scope is max.
3. Save and activate the Max configuration.
4. Register the displayed callback URL in the Max bot settings.

The generated callback uses Live Helper Chat's incoming-webhook endpoint:
`/index.php/webhooks/incoming/<identifier>` (the exact base path depends on
the LHC installation). The identifier is the only secret-bearing part of the
URL; keep the callback URL private.

The bot token is stored only in the ignored local settings/settings.ini.php file and in the installed LHC REST configuration. The repository template intentionally contains no token.

## Inline buttons and link previews

The `MaxWebhook` incoming webhook has two attributes (Settings -> Incoming
webhooks). Their values are kept when the configuration is activated again.

- `hide_buttons_on_click` - `true` (default) answers the button callback with
  `POST /answers` and an empty attachment list, so Max removes the keyboard
  from the clicked message and the message text stays. Visitors can't click
  the same buttons again, the same as in the website widget. `false` keeps the
  buttons.
- `disable_link_preview` - `true` sends messages with
  `disable_link_preview=true`. Default `false`.

The callback answer is a REST action (`Answer callback and hide buttons`) and
needs a bot trigger, the same way text delivery does:

1. In the bot which sends Max messages create a trigger with a single
   `Rest API` action: REST API `Max`, method `Answer callback and hide
   buttons`.
2. Create two webhooks (Settings -> Webhooks) for that trigger, events
   `chat.webhook_incoming_chat_continue` and `chat.webhook_incoming_chat_started`
   with the conditions `{args.webhook.scope}` `=` `max` and
   `{args.data.update_type}` `=` `message_callback`.

## Architecture

Max visitor -> LHC incoming webhook -> LHC chat.

Text and interactive actions use the configured LHC REST API action. The
`lhcmax` listener handles media only: POST /uploads, the returned multipart
endpoint, and then POST /messages. This separation prevents duplicate text
delivery while keeping the three-step upload independent of the REST worker.

## Test

Run the dependency-free contract harness with PHP:

    php tests/MaxContractTest.php

## License

Apache-2.0 or MIT.
