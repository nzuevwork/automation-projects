## Alisa + Bitrix24 + Home Assistant Integration

This project provides voice notifications using Yandex Alice
when business events occur in Bitrix24.

### Features
- Bitrix24 webhook listener (Flask)
- Text-to-Speech via Home Assistant
- Multi-device support (Yandex Stations, Siri)
- Secure HTTPS support
- Auto-restart on failure

### Use cases
- New deal notifications
- Payment confirmations
- CRM automation alerts
- Smart office announcements

### Tech stack
- Python / Flask
- Home Assistant API
- Bitrix24 Webhooks

### Deployment
1. Configure `config.py`
2. Install dependencies
3. Run behind Nginx / SSL