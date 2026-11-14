# Nexo: scrapers de Roodos

Nexo es la máquina Ubuntu de la casa (`192.168.1.27`, usuario `trikio`) que scrapea los avisos de autos y casas. Los guarda en su MySQL local (base `roodos`), y producción los lee por el túnel SSH reverso ([server-conf/tunnel](../server-conf/tunnel/mysql-reverse-tunnel.service)) y reindexa Manticore cada hora.

```
fuentes ──scrapers──▶ MySQL de nexo (roodos.cars) ──túnel 3307──▶ producción (Manticore)
                              │
                              └── base nexo: ejecuciones, fotos diarias, alertas, imágenes, candidatas
```

El código vive en `/home/trikio/roodos_v4/nexo`, un checkout parcial de este repo. Cada 10 minutos `bin/nexo update` trae `main` y, si cambió algo, reinstala el crontab y reinicia el dashboard: **hacer push a `main` también despliega nexo.**

## Fuentes

Todas las fuentes de autos están en [monitor/sources.py](monitor/sources.py): país, hosts de sus URLs, horario y comando. De ahí salen el crontab y la tabla `nexo.sources`. Para agregar una fuente: escribir el spider en `roodos_scraper/spiders/`, sumarla al registro y hacer push.

Cada aviso se reconoce por su país (`cars.id_country`) y el host de su URL; `cars` no tiene una columna de fuente. Todos los scrapers ponen `updated = NOW()` en cada aviso que guardan, así que "visto" significa `updated` reciente.

## Automotoras chicas

Las automotoras con sitio propio no tienen un spider cada una: las recorre el spider genérico [roodos_scraper/spiders/dealers.py](roodos_scraper/spiders/dealers.py), que encuentra las fichas por los sitemaps y los enlaces de la portada y solo arma un aviso si la ficha publica datos estructurados de schema.org (JSON-LD o microdatos; precio y km también desde `og:description`, como en la plataforma RT Automotriz). Descarta listados, avisos sin precio o año, y monedas que no son del país.

Hay una fuente por país (`automotoras_cl`, `_mx`, `_pe`, `_ec`) que corre los domingos y miércoles sobre los sitios activos de `nexo.dealer_sites`. La regla de vencidos se aplica sitio por sitio: si un sitio falla, sus avisos no se dan por vencidos.

El flujo:

1. `hunt` (9:30) busca "automotora + ciudad" y variantes, en rotación semanal, y deja los sitios en `nexo.candidates`.
2. `dealers probe` (10:00) prueba hasta 25 candidatas con el spider genérico, sin escribir en la base. Con 5 avisos válidos o más pasan a `nexo.dealer_sites` como `review`; si no, quedan como `manual` (necesitan un spider propio, que se escribe a pedido).
3. Los lunes (9:00) llega por Telegram la lista para aprobar, y se aprueban en el dashboard o con `bin/nexo dealers approve <host>` (o `--all`).

## Comandos

```bash
bin/nexo run <fuente>   # ejecuta una fuente y la registra (es lo que llama el cron)
bin/nexo sources        # fuentes con su última ejecución
bin/nexo daily          # foto diaria, vencidos, alertas y resumen por Telegram (8:00)
bin/nexo images         # revisa por muestreo las imágenes de los avisos activos (6:00)
bin/nexo hunt           # busca sitios candidatos en internet (9:30)
bin/nexo dealers probe|review|list|approve|reject|pause [hosts] [--all]   # automotoras chicas
bin/nexo deactivate <fuente> [--yes]   # desactiva los avisos de una fuente que ya no se puede actualizar
bin/nexo cron [--print] # instala el bloque de nexo en el crontab
bin/nexo update         # git pull y reinstalación si hubo cambios (cada 10 min)
bin/nexo db-init        # crea o completa la base nexo
```

## Monitoreo

La base `nexo` ([sql/nexo.sql](sql/nexo.sql)) está separada de `roodos`:

