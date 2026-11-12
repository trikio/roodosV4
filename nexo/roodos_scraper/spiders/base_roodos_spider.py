import json
from urllib.parse import urljoin

import scrapy
from w3lib.html import remove_tags

from roodos_scraper.items import ListingItem


class BaseRoodosSpider(scrapy.Spider):
    vertical = None  # car | house
    country = None   # CL/EC/PE/MX
    base_domain = None

    # Ajusta estos selectores al HTML real del sitio
    listing_selector = "article, .listing, .item"
    detail_link_selector = "a::attr(href)"
    next_page_selector = "a[rel='next']::attr(href), .pagination a.next::attr(href)"

    def start_requests(self):
        yield scrapy.Request(self.base_domain, callback=self.parse_list)

    def parse_list(self, response):
        cards = response.css(self.listing_selector)
        for card in cards:
            href = card.css(self.detail_link_selector).get()
            if href:
                yield response.follow(href, callback=self.parse_detail)

        next_href = response.css(self.next_page_selector).get()
        if next_href:
            yield response.follow(next_href, callback=self.parse_list)

    def parse_detail(self, response):
        item = ListingItem()
        item["vertical"] = self.vertical
        item["source_name"] = self.name
        item["country"] = self.country

        item["url"] = response.url
        item["slug"] = response.url.rstrip("/").split("/")[-1]
        item["title"] = (
            response.css("h1::text").get()
            or response.css("meta[property='og:title']::attr(content)").get()
            or ""
        ).strip()

        item["image"] = response.css("meta[property='og:image']::attr(content)").get()
        item["image_big"] = item["image"]

        details_html = response.css(".description, .details, .content").get() or ""
        item["details"] = remove_tags(details_html).strip()

        price_text = (
            response.css(".price::text").get()
            or response.css("meta[property='product:price:amount']::attr(content)").get()
            or ""
        )
        item["price"] = self._digits(price_text)

        external_id = response.css("[data-id]::attr(data-id)").get()
        if not external_id:
            external_id = self._extract_jsonld_field(response, "sku")
        item["external_id"] = external_id

        # Mapeos de ubicación: por ahora se dejan null (resolver con catálogo propio)
        item["id_state"] = None
        item["id_city"] = None

        if self.vertical == "car":
            item["id_car_make"] = None
            item["id_car_model"] = None
            item["year"] = self._digits(response.css(".year::text").get())
            item["km"] = self._digits(response.css(".km::text, .mileage::text").get())
            item["id_fuel"] = None
            item["id_body"] = None
            item["id_transmission"] = None
        else:
            item["id_house_operation"] = None
            item["id_house_type"] = None
            item["rooms"] = self._digits(response.css(".rooms::text").get())
            item["bath"] = self._digits(response.css(".bath::text, .baths::text").get())
            item["size"] = self._digits(response.css(".size::text, .area::text").get())

        yield item

    def _digits(self, value):
        if not value:
            return None
        text = str(value)
        digits = "".join(ch for ch in text if ch.isdigit())
        return int(digits) if digits else None

    def _extract_jsonld_field(self, response, field):
        scripts = response.css("script[type='application/ld+json']::text").getall()
        for raw in scripts:
            try:
                data = json.loads(raw)
                if isinstance(data, list):
                    for obj in data:
                        if isinstance(obj, dict) and field in obj:
                            return obj.get(field)
                elif isinstance(data, dict) and field in data:
                    return data.get(field)
            except Exception:
                continue
        return None
