#!/usr/bin/env python3
import os
import re
import unicodedata

import mysql.connector


def norm(s: str) -> str:
    if not s:
        return ""
    s = str(s).strip().lower()
    s = unicodedata.normalize("NFKD", s)
    s = "".join(ch for ch in s if not unicodedata.combining(ch))
    s = re.sub(r"[^a-z0-9]+", " ", s)
    return " ".join(s.split())


def clean_token(s: str) -> str:
    if not s:
        return ""
    s = re.sub(r"u\$s.*$", "", s, flags=re.I).strip()
    s = re.sub(r"^hacienda\s+", "", s, flags=re.I).strip()
    s = re.sub(r"\(.*?\)", "", s).strip()
    return s


def parse_city_state_from_details(details: str):
    if not details:
        return None, None

    # Pattern from topmotors: "Ciudad: Quito | Kilometraje: ..."
    m_city = re.search(r"ciudad\s*:\s*([^|]+)", details, flags=re.I)
    city_hint = clean_token(m_city.group(1)) if m_city else None

    # Pattern from autocosmos: "... | CITY | STATE u$sXX"
    if "|" in details:
        parts = [clean_token(p.strip()) for p in details.split("|") if p.strip()]
        if len(parts) >= 2:
            city = parts[-2]
            state = parts[-1]
            if state and re.search(r"\b(km|kms|kilomet)\b", state, re.I):
                state = parts[-2] if len(parts) >= 2 else None
                city = parts[-3] if len(parts) >= 3 else None
            return city or city_hint, state

    return city_hint, None


def main():
    conn = mysql.connector.connect(
        host=os.getenv("MYSQL_HOST", "localhost"),
        port=int(os.getenv("MYSQL_PORT", "3306")),
        user=os.getenv("MYSQL_USER", "trikio"),
        password=os.getenv("MYSQL_PASSWORD", ""),
        database=os.getenv("MYSQL_DATABASE", "roodos"),
    )
    cur = conn.cursor(dictionary=True)

    # states map
    cur.execute("SELECT id, ADM_CODE, SHORTFORM, FULLNAME FROM states WHERE CC1='EC'")
    state_rows = cur.fetchall()
    state_by_name = {}
    for s in state_rows:
        sid = s["id"]
        adm = s["ADM_CODE"]
        for v in [s["SHORTFORM"], s["FULLNAME"]]:
            n = norm(v)
            if n:
                state_by_name[n] = (sid, adm)

    # city map by (adm, name) and global name frequency
    cur.execute("SELECT id, ADM1, SHORTFORM, FULLNAME FROM cities WHERE CC1='EC'")
    city_rows = cur.fetchall()
    city_by_adm_name = {}
    city_name_global = {}
    city_short_global = {}
    for c in city_rows:
        cid = c["id"]
        adm = c["ADM1"]
        s_short = norm(c["SHORTFORM"])
        s_full = norm(c["FULLNAME"])
        names = [s_short, s_full]
        for n in names:
            if not n:
                continue
            city_by_adm_name[(adm, n)] = cid
            city_name_global.setdefault(n, set()).add(cid)
        if s_short:
            city_short_global.setdefault(s_short, set()).add(cid)

    cur.execute(
        """
        SELECT id, url, details, id_state, id_city
        FROM cars
        WHERE id_country='EC' AND (id_state IS NULL OR id_city IS NULL)
        """
    )
    rows = cur.fetchall()

    upd_state = 0
    upd_city = 0

    for r in rows:
        cid = r["id"]
        details = r.get("details") or ""
        id_state = r.get("id_state")
        id_city = r.get("id_city")

        city_txt, state_txt = parse_city_state_from_details(details)

        found_state = id_state
        found_adm = None
        if not found_state and state_txt:
            nstate = norm(state_txt)
            if nstate in state_by_name:
                found_state, found_adm = state_by_name[nstate]

        if found_state and not found_adm:
            # recover ADM code from state id
            for s in state_rows:
                if s["id"] == found_state:
                    found_adm = s["ADM_CODE"]
                    break

        found_city = id_city
        if not found_city and city_txt:
            ncity = norm(city_txt)
            # best: city constrained by resolved state
            if found_adm and (found_adm, ncity) in city_by_adm_name:
                found_city = city_by_adm_name[(found_adm, ncity)]
            else:
                # fallback by unique SHORTFORM globally, then any unique name
                ids_short = city_short_global.get(ncity, set())
                if len(ids_short) == 1:
                    found_city = next(iter(ids_short))
                else:
                    ids = city_name_global.get(ncity, set())
                    if len(ids) == 1:
                        found_city = next(iter(ids))

        if (found_state and not id_state) or (found_city and not id_city):
            cur.execute(
                "UPDATE cars SET id_state=COALESCE(id_state,%s), id_city=COALESCE(id_city,%s) WHERE id=%s",
                (found_state, found_city, cid),
            )
            if found_state and not id_state:
                upd_state += 1
            if found_city and not id_city:
                upd_city += 1

    conn.commit()
    print(f"updated_state={upd_state} updated_city={upd_city}")

    cur.execute("SELECT COUNT(*) total, SUM(id_state IS NULL) null_state, SUM(id_city IS NULL) null_city FROM cars WHERE id_country='EC'")
    print(cur.fetchone())

    cur.close()
    conn.close()


if __name__ == "__main__":
    main()
