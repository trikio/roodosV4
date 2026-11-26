const mixpanelToken = import.meta.env.VITE_MIXPANEL_TOKEN;
// Las vistas encolan eventos como [nombre, propiedades, callback?]; se envían al cargar Mixpanel.
const mixpanelEvents = (window.mixpanelEvents = window.mixpanelEvents || []);

let vendorsLoaded = false;
let pageLoaded = document.readyState === "complete";
let userInteracted = false;

function flushMixpanelEvents() {
  mixpanelEvents.splice(0).forEach(([name, properties, callback]) => {
    if (!mixpanelToken) {
      callback?.();
      return;
    }
    window.mixpanel.track(name, properties, { transport: "sendBeacon", send_immediately: true }, callback);
  });
}

// Eventos posteriores a la carga (clics): la cola solo se vacía al cargar Mixpanel.
window.trackEvent = (name, properties, callback) => {
  mixpanelEvents.push([name, properties, callback]);
  if (window.__mixpanelLoaded) flushMixpanelEvents();
  else loadMixpanel();
};

function loadMixpanel() {
  if (window.__mixpanelLoaded) return;
  window.__mixpanelLoaded = true;
  if (!mixpanelToken) {
    flushMixpanelEvents();
    return;
  }

  (function (e, c) {
    if (!c.__SV) {
      let l;
      let h;
      window.mixpanel = c;
      c._i = [];
      c.init = function (q, r, f) {
        function t(d, a) {
          const g = a.split(".");
          if (g.length === 2) {
            d = d[g[0]];
            a = g[1];
          }
          d[a] = function () {
            d.push([a].concat(Array.prototype.slice.call(arguments, 0)));
          };
        }

        let b = c;
        if (typeof f !== "undefined") b = c[f] = [];
        else f = "mixpanel";
        b.people = b.people || [];
        b.toString = function (d) {
          let a = "mixpanel";
          if (f !== "mixpanel") a += `.${f}`;
          if (!d) a += " (stub)";
          return a;
        };
        b.people.toString = function () {
          return `${b.toString(1)}.people (stub)`;
        };
        l = "disable time_event track track_pageview track_links track_forms track_with_groups add_group set_group remove_group register register_once alias unregister identify name_tag set_config reset opt_in_tracking opt_out_tracking has_opted_in_tracking has_opted_out_tracking clear_opt_in_out_tracking start_batch_senders start_session_recording stop_session_recording people.set people.set_once people.unset people.increment people.append people.union people.track_charge people.clear_charges people.delete_user people.remove".split(" ");
        for (h = 0; h < l.length; h += 1) t(b, l[h]);
        c._i.push([q, r, f]);
      };
      c.__SV = 1.2;
      const k = e.createElement("script");
      k.type = "text/javascript";
      k.async = true;
      k.src = "https://cdn.mxpnl.com/libs/mixpanel-2-latest.min.js";
      const firstScript = e.getElementsByTagName("script")[0];
      firstScript.parentNode.insertBefore(k, firstScript);
    }
  })(document, window.mixpanel || []);

  window.mixpanel.init(mixpanelToken, {
    autocapture: true,
    record_sessions_percent: 100,
  });
  flushMixpanelEvents();
}

function pushAdsenseSlot(slot) {
  if (!slot || slot.dataset.adsLoaded === "1") return;

  try {
    window.adsbygoogle = window.adsbygoogle || [];
    window.adsbygoogle.push({});
    slot.dataset.adsLoaded = "1";
  } catch (_) {}
}

// adsbygoogle.js se carga desde el <head> del layout; aquí se piden los anuncios apenas carga la
// página. Los slots ocultos (la barra lateral en móvil) se saltan: AdSense falla sin ancho.
function initAdsense() {
  document
    .querySelectorAll("ins.adsbygoogle.js-adsense")
    .forEach((slot) => slot.getClientRects().length && pushAdsenseSlot(slot));
}

initAdsense();

function loadVendorsOnce() {
  if (vendorsLoaded || !pageLoaded || !userInteracted) return;
  vendorsLoaded = true;
  loadMixpanel();
}

window.addEventListener("load", () => {
  pageLoaded = true;
  // Con eventos pendientes no se espera interacción: se perderían las visitas que se van sin
  // interactuar.
  if (mixpanelEvents.length) loadMixpanel();
  loadVendorsOnce();
});

["scroll", "click", "keydown", "touchstart", "pointerdown"].forEach((eventName) => {
  window.addEventListener(
    eventName,
    () => {
      userInteracted = true;
      loadVendorsOnce();
    },
    { passive: true, once: true },
  );
});
