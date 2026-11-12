import os
from pathlib import Path

from dotenv import load_dotenv

ROOT = Path(__file__).resolve().parents[1]
LOG_DIR = ROOT / "logs"

load_dotenv(ROOT / ".env")

SCRAPY = os.getenv("NEXO_SCRAPY", "/home/trikio/.local/bin/scrapy")
PYTHON = "/usr/bin/python3"

TELEGRAM_BOT_TOKEN = os.getenv("TELEGRAM_BOT_TOKEN", "")
TELEGRAM_CHAT_ID = os.getenv("TELEGRAM_CHAT_ID", "")

# Un aviso activo que su fuente no ve en estos días se desactiva (solo en fuentes con full_crawl).
EXPIRE_DAYS = 30

# Una ejecución que ve menos de esta fracción de lo habitual cuenta como caída.
DROP_RATIO = 0.5

DASHBOARD_URL = os.getenv("NEXO_DASHBOARD_URL", "http://192.168.1.27:8765")
