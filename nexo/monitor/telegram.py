import sys

import requests

from .config import TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID

MAX_LENGTH = 4000  # Telegram corta en 4096


def send(text):
    """Manda un mensaje al chat de alertas. Si falla, lo deja en stderr (queda en el log del cron)."""
    if not TELEGRAM_BOT_TOKEN or not TELEGRAM_CHAT_ID:
        print(f"[telegram sin configurar] {text}", file=sys.stderr)
        return False
    if len(text) > MAX_LENGTH:
        text = text[: MAX_LENGTH - 20] + "\n… (cortado)"
    try:
        response = requests.post(
            f"https://api.telegram.org/bot{TELEGRAM_BOT_TOKEN}/sendMessage",
            json={"chat_id": TELEGRAM_CHAT_ID, "text": text, "disable_web_page_preview": True},
            timeout=20,
        )
        response.raise_for_status()
        return True
    except requests.RequestException as exc:
        print(f"[telegram falló: {exc.__class__.__name__}] {text}", file=sys.stderr)
        return False
