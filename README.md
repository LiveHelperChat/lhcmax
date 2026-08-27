# Live Helper Chat - Max Messenger Extension (lhcmax)

Official standalone integration extension for **Max Messenger (`platform-api2.max.ru`)** with Live Helper Chat.

## Features
- 🚀 Two-way instant messaging between LHC operators and Max users.
- 📎 Native 3-step media upload engine (`POST /uploads` -> Binary upload -> `POST /messages` with token) for images, voice, audio, video, and documents.
- 💬 Full quote and reply synchronization (`link: {type: "reply", mid: ...}`).
- 🔄 Clean architecture: keeps Live Helper Chat core 100% stock and updatable without merge conflicts.

## Installation
1. Place this folder into `lhc_web/extension/lhcmax`.
2. Add `'lhcmax'` to `settings/settings.ini.php` in the `extensions` array:
```php
'extensions' => array(
    'lhcmax',
    'lhctelegram',
    ...
)
```
3. Configure your Bot token in `extension/lhcmax/settings/settings.ini.php`.
