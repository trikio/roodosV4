def num(value):
    """12345 -> '12.345'."""
    return f"{int(value or 0):,}".replace(",", ".")


def day(value):
    """datetime -> '21-04', o 'nunca'."""
    return value.strftime("%d-%m") if value else "nunca"


def label(source):
    return f"{source.name} ({source.country.upper()})"
