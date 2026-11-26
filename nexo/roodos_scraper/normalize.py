"""Saca del texto de un aviso su ubicación, combustible, transmisión, carrocería y versión.

Los spiders casi nunca entregan esos datos como IDs: vienen en el título ("Kia Sportage 2016 2.0 Lx
Diesel 4x2 6mt 5p") o en `details`, como segmentos separados por "|" ("SUVs | Automática | Gasolina |
La Molina | Lima | USD") o como clave:valor ("fuel:Diésel | trans:Transmisión Mecánica | region:13").

Reglas para no inventar datos:
- La ubicación sale solo de segmentos cortos de `details` (o del campo `location` del spider) que
  coinciden enteros con una ciudad o región del país; nunca del título ni de descripciones largas.
  Una ciudad con nombre repetido (hay 27 "Miraflores" en Perú) se desempata con la región del mismo
  aviso o con `puntaje`; si sigue empatada, queda vacía.
- Combustible, transmisión y carrocería se buscan primero en los segmentos y, si no aparecen, en el
  título. Si hay señales que se contradicen, queda vacío.
- La versión es el título sin marca, modelo, año ni ruido del sitio, o el `version:` de details.

Lo usan el pipeline (cada aviso que entra) y scripts/normalize_cars.py (los avisos que ya están).
"""

import re
import unicodedata


def norm(text):
    if not text:
        return ""
    t = unicodedata.normalize("NFKD", str(text).strip().lower())
    t = "".join(ch for ch in t if not unicodedata.combining(ch))
    t = re.sub(r"[^a-z0-9]+", " ", t)
    return " ".join(t.split())


FUEL_IDS = {"gasolina": 1, "diesel": 2, "hibrido": 3, "electrico": 4, "gas": 5}
TRANSMISSION_IDS = {"manual": 1, "automatico": 2}
BODY_IDS = {"sedan": 1, "hatchback": 2, "suv": 3, "minivan": 4, "coupe": 5, "pickup": 6, "wagon": 7,
            "crossover": 8, "cabriolet": 9}

# Orden importa: lo primero que calza gana (un híbrido suele decir también "gasolina").
FUEL_PATTERNS = [
    ("hibrido", r"\b(hibrid[oa]s?|hybrid|hev|phev|mhev|enchufable)\b"),
    ("electrico", r"\b(electric[oa]?|electric|electro|bev)\b"),
    ("gas", r"\b(gnv|glp|gnc|gas|dual|bi ?fuel|gas natural)\b"),
    ("diesel", r"\b(diesel|petroleo|petrolero|tdi|crdi|hdi|bluehdi|d4d|tdci|dci|cdi|ddis|turbodiesel|d20)\b"),
    ("gasolina", r"\b(gasolin[ae]|gasoline|bencin[ae]r?o?|bencina|nafta|naftero|petrol)\b"),
]
# En el título, "gas" o "dual" sueltos dicen poco: solo cuentan en details.
TITLE_SKIP_FUEL = re.compile(r"^(gas|dual)$")

TRANSMISSION_PATTERNS = [
    ("automatico", r"\b(automatic[oa]?|automatica|automaticos?|automatictransmission|aut|at|a t|cvt|ecvt|e cvt|"
                   r"dsg|dct|edc|amt|ivt|tiptronic|steptronic|ta|secuencial|s tronic|powershift|multitronic|speedtronic|"
                   r"\d?g tronic|pdk|autostick)\b"),
    ("manual", r"\b(manual|manualtransmission|mecanic[oa]|mecanica|mt|m t|\d ?mt|tm|std|estandar|standard)\b"),
]

BODY_PATTERNS = [
    ("pickup", r"\b(pick ?up|pickup truck|doble cabina|cabina doble|dcab|d cab|dc|cabina simple|crew cab|"
               r"double cab|cab doble)\b"),
    ("cabriolet", r"\b(cabrio|cabriolet|convertible|descapotable|roadster)\b"),
    ("coupe", r"\b(coupe)\b"),
    ("wagon", r"\b(station wagon|wagon|sw|familiar)\b"),
    ("minivan", r"\b(minivan|van|vans|furgon|furgoneta|monovolumen)\b"),
    ("hatchback", r"\b(hatchback|hatch|hb)\b"),
    ("sedan", r"\b(sedan|sdn)\b"),
    ("crossover", r"\b(crossover)\b"),
    ("suv", r"\b(suvs?|todo terreno|todoterreno|jeep)\b"),
]
# "Jeep" en details es un tipo de carrocería (Ecuador); en el título es la marca.
TITLE_SKIP_BODY = re.compile(r"^jeep$")

