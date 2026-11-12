import scrapy


class ListingItem(scrapy.Item):
    vertical = scrapy.Field()  # car | house
    source_name = scrapy.Field()
    source_label = scrapy.Field()  # nombre de la fuente que muestra producción (cars.nexo_id)
    source_id = scrapy.Field()
    country = scrapy.Field()  # CL/EC/PE/MX

    url = scrapy.Field()
    slug = scrapy.Field()
    title = scrapy.Field()
    image = scrapy.Field()
    image_big = scrapy.Field()
    details = scrapy.Field()
    price = scrapy.Field()
    external_id = scrapy.Field()

    # geo ids (resolved by your own mapping)
    id_state = scrapy.Field()
    id_city = scrapy.Field()

    # car-specific
    id_car_make = scrapy.Field()
    id_car_model = scrapy.Field()
    brand_name = scrapy.Field()  # for make lookup
    model_name = scrapy.Field()  # for model lookup
    year = scrapy.Field()
    km = scrapy.Field()
    id_fuel = scrapy.Field()
    id_body = scrapy.Field()
    id_transmission = scrapy.Field()

    # house-specific
    id_house_operation = scrapy.Field()
    id_house_type = scrapy.Field()
    rooms = scrapy.Field()
    bath = scrapy.Field()
    size = scrapy.Field()
