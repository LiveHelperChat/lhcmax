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
