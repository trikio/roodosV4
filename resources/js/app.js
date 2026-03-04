const mixpanelToken = import.meta.env.VITE_MIXPANEL_TOKEN;
const adsenseClient = import.meta.env.VITE_ADSENSE_CLIENT;

let vendorsLoaded = false;
let pageLoaded = document.readyState === "complete";
let userInteracted = false;
let adsenseScriptReady = false;
let adsenseScriptPromise = null;

function loadScript(src, { async = true, defer = false, attrs = {} } = {}) {
  return new Promise((resolve, reject) => {
    const script = document.createElement("script");
    script.src = src;
    script.async = async;
    script.defer = defer;

    Object.entries(attrs).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== "") {
        script.setAttribute(key, value);
      }
    });

    script.onload = resolve;
    script.onerror = reject;
    document.head.appendChild(script);
  });
}

function loadMixpanel() {
  if (!mixpanelToken || window.__mixpanelLoaded) return;
  window.__mixpanelLoaded = true;

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
}

function loadAdsenseScript(clientId) {
  if (adsenseScriptReady) return Promise.resolve();
  if (adsenseScriptPromise) return adsenseScriptPromise;
  if (!clientId) return Promise.resolve();

  adsenseScriptPromise = loadScript(
    `https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=${encodeURIComponent(clientId)}`,
    {
      attrs: {
        crossorigin: "anonymous",
      },
    },
  )
    .then(() => {
      adsenseScriptReady = true;
    })
    .catch(() => {});

  return adsenseScriptPromise;
}

function pushAdsenseSlot(slot) {
  if (!slot || slot.dataset.adsLoaded === "1") return;

  try {
    window.adsbygoogle = window.adsbygoogle || [];
    window.adsbygoogle.push({});
    slot.dataset.adsLoaded = "1";
  } catch (_) {}
}

function initLazyAdsense() {
  const slots = Array.from(document.querySelectorAll("ins.adsbygoogle.js-lazy-adsense"));
  if (!slots.length) return;

  const clientId = slots[0].dataset.adClient || adsenseClient;
  loadAdsenseScript(clientId).then(() => {
    if (!("IntersectionObserver" in window)) {
      slots.forEach((slot) => pushAdsenseSlot(slot));
      return;
    }

    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            pushAdsenseSlot(entry.target);
            observer.unobserve(entry.target);
          }
        });
      },
      { rootMargin: "200px 0px" },
    );

    slots.forEach((slot) => observer.observe(slot));
  });
}

function loadVendorsOnce() {
  if (vendorsLoaded || !pageLoaded || !userInteracted) return;
  vendorsLoaded = true;
  loadMixpanel();
  initLazyAdsense();
}

window.addEventListener("load", () => {
  pageLoaded = true;
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