# Palabras de details que nunca son una ubicación.
NOT_A_PLACE = {
    "usd", "clp", "mxn", "pen", "particular", "comercial", "auto", "autos", "usado", "usada", "nuevo",
    "seminuevo", "precio", "consultar", "contado", "financiado", "negociable", "blanco", "negro", "gris",
    "rojo", "azul", "plata", "plateado",
}

LOCATION_KEYS = re.compile(r"^(region|ciudad|city|comuna|provincia|estado|ubicacion|location|sede|distrito)\s+")
PLACE_PREFIXES = re.compile(
    r"^(region metropolitana de|region del|region de la|region de los|region de|region|provincia del|provincia de|"
    r"provincia constitucional del|estado de|departamento de|municipio de)\s+")

STATE_ALIASES = {
    "cl": {"metropolitana": "13", "rm": "13", "rm metropolitana": "13", "metropolitana de santiago": "13", "santiago": "13",
           "region metropolitana": "13", "bio bio": "8", "biobio": "8", "del biobio": "8", "nuble": "8",
           "o higgins": "6", "ohiggins": "6", "libertador general bernardo o higgins": "6",
           "gral b o higgins": "6", "libertador b o higgins": "6", "la araucania": "9", "araucania": "9",
           "los rios": "14", "de los rios": "14", "arica y parinacota": "15", "arica": "15", "aysen": "11",
           "aisen": "11", "magallanes": "12", "valparaiso": "5", "tarapaca": "1", "maule": "7", "los lagos": "10",
           "antofagasta": "2", "atacama": "3", "coquimbo": "4"},
    "mx": {"cdmx": "09", "ciudad de mexico": "09", "df": "09", "distrito federal": "09", "edomex": "15",
           "estado de mexico": "15", "mexico": "15", "coahuila": "07", "michoacan": "16", "queretaro": "22",
           "veracruz": "30", "nuevo leon": "19"},
    "ec": {"cotopaxi": "07", "santo domingo de los tsachilas": None, "santa elena": None},
    "pe": {"lima metropolitana": "15", "lima provincias": "15", "callao": "07"},
}


# Palabras del título que no son versión ("Usado Peugeot 208 Automovil | Portillo").
GENERIC_WORDS = {"usado", "usada", "seminuevo", "seminueva", "nuevo", "nueva", "auto", "automovil", "venta", "vendo",
                 "oferta", "station", "wagon", "camioneta", "furgon", "suv", "suvs"}


# Región de las ciudades principales. El catálogo `cities` repite nombres (hay tres "Chiclayo" en Perú) y su
# `puntaje` no refleja población, así que sin región en el aviso estas ciudades se ubican por esta tabla.
CITY_STATE_HINTS = {
    "pe": {"lima": "15", "arequipa": "04", "trujillo": "13", "chiclayo": "14", "piura": "20", "cusco": "08",
           "cuzco": "08", "huancayo": "12", "iquitos": "16", "tacna": "23", "juliaca": "21", "puno": "21",
           "chimbote": "02", "huaraz": "02", "pucallpa": "25", "ica": "11", "cajamarca": "06", "ayacucho": "05",
           "tarapoto": "22", "moquegua": "18", "ilo": "18", "tumbes": "24", "huanuco": "10", "chincha": "11",
           "pisco": "11", "sullana": "20", "callao": "07"},
    "ec": {"quito": "18", "guayaquil": "10", "cuenca": "02", "ambato": "19", "manta": "14", "portoviejo": "14",
           "machala": "08", "loja": "12", "ibarra": "11", "riobamba": "06", "esmeraldas": "09", "latacunga": "07",
           "cumbaya": "18", "tumbaco": "18", "sangolqui": "18", "samborondon": "10", "duran": "10",
           "babahoyo": "13", "quevedo": "13", "tulcan": "05", "azogues": "04"},
    "mx": {"monterrey": "19", "guadalajara": "14", "zapopan": "14", "puebla": "21", "tijuana": "02",
           "leon": "11", "merida": "31", "queretaro": "22", "cancun": "23", "toluca": "15", "chihuahua": "06",
           "aguascalientes": "01", "hermosillo": "26", "culiacan": "25", "saltillo": "07", "morelia": "16",
           "san luis potosi": "24", "mexicali": "02", "torreon": "07", "veracruz": "30", "cuernavaca": "17",
           "san pedro garza garcia": "19", "naucalpan": "15", "ciudad juarez": "06"},
}


