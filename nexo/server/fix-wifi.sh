#!/bin/bash
# Deja estable el WiFi USB (Realtek RTL8821CU, 0bda:1a2b en modo disco -> 0bda:c811 en modo WiFi).
set -euo pipefail
[ "$EUID" -eq 0 ] || { echo "Correr con sudo"; exit 1; }
IFACE=wlxa0d76830279f

echo "== 1. Cambio inmediato de modo disco a modo WiFi al arrancar"
cat > /etc/udev/rules.d/40-rtl8821cu-modeswitch.rules <<'RULE'
# El adaptador arranca como disco virtual (0bda:1a2b); esto lo pasa a modo WiFi apenas aparece.
ACTION=="add", SUBSYSTEM=="usb", ATTR{idVendor}=="0bda", ATTR{idProduct}=="1a2b", RUN+="/usr/sbin/usb_modeswitch -K -v 0bda -p 1a2b"
RULE

echo "== 2. wpa_supplicant arranca cuando aparece la interfaz, aunque tarde"
cat > /etc/udev/rules.d/90-wifi-wpa.rules <<RULE
ACTION=="add", SUBSYSTEM=="net", KERNEL=="$IFACE", TAG+="systemd", ENV{SYSTEMD_WANTS}+="netplan-wpa-$IFACE.service"
RULE
udevadm control --reload

echo "== 3. Watchdog: si no llega al router, reaplica netplan"
cat > /usr/local/sbin/wifi-watchdog <<'SCRIPT'
#!/bin/sh
GW=$(ip route show default | awk '{print $3; exit}')
ping -c 3 -W 3 "${GW:-192.168.1.1}" >/dev/null 2>&1 && exit 0
logger -t wifi-watchdog "sin conexión al router, reaplicando netplan"
netplan apply
SCRIPT
chmod 755 /usr/local/sbin/wifi-watchdog
cat > /etc/systemd/system/wifi-watchdog.service <<'UNIT'
[Unit]
Description=Reconecta el WiFi si se cae

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/wifi-watchdog
UNIT
cat > /etc/systemd/system/wifi-watchdog.timer <<'UNIT'
[Unit]
Description=Revisa el WiFi cada 3 minutos

[Timer]
OnBootSec=4min
OnUnitActiveSec=3min

[Install]
WantedBy=timers.target
UNIT
systemctl daemon-reload
systemctl enable --now wifi-watchdog.timer

echo "== 4. Driver con DKMS (se recompila solo con cada kernel nuevo)"
SRC=/usr/src/rtl8821CU-5.4.1
if ! dkms status rtl8821CU 2>/dev/null | grep -q installed; then
  rm -rf "$SRC"
  cp -r /home/trikio/RTL8811CU "$SRC"
  sed -i 's/^PACKAGE_VERSION=.*/PACKAGE_VERSION="5.4.1"/' "$SRC/dkms.conf"
  make -C "$SRC" clean >/dev/null
  dkms add -m rtl8821CU -v 5.4.1
  dkms build -m rtl8821CU -v 5.4.1
  dkms install -m rtl8821CU -v 5.4.1 --force
fi
dkms status

echo "== Listo"
systemctl list-timers wifi-watchdog.timer --no-pager
