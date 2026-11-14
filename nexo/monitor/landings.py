"""Ciclo semanal de landings: crea las de marca + modelo + año que el inventario justifica y limpia las demás.

Corre en nexo, que es dueño de la base `roodos`: escribe en su MySQL y producción toma los cambios en su
reindexación nocturna de landings (3:00) y regenera los sitemaps (3:30). El servidor web no ejecuta nada.

La lógica está en los comandos de Laravel del repo (landings:candidates y landings:clean), los mismos que
usa la web para clasificar títulos. Para contar avisos igual que la web, nexo tiene su propio Manticore,
configurado desde server-conf/manticoresearch/manticore.conf (ver `bin/nexo landings --setup`).
"""

import json
import os
import re
import subprocess
import tempfile
from datetime import datetime

from . import db, telegram
from .config import LOG_DIR, ROOT

REPO = ROOT.parent
COUNTRIES = ["cl", "mx", "pe", "ec"]
PHP = os.getenv("NEXO_PHP", "php")
INDEXER = os.getenv("NEXO_INDEXER", "indexer")

MANTICORE_DIR = ROOT / "manticore"
MANTICORE_CONF = MANTICORE_DIR / "manticore.conf"
OUTPUT_DIR = LOG_DIR / "landings"

# Si una semana la limpieza quiere redirigir o eliminar más que esta fracción de las landings activas de un
# país, no se aplica sola: casi siempre es un problema (un scraper caído, un índice vacío), no basura real.
MAX_CHANGE = 0.02

# El cron semanal crea las landings nuevas; con LANDINGS_AUTO_APPLY=0 en .env solo avisa.
AUTO_APPLY = os.getenv("LANDINGS_AUTO_APPLY", "1") == "1"

# Bajar landings (301 o 410) es otra decisión: solo con LANDINGS_AUTO_CLEAN=1 o --apply-clean. Mientras
# tanto la limpieza solo informa qué haría.
AUTO_CLEAN = os.getenv("LANDINGS_AUTO_CLEAN", "") == "1"

# Cada limpieza aplicada respalda las landings del país en car_landings_backup_<país>_<fecha>.
BACKUP_DAYS = 56

# México tiene ~2 millones de landings.
MEMORY = {"mx": "4G"}


def setup():
    """Genera la config de Manticore de nexo desde la de producción: misma definición de índices, pero
    leyendo el MySQL local y con datos, logs y pid en la carpeta de nexo (corre como servicio de usuario)."""
    source = (REPO / "server-conf" / "manticoresearch" / "manticore.conf").read_text()
    data = MANTICORE_DIR / "data"
    replacements = [
        (r"(sql_host\s*=\s*).*", rf"\g<1>{os.getenv('MYSQL_HOST', '127.0.0.1')}"),
        (r"(sql_port\s*=\s*).*", rf"\g<1>{os.getenv('MYSQL_PORT', '3306')}"),
        (r"(sql_user\s*=\s*).*", rf"\g<1>{os.getenv('MYSQL_USER', 'trikio')}"),
        (r"(sql_pass\s*=\s*).*", lambda m: m.group(1) + os.getenv("MYSQL_PASSWORD", "")),
        (r"/var/lib/manticore/", f"{data}/"),
        (r"/var/log/manticore/", f"{LOG_DIR}/"),
        (r"/var/run/manticore/", f"{MANTICORE_DIR}/"),
    ]
    for pattern, replacement in replacements:
        source = re.sub(pattern, replacement, source)

    data.mkdir(parents=True, exist_ok=True)
    LOG_DIR.mkdir(exist_ok=True)
    MANTICORE_CONF.write_text(source)
    MANTICORE_CONF.chmod(0o600)  # lleva la clave de MySQL
    print(f"config de Manticore: {MANTICORE_CONF}")


def run(countries=None, apply=None, apply_clean=None, force=False):
    countries = countries or COUNTRIES
    apply = AUTO_APPLY if apply is None else apply
    apply_clean = AUTO_CLEAN if apply_clean is None else apply_clean
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    mode = f"crear: {'sí' if apply else 'solo reporte'}, bajar: {'sí' if apply_clean else 'solo reporte'}"
    lines = [f"Landings semanales ({mode}) {datetime.now():%Y-%m-%d}"]

    try:
        reindex(countries)
    except subprocess.CalledProcessError as exc:
        telegram.send(f"Landings: falló la reindexación de nexo ({exc.returncode}); no se hizo nada.")
        raise

    for country in countries:
        try:
            lines += country_cycle(country, apply, apply_clean, force)
        except Exception as exc:  # un país que falla no frena a los demás
            lines.append(f"[{country}] ERROR: {exc.__class__.__name__}: {exc}")

    if apply_clean:
        dropped = drop_old_backups()
        if dropped:
            lines.append(f"\nRespaldos de más de {BACKUP_DAYS} días borrados: {dropped}")
    if not apply:
        lines.append("Creación desactivada (LANDINGS_AUTO_APPLY=0): bin/nexo landings --apply para crearlas.")
    report = "\n".join(lines)
    print(report)
    telegram.send(report)