class Normalizer:
    def __init__(self, cur):
        """`cur` es un cursor de mysql.connector con dictionary=True."""
        self.states = {}      # (cc, alias) -> (state_id, adm)
        self.state_adm = {}   # (cc, adm) -> state_id
        self.state_of = {}    # state_id -> adm
        cur.execute("SELECT id, CC1, ADM_CODE, SHORTFORM, FULLNAME FROM states")
        for row in cur.fetchall():
            cc, adm = row["CC1"].lower(), row["ADM_CODE"]
            self.state_adm[(cc, adm)] = row["id"]
            self.state_of[row["id"]] = adm
            for name in (row["SHORTFORM"], row["FULLNAME"]):
                for alias in {norm(name), PLACE_PREFIXES.sub("", norm(name))}:
                    if alias:
                        self.states.setdefault((cc, alias), (row["id"], adm))
        for cc, aliases in STATE_ALIASES.items():
            for alias, adm in aliases.items():
                if adm and (cc, adm) in self.state_adm:
                    self.states[(cc, alias)] = (self.state_adm[(cc, adm)], adm)

        self.cities = {}  # (cc, name) -> [(city_id, adm, puntaje)]
        self.city_adm = {}
        cur.execute("SELECT id, CC1, ADM1, SHORTFORM, FULLNAME, puntaje FROM cities")
        for row in cur.fetchall():
            cc = row["CC1"].lower()
            self.city_adm[row["id"]] = row["ADM1"]
            for name in {norm(row["SHORTFORM"]), norm(row["FULLNAME"])}:
                if name:
                    self.cities.setdefault((cc, name), []).append((row["id"], row["ADM1"], row["puntaje"] or 0))

        self.make_name, self.model_name = {}, {}
        cur.execute("SELECT id_car_make, name FROM car_make")
        for row in cur.fetchall():
            self.make_name[row["id_car_make"]] = row["name"] or ""
        cur.execute("SELECT id_car_model, name FROM car_model")
        for row in cur.fetchall():
            self.model_name[row["id_car_model"]] = row["name"] or ""

    # --- segmentos ---------------------------------------------------------------------------

    @staticmethod
    def segments(details):
        """Segmentos cortos de details, sin montos ni kilometraje pegados ("47000 km Vitacura")."""
        out = []
        for raw in re.split(r"\s[|\-–]\s|\|", details or ""):
            s = raw.strip()
            if not s or len(s) > 60:
                continue
            s = re.sub(r"(u\$s|us\$|\$|usd|s/\.?)\s*[\d.,]+.*$", "", s, flags=re.I)
            s = re.sub(r"^[\d.,]+\s*kms?\b", "", s, flags=re.I)
            s = re.sub(r"^https?://schema\.org/", "", s, flags=re.I)
            s = s.strip(" :,")
            if s:
                out.append(s)
        return out

    @staticmethod
    def key_values(details):
        """{clave: valor} de details tipo "fuel:Diésel | region:13"."""
        out = {}
        for m in re.finditer(r"(?:^|\|)\s*([a-zA-Z_]+)\s*:\s*([^|]+)", details or ""):
            out[m.group(1).lower()] = m.group(2).strip()
        return out

    # --- atributos ---------------------------------------------------------------------------

    @staticmethod
    def _match(patterns, texts, skip=None):
        found = set()
        for text in texts:
            n = norm(text)
            if not n:
                continue
            for key, pattern in patterns:
                m = re.search(pattern, n)
                if m and not (skip and skip.match(m.group(0))):
                    found.add(key)
                    break  # el primero que calza en este texto
        return found.pop() if len(found) == 1 else None

    def fuel(self, segments, kv, title):
        texts = [kv[k] for k in ("fuel", "combustible") if k in kv] or segments
        return FUEL_IDS.get(self._match(FUEL_PATTERNS, texts) or self._match(FUEL_PATTERNS, [title], TITLE_SKIP_FUEL))

    def transmission(self, segments, kv, title):
        texts = [kv[k] for k in ("trans", "transmission", "transmision") if k in kv] or segments
        return TRANSMISSION_IDS.get(self._match(TRANSMISSION_PATTERNS, texts) or self._match(TRANSMISSION_PATTERNS, [title]))

    def body(self, segments, kv, title):
        texts = [kv[k] for k in ("body", "carroceria", "tipo") if k in kv] or segments
        return BODY_IDS.get(self._match(BODY_PATTERNS, texts) or self._match(BODY_PATTERNS, [title], TITLE_SKIP_BODY))

    # --- ubicación ---------------------------------------------------------------------------

    @staticmethod
    def _variants(n):
        """"region de valparaiso" -> también "valparaiso"; "sede san miguel" -> "san miguel"."""
        out = [n]
        for v in (PLACE_PREFIXES.sub("", n), LOCATION_KEYS.sub("", n)):
            v = PLACE_PREFIXES.sub("", v)
            v = re.sub(r"^(de|del|de la|de los)\s+", "", v)
            if v and v not in out:
                out.append(v)
        return out

    def location(self, country, candidates):
        """(state_id, city_id) de los textos candidatos; cada uno debe calzar entero."""
        cc = country.lower()
        names = []
        state_id = state_adm = None
        for raw in candidates:
            if not raw:
                continue
            region_only = str(raw).startswith("region:")
            if region_only:
                raw = raw[len("region:"):]
                if raw.strip().isdigit():  # autosusados: "region:13"
                    adm = raw.strip()
                    if (cc, adm) in self.state_adm and not state_id:
                        state_id, state_adm = self.state_adm[(cc, adm)], adm
                    continue
            n = norm(raw)
            if not n or n in NOT_A_PLACE or re.search(r"\d", n) or len(n) > 45:
                continue
            variants = self._variants(n)
            if not region_only:
                names.append(variants)
            for v in variants:
                if (cc, v) in self.states and not state_id:
                    state_id, state_adm = self.states[(cc, v)]
        if not state_id:
            for variants in names:
                adm = next((CITY_STATE_HINTS.get(cc, {}).get(v) for v in variants if v in CITY_STATE_HINTS.get(cc, {})), None)
                if adm and (cc, adm) in self.state_adm:
                    state_id, state_adm = self.state_adm[(cc, adm)], adm
                    break

        # Ciudades: un nombre que es solo la región ("Lima", "Santiago") vale menos que un distrito o
        # comuna del mismo aviso ("La Molina | Lima").
        found = []
        for variants in names:
            if state_adm:
                # MercadoLibre MX usa el nombre largo del municipio ("Tlalnepantla De Baz", "San Pedro Garza
                # García", "La Magdalena Contreras") y el catálogo el corto; con la región ya fija se prueba
                # también el corto.
                variants = variants + [x for v in variants for x in (re.sub(r"\s+de\s+\w+$", "", v),
                                                                     re.sub(r"^san pedro\s+", "", v),
                                                                     re.sub(r"^(la|el|los|las)\s+", "", v)) if x != v]
            for v in variants:
                options = self.cities.get((cc, v)) or []
                options = sorted(options, key=lambda o: -o[2])
                if state_adm:
                    # Con región conocida: la ciudad de ese nombre en esa región (si hay dos, la de más puntaje).
                    options = [o for o in options if o[1] == state_adm]
                elif not (options and options[0][2] >= 5 and (len(options) == 1 or options[0][2] >= 3 * options[1][2])):
                    # Sin región: solo si el nombre es claramente una ciudad conocida; si no, no se adivina.
                    options = []
                if options:
                    found.append((any((cc, x) in self.states for x in variants), options[0]))
                    break
        if not found:
            return state_id, None
        found.sort(key=lambda f: f[0])  # primero los que no son nombre de región
        city_id, adm = found[0][1][0], found[0][1][1]
        if not state_id or state_adm != adm:
            state_id = self.state_adm.get((cc, adm), state_id)
        return state_id, city_id

    # --- versión -----------------------------------------------------------------------------

    def version(self, title, kv, make_id, model_id):
        if not model_id:
            return None  # sin modelo reconocido no se sabe qué parte del título es la versión
        if kv.get("version"):
            text = kv["version"]
        else:
            text = re.split(r"\s\|\s", title or "")[0]
        drop = set()
        for name in (self.make_name.get(make_id), self.model_name.get(model_id)):
            drop.update(norm(name).split())
        drop.update(GENERIC_WORDS)
        kept = []
        for token in (text or "").split():
            n = norm(token)
            if not n or all(w in drop for w in n.split()) or re.fullmatch(r"(19|20)\d\d", n):
                continue
            if re.fullmatch(r"\(?\d{5,}\)?", token) or token.startswith("$"):
                continue
            kept.append(token)
        version = " ".join(kept).strip(" -|,.!")
        if not version or len(version) > 100 or not re.search(r"[A-Za-z0-9]", version):
            return None
        return version

    # --- todo junto --------------------------------------------------------------------------

    def extract(self, country, title, details, location=None, make_id=None, model_id=None):
        segs = self.segments(details)
        kv = self.key_values(details)
        loc_candidates = []
        if location:
            loc_candidates += [p for p in re.split(r"[|,]", str(location))]
        for key in ("region", "ciudad", "city", "comuna", "provincia", "estado", "sede", "ubicacion"):
            if key in kv:
                loc_candidates.append(f"region:{kv[key]}" if key in ("region", "estado", "provincia") else kv[key])
        loc_candidates += [s for s in segs if ":" not in s]
        state_id, city_id = self.location(country, loc_candidates)
        return {
            "id_state": state_id,
            "id_city": city_id,
            "id_fuel": self.fuel(segs, kv, title or ""),
            "id_transmission": self.transmission(segs, kv, title or ""),
            "id_body": self.body(segs, kv, title or ""),
            "version": self.version(title, kv, make_id, model_id),
        }
