#!/usr/bin/env python3
import os
import mysql.connector
from dotenv import load_dotenv


def fix_text(s: str) -> str:
    if not s:
        return s
    s = str(s)
    try:
        repaired = s.encode("latin1").decode("utf-8")
        if repaired.count("�") <= s.count("�"):
            return repaired
    except Exception:
        pass
    return s


def main():
    load_dotenv('/home/trikio/roodos_v4/nexo/.env')
    conn = mysql.connector.connect(
        host=os.getenv("MYSQL_HOST", "localhost"),
        port=int(os.getenv("MYSQL_PORT", "3306")),
        user=os.getenv("MYSQL_USER", "trikio"),
        password=os.getenv("MYSQL_PASSWORD", ""),
        database=os.getenv("MYSQL_DATABASE", "roodos"),
        autocommit=False,
    )
    cur = conn.cursor(dictionary=True)

    cur.execute("SELECT id,title,details FROM houses WHERE nexo_id='Doomos' AND (title LIKE '%Ã%' OR details LIKE '%Ã%')")
    rows = cur.fetchall()
    upd = 0
    for r in rows:
        new_title = fix_text(r.get("title") or "")
        new_details = fix_text(r.get("details") or "")
        if new_title != (r.get("title") or "") or new_details != (r.get("details") or ""):
            cur.execute("UPDATE houses SET title=%s, details=%s, updated=NOW() WHERE id=%s", (new_title[:250], new_details, r["id"]))
            upd += 1
        if upd and upd % 5000 == 0:
            conn.commit()
            print("updated", upd)

    conn.commit()
    print({"scanned": len(rows), "updated": upd})
    cur.close(); conn.close()


if __name__ == '__main__':
    main()
