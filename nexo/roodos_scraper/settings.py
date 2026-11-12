BOT_NAME = "roodos_scraper"

SPIDER_MODULES = ["roodos_scraper.spiders"]
NEWSPIDER_MODULE = "roodos_scraper.spiders"

ROBOTSTXT_OBEY = True

CONCURRENT_REQUESTS = 16
DOWNLOAD_DELAY = 0.6
RANDOMIZE_DOWNLOAD_DELAY = True
AUTOTHROTTLE_ENABLED = True
AUTOTHROTTLE_START_DELAY = 1
AUTOTHROTTLE_MAX_DELAY = 20
RETRY_ENABLED = True
RETRY_TIMES = 3

REQUEST_FINGERPRINTER_IMPLEMENTATION = "2.7"
FEED_EXPORT_ENCODING = "utf-8"

ITEM_PIPELINES = {
    "roodos_scraper.pipelines.MySQLRoodosPipeline": 300,
}

DOWNLOADER_MIDDLEWARES = {
    "roodos_scraper.middlewares.RandomUserAgentRotationMiddleware": 545,
    "roodos_scraper.middlewares.WebshareProxyRotationMiddleware": 740,
    "scrapy.downloadermiddlewares.httpproxy.HttpProxyMiddleware": 750,
}

DEFAULT_REQUEST_HEADERS = {
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "Accept-Language": "es-419,es;q=0.9,en;q=0.8",
    "User-Agent": "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36",
}