- `runs`: cada ejecución, con avisos vistos y nuevos (contados en `cars`, no en los logs), código de salida y última línea de error. El resultado es `ok`, `empty` (terminó sin avisos), `drop` (menos de la mitad de la mediana de las últimas 3 buenas) o `failed`.
- `daily_stats`: por fuente y día, avisos activos, vistos y nuevos en 24 h, sin verse en 30 días, desactivados e imágenes rotas.
- `alerts`: una abierta por tipo y fuente. Las de ejecución (`run`) se mandan a Telegram al abrirse y al resolverse; las diarias (`overdue`, `images`) van en el resumen. `tunnel` y `disk` avisan apenas se detectan.
- `image_checks`: 20 avisos activos por fuente al día; se revisan la miniatura en R2 (`image`) y la original en la fuente (`image_big`).
- `candidates`: sitios encontrados por `hunt`, con su puntaje.

Una fuente está **atrasada** cuando no ve avisos dentro de su frecuencia (8 días si corre semanal, 2 si es diaria).

Alertas y resumen llegan por la API de bots de Telegram (`TELEGRAM_BOT_TOKEN` y `TELEGRAM_CHAT_ID` en `.env`), sin pasar por OpenClaw.

El dashboard está en `http://192.168.1.27:8765` (solo red local), como servicio de usuario: [systemd/nexo-dashboard.service](systemd/nexo-dashboard.service).

## Avisos vencidos

Un aviso activo que su fuente no ve en 30 días pasa a `status = 0` y sale de Manticore en la siguiente reindexación. Solo se aplica a fuentes con `full_crawl=True`, y solo si la fuente está al día. Con tope de páginas, los avisos que quedan más allá del tope nunca se ven y se darían por vencidos sin estarlo, así que cada fuente se marca después de confirmar que recorre su listado completo.

## Landings (semanal)

Los domingos a la 1:00, `bin/nexo landings` hace el ciclo de las landings de autos de cada país, sobre la base `roodos` de nexo:

1. Reindexa el Manticore de nexo (`car_make`, `car_model`, `car_{país}`, `car_landings_{país}`).
2. `php artisan landings:candidates {país} --sql`: crea, o reactiva si el slug ya existía, una landing por cada modelo y año con 8 avisos o más que todavía no tiene la suya (`/kia-sportage-2024`). Guardan en `content` sus filtros y su fecha de creación, y la web lista exactamente ese modelo y año.
3. `php artisan landings:clean {país}`: deja solo las búsquedas reales con 5 avisos o más; el resto va con 301 a su padre o con 410. Las creadas en el paso 2 tienen 4 semanas de gracia y después, con menos de 5 avisos, van con 301 a su modelo.
4. Manda el resumen por Telegram. Producción toma los cambios en su reindexación de las 3:00 y en los sitemaps de las 3:30.

Deja SQL, CSV y JSON de cada paso en `logs/landings/`. Crear y bajar landings son permisos separados:

- **Crear** las nuevas: activado. Con `LANDINGS_AUTO_APPLY=0` en `.env` el cron solo reporta, y se crean a mano con `bin/nexo landings --apply`.
- **Bajar** landings (301 o 410): desactivado. La limpieza solo informa qué haría; se aplica con `bin/nexo landings --apply-clean`, o `LANDINGS_AUTO_CLEAN=1` para que el cron lo haga solo.

Freno: si en un país la limpieza quiere redirigir o eliminar más del 2% de las landings activas, no se aplica (salvo `--apply-clean --force`). La primera limpieza de un país que nunca se limpió supera eso de lejos y se aplica a mano, revisando el CSV. Cada limpieza aplicada respalda las landings del país en `car_landings_backup_{país}_{fecha}` (el SQL trae cómo revertir); los respaldos de más de 8 semanas se borran.

La lógica vive en los comandos de Laravel del repo, así que nexo tiene el repo completo, PHP y su propio Manticore. Así quedó instalado (noviembre de 2026, sin `sudo`):

