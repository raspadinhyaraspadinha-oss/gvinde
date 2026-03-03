/**
 * Facebook Pixel (browser) + CAPI bridge + UTM persistence.
 *
 * Include this file on EVERY page of the funnel BEFORE any event calls.
 * It initialises the pixel, captures UTMs on first load, and exposes
 * helpers to fire deduplicated browser+server events.
 *
 * Usage:
 *   <script src="/js/fb-tracking.js"></script>
 *   <script>
 *     // fires browser pixel AND sends to server CAPI endpoint
 *     fbTrack("ViewContent", { content_name: "VSL" });
 *   </script>
 */

(function () {
  "use strict";

  var PIXEL_ID = "895868873361776";
  var CAPI_ENDPOINT = "/checkout/api/fb-capi.php";
  var UTM_STORAGE_KEY = "funnelUtms";
  var EVENT_ID_PREFIX = "evt_";

  // ── 1. Install browser pixel (idempotent) ──────────────────────────
  if (!window.fbq) {
    (function (f, b, e, v, n, t, s) {
      if (f.fbq) return;
      n = f.fbq = function () {
        n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments);
      };
      if (!f._fbq) f._fbq = n;
      n.push = n;
      n.loaded = true;
      n.version = "2.0";
      n.queue = [];
      t = b.createElement(e);
      t.async = true;
      t.src = v;
      s = b.getElementsByTagName(e)[0];
      s.parentNode.insertBefore(t, s);
    })(window, document, "script", "https://connect.facebook.net/en_US/fbevents.js");
  }

  fbq("init", PIXEL_ID);

  // ── 2. UTM capture & persistence ───────────────────────────────────
  function captureUtms() {
    var params = new URLSearchParams(window.location.search);
    var keys = ["utm_source", "utm_campaign", "utm_medium", "utm_content", "utm_term", "fbclid", "src", "sck"];
    var found = {};
    var hasAny = false;

    keys.forEach(function (k) {
      var v = params.get(k);
      if (v) {
        found[k] = v;
        hasAny = true;
      }
    });

    if (hasAny) {
      localStorage.setItem(UTM_STORAGE_KEY, JSON.stringify(found));
    }
  }

  function getStoredUtms() {
    try {
      return JSON.parse(localStorage.getItem(UTM_STORAGE_KEY) || "{}");
    } catch (e) {
      return {};
    }
  }

  captureUtms();

  // ── 3. Cookie helpers ──────────────────────────────────────────────
  function getCookie(name) {
    var match = document.cookie.match(new RegExp("(?:^|;\\s*)" + name + "=([^;]*)"));
    return match ? decodeURIComponent(match[1]) : null;
  }

  function getFbp() {
    return getCookie("_fbp") || null;
  }

  function getFbc() {
    var existing = getCookie("_fbc");
    if (existing) return existing;
    var utms = getStoredUtms();
    if (utms.fbclid) {
      return "fb.1." + Date.now() + "." + utms.fbclid;
    }
    return null;
  }

  // ── 4. Event ID generator (for dedup) ──────────────────────────────
  function generateEventId() {
    return EVENT_ID_PREFIX + Date.now() + "_" + Math.random().toString(36).substring(2, 10);
  }

  // ── 5. Main tracking function ──────────────────────────────────────
  /**
   * @param {string} eventName  - Standard FB event name (PageView, ViewContent, etc.)
   * @param {object} [customData] - Extra data for the event
   * @param {object} [options]    - { serverOnly: bool, browserOnly: bool, userData: {} }
   */
  function fbTrack(eventName, customData, options) {
    customData = customData || {};
    options = options || {};

    var eventId = generateEventId();

    // Browser pixel (with eventID for dedup)
    if (!options.serverOnly) {
      fbq("track", eventName, customData, { eventID: eventId });
    }

    // Server CAPI
    if (!options.browserOnly) {
      var utms = getStoredUtms();
      var consultaRaw = null;
      try {
        consultaRaw = JSON.parse(localStorage.getItem("consultaCpfData") || "null");
      } catch (e) {}

      var payload = {
        event_name: eventName,
        event_id: eventId,
        event_source_url: window.location.href,
        custom_data: customData,
        user_data: Object.assign(
          {
            fbp: getFbp(),
            fbc: getFbc(),
            client_user_agent: navigator.userAgent,
          },
          consultaRaw
            ? {
                fn: (consultaRaw.nome || "").split(" ")[0].toLowerCase(),
                ln: (consultaRaw.nome || "").split(" ").slice(1).join(" ").toLowerCase(),
                external_id: consultaRaw.cpf || undefined,
              }
            : {},
          options.userData || {}
        ),
        tracking_parameters: utms,
      };

      // Fire-and-forget POST
      try {
        var xhr = new XMLHttpRequest();
        xhr.open("POST", CAPI_ENDPOINT, true);
        xhr.setRequestHeader("Content-Type", "application/json");
        xhr.send(JSON.stringify(payload));
      } catch (e) {
        // Silently fail — never block the funnel
      }
    }
  }

  // ── 6. Auto PageView ───────────────────────────────────────────────
  fbTrack("PageView");

  // ── 7. Expose globally ─────────────────────────────────────────────
  window.fbTrack = fbTrack;
  window.getStoredUtms = getStoredUtms;
  window.getFbp = getFbp;
  window.getFbc = getFbc;
})();
