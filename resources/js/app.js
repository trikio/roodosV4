const mixpanelToken = import.meta.env.VITE_MIXPANEL_TOKEN;
const adsenseClient = import.meta.env.VITE_ADSENSE_CLIENT;

let vendorsLoaded = false;
let pageLoaded = document.readyState === "complete";
let userInteracted = false;
let adsenseScriptReady = false;
let adsenseScriptPromise = null;
let mixpanelInitialized = false;
let mixpanelScriptPromise = null;

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
  if (!mixpanelToken || mixpanelInitialized) return;

  if (!mixpanelScriptPromise) {
    mixpanelScriptPromise = loadScript("https://cdn.mxpnl.com/libs/mixpanel-2-latest.min.js").catch(() => {});
  }

  mixpanelScriptPromise.then(() => {
    if (!mixpanelInitialized && window.mixpanel && typeof window.mixpanel.init === "function") {
      window.mixpanel.init(mixpanelToken, { persistence: "localStorage" });
      mixpanelInitialized = true;
    }
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
