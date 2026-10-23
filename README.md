# Roodos V4

Buscador de clasificados (autos y casas) multi-país sobre Laravel 12 + Octane (Swoole), con Manticore Search como motor de búsqueda.

## Dominios

| Host | Contenido |
| --- | --- |
| `roodos.com` | Home global y páginas informativas |
| `roodos.{país}` | Home del país |
| `www.roodos.{país}` | Redirección 301 a `autos.roodos.{país}` |
| `autos.roodos.{país}` | Búsqueda, marca, modelo, detalle y landings SEO (`/{slug}`) |
| `casas.roodos.{país}` | Búsqueda, detalle y landings de propiedades |

Países configurados en [config/countries.php](config/countries.php): `cl`, `ec`, `pe`, `mx` y `lab` (desarrollo).

## Búsqueda

Toda la búsqueda pasa por [app/Services/ManticoreSearchService.php](app/Services/ManticoreSearchService.php). Los índices se definen en [server-conf/manticoresearch/manticore.conf](server-conf/manticoresearch/manticore.conf) y se alimentan desde MySQL:

- `car_{país}`: avisos de autos (reindexado cada hora, ver [server-conf/cron/crontab](server-conf/cron/crontab)).
- `car_landings_{país}`: landings SEO (reindexado diario a las 3 AM).
- `car_make`, `car_model`: catálogos para `/marca/{slug}` y `/modelo/{slug}`.

Conexión configurable con `MANTICORE_HOST` y `MANTICORE_PORT` (ver `config/services.php`). Para probarla:

```bash
php artisan manticore:test
```

## Landings y sitemaps

Las landings (`car_landings` en MySQL) son data heredada: nadie las genera ni actualiza, y en su mayoría son texto de avisos convertido en URL. La limpieza deja solo búsquedas reales (marca, modelo, lugar, año) con avisos suficientes:

```bash
php artisan landings:clean all     # o un país: pe, cl, ec, mx. Lee Manticore; no modifica la base
```

Por país genera en `storage/app/landings/` un `.sql` (con respaldo de la tabla al inicio) y un `.csv` con la acción de cada landing, y en `storage/app/sitemaps/{país}/removed-*.xml` la lista de URLs eliminadas o redirigidas para que Google las vuelva a rastrear pronto. Después de revisarlos:

1. Aplicar el `.sql` en MySQL.
2. `sudo indexer --rotate car_landings_{país}`, `touch storage/app/sitemaps/{país}/.limpieza-aplicada` y `php artisan sitemaps:generate {país}` (el cron lo repite cada noche). Sin ese archivo el sitemap del país no se genera, para no publicar landings sin limpiar.
3. Enviar `https://autos.roodos.{país}/sitemap.xml` en Search Console.
4. Borrar `removed-*.xml` cuando Search Console muestre esas URLs fuera del índice (1-2 meses).

Las landings desactivadas responden 410; `/marca/x` y `/modelo/x` redirigen a `autos-x-usados` cuando existe.

## Monitoreo y túnel de la base

La base MySQL de roodos no está en el servidor de producción: una máquina aparte abre un túnel SSH reverso que la publica en `127.0.0.1:3307` del servidor, y de ahí reindexa Manticore cada hora. Si el túnel se cae, los avisos se congelan (pasó del 4 al 23 de octubre de 2026).

- **Túnel:** [server-conf/tunnel/mysql-reverse-tunnel.service](server-conf/tunnel/mysql-reverse-tunnel.service) se instala en la máquina de la base; se reconecta solo. En el servidor conviene `ClientAliveInterval 30` en `sshd_config` para que una sesión muerta libere el 3307.
- **Chequeo de salud:** [server-conf/healthcheck/roodos-healthcheck.sh](server-conf/healthcheck/roodos-healthcheck.sh) corre cada 15 minutos (crontab) y avisa por [ntfy](https://ntfy.sh) si Manticore no responde, el túnel está caído, los índices llevan más de 3 h sin actualizarse o la home de autos de un país no da 200. El tema de ntfy va en `/etc/roodos-healthcheck.env` (`NTFY_TOPIC=...`), fuera del repo. Log: `/var/log/roodos-healthcheck.log`.
- **Manticore:** [server-conf/service_systemd/manticore.service](server-conf/service_systemd/manticore.service) se detiene con `--stopwait`; con `--stop` un reinicio dejaba el servicio colgado (caída del 23 de octubre de 2026).

## Desarrollo

```bash
composer install
npm install
composer dev        # server, queue, logs y vite
php artisan test
```

Las rutas dependen del host, así que en local hay que apuntar dominios como `autos.roodos.lab` a `127.0.0.1` (por ejemplo en `/etc/hosts`).

## Deploy

Cada push a `main` despliega por SSH con [.github/workflows/roodos.yml](.github/workflows/roodos.yml). La instalación inicial del servidor está en [server-conf/deploy/](server-conf/deploy/).
