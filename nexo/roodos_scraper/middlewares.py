import logging
import os
import random
from typing import List, Optional

UA_POOL = [
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36",
    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15",
]

import requests
from dotenv import load_dotenv


class RandomUserAgentRotationMiddleware:
    @classmethod
    def from_crawler(cls, crawler):
        return cls()

    def process_request(self, request, spider):
        rotate = getattr(spider, "rotate_user_agents", False) or getattr(spider, "name", "").startswith("mercadolibre_")
        if not rotate:
            return
        request.headers["User-Agent"] = random.choice(UA_POOL)


class WebshareProxyRotationMiddleware:
    def __init__(self, api_token: Optional[str], page_size: int = 100):
        self.api_token = api_token
        self.page_size = page_size
        self.proxies: List[str] = []
        self._idx = 0
        self.logger = logging.getLogger(self.__class__.__name__)

    @classmethod
    def from_crawler(cls, crawler):
        load_dotenv()
        api_token = os.getenv("WEBSHARE_API_TOKEN")
        page_size = int(os.getenv("WEBSHARE_PAGE_SIZE", "100"))
        mw = cls(api_token=api_token, page_size=page_size)
        mw._bootstrap(crawler)
        return mw

    def _bootstrap(self, crawler):
        if not self.api_token:
            self.logger.warning("WEBSHARE_API_TOKEN not set; proxy rotation disabled")
            return

        try:
            fetched = self._fetch_webshare_proxies()
            self.proxies = fetched
            random.shuffle(self.proxies)
            self.logger.info("Loaded %s proxies from Webshare", len(self.proxies))
        except Exception as exc:
            self.logger.error("Failed loading Webshare proxies: %s", exc)
            self.proxies = []

    def _fetch_webshare_proxies(self) -> List[str]:
        url = "https://proxy.webshare.io/api/proxy/list/"
        headers = {
            "Accept": "application/json",
            "Authorization": f"Token {self.api_token}",
        }
        params = {"page": 1, "page_size": self.page_size}
        r = requests.get(url, headers=headers, params=params, timeout=20)
        r.raise_for_status()
        data = r.json()
        results = data.get("results") or []

        proxy_urls = []
        for p in results:
            host = p.get("proxy_address") or p.get("address") or p.get("ip")
            port = None
            ports = p.get("ports") or {}
            if isinstance(ports, dict):
                port = ports.get("http") or ports.get("socks5")
            port = port or p.get("port")

            username = p.get("username")
            password = p.get("password")

            if not host or not port:
                continue

            if username and password:
                proxy_urls.append(f"http://{username}:{password}@{host}:{port}")
            else:
                proxy_urls.append(f"http://{host}:{port}")

        return proxy_urls

    def process_request(self, request, spider):
        if not self.proxies:
            return
        proxy = self.proxies[self._idx % len(self.proxies)]
        self._idx += 1
        request.meta["proxy"] = proxy

    def process_response(self, request, response, spider):
        if response.status in {403, 429, 503} and self.proxies:
            # rotate immediately on likely block/rate-limit
            proxy = self.proxies[self._idx % len(self.proxies)]
            self._idx += 1
            retry_req = request.copy()
            retry_req.meta["proxy"] = proxy
            retry_req.dont_filter = True
            return retry_req
        return response