- **PHP 8.4** (`/usr/bin/php8.4`) corre los comandos: el proyecto pide 8.4 y el `php` por defecto (8.5) no tiene `mysqli`. A 8.4 le faltan `mbstring` (lo cubre `symfony/polyfill-mbstring`), `dom` (solo para correos) y `zip`.
- **Composer** en `~/.local/share/composer/composer.phar`, corrido con **PHP 8.3**, que sí tiene `zip`.
- En `nexo/.env`: `NEXO_PHP=php8.4`, `NEXO_COMPOSER=php8.3 ~/.local/share/composer/composer.phar` y `COMPOSER_IGNORE_PLATFORM_REQ=php,ext-mbstring,ext-dom`.
- `.env` de Laravel en la raíz del repo: `APP_KEY`, `CACHE_STORE=file`, `MANTICORE_HOST=127.0.0.1`, `MANTICORE_PORT=9306`.
- Manticore como servicio de usuario, `nexo-manticore` (el de sistema sigue apagado).

Para rehacerlo en otra máquina:

```bash
cd ~/roodos_v4 && git sparse-checkout disable          # el repo completo, no solo nexo/
# Composer (verificar la firma del instalador) en ~/.local/share/composer/composer.phar
COMPOSER_IGNORE_PLATFORM_REQ=php,ext-mbstring,ext-dom php8.3 ~/.local/share/composer/composer.phar install --no-dev
printf 'APP_ENV=production\nAPP_KEY=\nCACHE_STORE=file\nMANTICORE_HOST=127.0.0.1\nMANTICORE_PORT=9306\n' > .env && php8.4 artisan key:generate
nexo/bin/nexo landings --setup                         # nexo/manticore/manticore.conf desde la de producción
indexer --config nexo/manticore/manticore.conf car_make car_model car_cl car_mx car_pe car_ec car_landings_cl car_landings_mx car_landings_pe car_landings_ec
systemctl --user enable --now ~/roodos_v4/nexo/systemd/nexo-manticore.service
nexo/bin/nexo cron                                     # agrega la línea del domingo (solo con la config ya generada)
```

`bin/nexo update` corre `composer install` cuando cambia `composer.lock` y regenera la config de Manticore cuando cambia la de producción.

## Configuración

`.env` no está en el repo; las claves están en [.env.example](.env.example): MySQL local, proxies de Webshare, Cloudflare R2 para las miniaturas y Telegram.

## Instalación en una máquina nueva

```bash
git clone --filter=blob:none --sparse git@github.com:trikio/roodosV4.git ~/roodos_v4
cd ~/roodos_v4 && git sparse-checkout set nexo server-conf/tunnel
pip install --user -r nexo/requirements.txt
~/.local/bin/patchright install chromium   # browser de scrapling para Seminuevos, Bruno Fritsch
cp nexo/.env.example nexo/.env    # y completarlo
nexo/bin/nexo db-init && nexo/bin/nexo cron
systemctl --user enable --now ~/roodos_v4/nexo/systemd/nexo-dashboard.service   # requiere loginctl enable-linger
```

El túnel se instala como servicio del sistema según [server-conf/tunnel](../server-conf/tunnel/mysql-reverse-tunnel.service).

## WiFi

La máquina se conecta por un adaptador USB Realtek RTL8821CU. Arranca en modo "disco" (`0bda:1a2b`) y hay que pasarlo a modo WiFi; si tarda, `wpa_supplicant` se rinde y la máquina queda sin red. [server/fix-wifi.sh](server/fix-wifi.sh) (con `sudo`) lo deja estable: cambio de modo inmediato, `wpa_supplicant` atado a la aparición de la interfaz, un watchdog cada 3 minutos y el driver (`~/RTL8811CU`) con DKMS para que sobreviva a las actualizaciones del kernel. La máquina tiene dos puertos de red (`eno1`, `enp2s0`): un cable lo resolvería del todo.
