import argparse
import os
import subprocess
import sys

from . import cron, daily, db, hunt, images, runner, sources
from .config import ROOT
from .fmt import day, label, num


def main():
    parser = argparse.ArgumentParser(prog="nexo", description="Scrapers de Roodos y su monitoreo.")
    commands = parser.add_subparsers(dest="command", required=True)
    run = commands.add_parser("run", help="ejecuta una fuente y la registra")
    run.add_argument("source", choices=sorted(sources.BY_ID))
    sources_parser = commands.add_parser("sources", help="lista las fuentes con su última ejecución")
    sources_parser.add_argument("--ids", action="store_true", help="solo los ids de las fuentes activas")
    commands.add_parser("daily", help="foto diaria, vencidos, alertas y resumen por Telegram")
    commands.add_parser("images", help="revisa por muestreo las imágenes de los avisos activos")
    commands.add_parser("hunt", help="busca fuentes candidatas en internet")
    cron_parser = commands.add_parser("cron", help="instala el bloque de nexo en el crontab")
    cron_parser.add_argument("--print", action="store_true", help="solo muestra el crontab resultante")
    commands.add_parser("update", help="trae los cambios del repo y reinstala si hubo")
    commands.add_parser("db-init", help="crea o completa la base nexo")
    args = parser.parse_args()

    if args.command == "run":
        outcome = runner.run(sources.BY_ID[args.source])
        sys.exit(0 if outcome in (None, "ok") else 1)
    elif args.command == "sources":
        if args.ids:
            print("\n".join(source.id for source in sources.SOURCES if source.enabled))
        else:
            list_sources()
    elif args.command == "daily":
        daily.daily()
    elif args.command == "images":
        images.run()
    elif args.command == "hunt":
        hunt.run()
    elif args.command == "cron":
        if args.print:
            current = subprocess.run(["crontab", "-l"], capture_output=True, text=True).stdout
            print(cron.merged(current), end="")
        else:
            cron.install()
    elif args.command == "update":
        update()
    elif args.command == "db-init":
        db_init()


def list_sources():
    conn = db.connect()
    for source in sources.SOURCES:
        last = db.one(
            conn,
            "SELECT started_at, outcome, seen FROM nexo.runs WHERE source_id=%s AND finished_at IS NOT NULL "
            "ORDER BY started_at DESC LIMIT 1",
            (source.id,),
        )
        status = f"{last['outcome']:<6} {num(last['seen']):>7} el {day(last['started_at'])}" if last else "sin ejecuciones"
        print(f"{source.id:<32} {label(source):<26} {source.schedule:<12} {status}")


def update():
    """Trae main y, si cambió algo, reinstala el crontab y reinicia el dashboard."""
    repo = ROOT.parent
    head = lambda: subprocess.run(["git", "-C", repo, "rev-parse", "HEAD"], capture_output=True, text=True).stdout.strip()
    before = head()
    subprocess.run(["git", "-C", repo, "pull", "--ff-only", "-q"], check=True)
    if head() == before:
        return
    print(f"actualizado {before[:7]} -> {head()[:7]}")
    # En un proceso nuevo: este todavía tiene cargado el código anterior al pull.
    subprocess.run([ROOT / "bin" / "nexo", "cron"], check=True)
    subprocess.run(["systemctl", "--user", "restart", "nexo-dashboard"])


def db_init():
    with open(ROOT / "sql" / "nexo.sql") as sql:
        subprocess.run(
            ["mysql", "-h", os.getenv("MYSQL_HOST", "localhost"), "-P", os.getenv("MYSQL_PORT", "3306"),
             "-u", os.getenv("MYSQL_USER", "trikio")],
            stdin=sql, check=True, env={**os.environ, "MYSQL_PWD": os.getenv("MYSQL_PASSWORD", "")},
        )
    conn = db.connect()
    sources.sync(conn)
    conn.close()
    print("base nexo lista")


if __name__ == "__main__":
    main()
