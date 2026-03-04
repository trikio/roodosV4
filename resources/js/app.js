const mixpanelToken = import.meta.env.VITE_MIXPANEL_TOKEN;
const adsenseClient = import.meta.env.VITE_ADSENSE_CLIENT;

let vendorsLoaded = false;
let pageLoaded = document.readyState === "complete";
let userInteracted = false;

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
  if (!mixpanelToken) return;
  if (window.mixpanel && typeof window.mixpanel.init === "function") return;

  loadScript("https://cdn.mxpnl.com/libs/mixpanel-2-latest.min.js")
    .then(() => {
      if (window.mixpanel && typeof window.mixpanel.init === "function") {
        window.mixpanel.init(mixpanelToken, { persistence: "localStorage" });
      }
    })
    .catch(() => {});
}

function loadAdsense() {
  if (!adsenseClient) return;

  loadScript(
    `https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=${encodeURIComponent(adsenseClient)}`,
    {
      attrs: {
        crossorigin: "anonymous",
      },
    },
  )
    .then(() => {
      window.adsbygoogle = window.adsbygoogle || [];
      window.adsbygoogle.push({});
    })
    .catch(() => {});
}

function loadVendorsOnce() {
  if (vendorsLoaded || !pageLoaded || !userInteracted) return;
  vendorsLoaded = true;
  loadMixpanel();
  loadAdsense();
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