def country_cycle(country, apply, apply_clean, force):
    lines = [""]

    # 1. Crear o reactivar las landings marca + modelo + año nuevas.
    candidates = artisan("landings:candidates", country, "--sql")
    created = candidates["create"]
    lines.append(f"[{country}] Nuevas marca + modelo + año: {created} (de {candidates['candidates']} con avisos suficientes)")
    if candidates["titles"]:
        lines.append("  " + ", ".join(candidates["titles"][:8]) + ("…" if created > 8 else ""))
    if apply and created:
        mysql(candidates["sql"])
        lines.append("  creadas")

    # 2. Limpiar. Las recién creadas no están en el índice todavía y además tienen semanas de gracia.
    clean = artisan("landings:clean", country, "--no-sitemaps")
    changes = clean["redirect"] + clean["deactivate"]
    share = changes / clean["active"] if clean["active"] else 0
    lines.append(
        f"[{country}] Limpieza: {changes} de {clean['active']} activas ({share:.1%}): "
        f"{clean['redirect']} con 301, {clean['deactivate']} con 410"
    )
    if apply_clean and changes:
        if share > MAX_CHANGE and not force:
            lines.append(f"  NO aplicada: supera el {MAX_CHANGE:.0%}. Revisar {clean['sql']} y aplicar con --force.")
        else:
            mysql(clean["sql"])
            lines.append("  aplicada")

    return lines


def reindex(countries):
    """Reindexa en nexo los índices que leen los comandos, con los datos de su MySQL."""
    indexes = ["car_make", "car_model"]
    for country in countries:
        indexes += [f"car_{country}", f"car_landings_{country}"]
    subprocess.run([INDEXER, "--config", str(MANTICORE_CONF), "--rotate", "--quiet", *indexes], check=True)


def artisan(command, country, *options):
    """Corre un comando de Laravel y devuelve el resumen JSON que deja junto a su SQL."""
    directory = tempfile.mkdtemp(prefix=f"{country}-", dir=OUTPUT_DIR)
    result = subprocess.run(
        [PHP, "artisan", command, country, f"--output={directory}", f"--memory={MEMORY.get(country, '2G')}", *options],
        cwd=REPO, capture_output=True, text=True,
    )
    if result.returncode != 0:
        tail = (result.stderr or result.stdout).strip().splitlines()[-3:]
        raise RuntimeError(f"{command} salió con {result.returncode}: {' / '.join(tail)}")
    summaries = sorted(p for p in os.listdir(directory) if p.endswith(".json"))
    with open(os.path.join(directory, summaries[-1])) as summary:
        return json.load(summary)


def mysql(sql_path):
    """Aplica un SQL de los comandos (con su propia transacción) en la base `roodos` de nexo."""
    with open(sql_path) as sql:
        subprocess.run(
            ["mysql", "-h", os.getenv("MYSQL_HOST", "localhost"), "-P", os.getenv("MYSQL_PORT", "3306"),
             "-u", os.getenv("MYSQL_USER", "trikio"), os.getenv("MYSQL_DATABASE", "roodos")],
            stdin=sql, check=True, env={**os.environ, "MYSQL_PWD": os.getenv("MYSQL_PASSWORD", "")},
        )


def drop_old_backups():
    """Borra los respaldos de landings:clean más antiguos que BACKUP_DAYS (su nombre lleva la fecha)."""
    conn = db.connect()
    limit = datetime.now().timestamp() - BACKUP_DAYS * 86400
    dropped = []
    for row in db.all(conn, "SHOW TABLES LIKE 'car_landings_backup_%'"):
        table = next(iter(row.values()))
        match = re.search(r"_(\d{8})_\d{6}$", table)
        if match and datetime.strptime(match.group(1), "%Y%m%d").timestamp() < limit:
            db.execute(conn, f"DROP TABLE `{table}`")
            dropped.append(table)
    conn.close()
    return len(dropped)
