/* Low-level fetch/JSONP execution for the shared API-client facade. */
(() => {
  "use strict";

  const modules = window.P2K_API_MODULES || (window.P2K_API_MODULES = {});
  if (modules.transport?.create) return;

  modules.transport = Object.freeze({
    create(dependencies) {
      const {
        nativeFetch, defaultTimeoutMs, jsonpEnabled, counters, integer,
        normalizeUrl, apiError, abortError, timeoutError, normalizeError,
        isCancelled, categoryForStatus, oauthSessionActive,
        executeOAuthGateway, waitForRealOAuthDecision
      } = dependencies;

      function combineSignal(externalSignal, timeoutMs, url) {
        const controller = new AbortController();
        let timeoutTriggered = false;
        const timer = window.setTimeout(() => {
          timeoutTriggered = true;
          controller.abort();
        }, timeoutMs);
        const onAbort = () => controller.abort();
        externalSignal?.addEventListener("abort", onAbort, { once: true });
        return {
          signal: controller.signal,
          error() { return timeoutTriggered ? timeoutError(url) : abortError(url); },
          cleanup() {
            window.clearTimeout(timer);
            externalSignal?.removeEventListener("abort", onAbort);
          }
        };
      }

      function retryAfterMilliseconds(response) {
        const value = response.headers?.get?.("retry-after");
        if (!value) return 0;
        const seconds = Number(value);
        if (Number.isFinite(seconds)) return Math.max(0, seconds * 1000);
        const timestamp = Date.parse(value);
        return Number.isFinite(timestamp) ? Math.max(0, timestamp - Date.now()) : 0;
      }

      function appendCallbackParameter(url, callbackName) {
        return `${url}${url.includes("?") ? "&" : "?"}callback=${encodeURIComponent(callbackName)}`;
      }

      function loadJSONP(url, { signal = null, timeoutMs = defaultTimeoutMs } = {}) {
        const normalizedUrl = normalizeUrl(url);
        if (!jsonpEnabled) {
          return Promise.reject(apiError("JSONP fallback is disabled.", {
            category: "network", code: "JSONP_DISABLED", url: normalizedUrl, retryable: true
          }));
        }
        counters.jsonp += 1;
        return new Promise((resolve, reject) => {
          if (signal?.aborted) { reject(abortError(normalizedUrl)); return; }
          const callbackName = `p2kApiJsonp_${Date.now()}_${Math.random().toString(36).slice(2)}`;
          const script = document.createElement("script");
          let timer = null;
          let settled = false;
          const cleanup = () => {
            if (timer !== null) window.clearTimeout(timer);
            signal?.removeEventListener("abort", onAbort);
            script.remove();
            try { delete window[callbackName]; }
            catch (_) { window[callbackName] = undefined; }
          };
          const finish = (handler, value) => {
            if (settled) return;
            settled = true;
            cleanup();
            handler(value);
          };
          const onAbort = () => finish(reject, abortError(normalizedUrl));
          window[callbackName] = data => finish(resolve, data);
          script.onerror = () => finish(reject, apiError(`JSONP request failed for ${normalizedUrl}`, {
            category: "network", code: "JSONP_FAILED", url: normalizedUrl, retryable: true
          }));
          timer = window.setTimeout(() => finish(reject, timeoutError(normalizedUrl)), timeoutMs);
          signal?.addEventListener("abort", onAbort, { once: true });
          script.src = appendCallbackParameter(normalizedUrl, callbackName);
          script.async = true;
          document.head.appendChild(script);
        });
      }

      function responseHeaders(response) {
        const headers = {};
        response.headers?.forEach?.((value, name) => { headers[name] = value; });
        if (!headers["content-type"]) headers["content-type"] = "application/json";
        return headers;
      }

      async function executeFetch(url, { headers, signal, timeoutMs, conditional = false, priority = 0, trafficClass = "foreground" }) {
        await waitForRealOAuthDecision();
        counters.fetches += 1;
        if (conditional) counters.conditionalFetches += 1;
        if (oauthSessionActive()) {
          try { return await executeOAuthGateway(url, { headers, signal, priority, trafficClass }); }
          catch (error) { throw normalizeError(error, url, signal); }
        }
        const combined = combineSignal(signal, timeoutMs, url);
        try {
          return await nativeFetch(url, {
            method: "GET", mode: "cors", cache: "no-cache", credentials: "omit",
            referrerPolicy: "no-referrer", headers, signal: combined.signal
          });
        } catch (error) {
          if (combined.signal.aborted) throw combined.error();
          throw normalizeError(error, url, signal);
        } finally {
          combined.cleanup();
        }
      }

      async function fetchSnapshot(url, cachedEntry, options) {
        const signal = options.signal || null;
        const timeoutMs = integer(options.timeoutMs, defaultTimeoutMs, 1_000, 120_000);
        const baseHeaders = new Headers(options.headers || { Accept: "application/json" });
        const conditionalHeaders = new Headers(baseHeaders);
        const hasConditional = Boolean(cachedEntry?.etag || cachedEntry?.lastModified);
        if (cachedEntry?.etag) conditionalHeaders.set("If-None-Match", cachedEntry.etag);
        if (cachedEntry?.lastModified) conditionalHeaders.set("If-Modified-Since", cachedEntry.lastModified);

        let response;
        try {
          response = await executeFetch(url, {
            headers: hasConditional ? conditionalHeaders : baseHeaders, signal, timeoutMs,
            conditional: hasConditional, priority: Number(options.priority) || 0,
            trafficClass: options.trafficClass === "background" ? "background" : "foreground"
          });
        } catch (conditionalError) {
          if (isCancelled(conditionalError, signal)) throw conditionalError;
          if (hasConditional) {
            counters.plainFetchRetries += 1;
            try {
              response = await executeFetch(url, {
                headers: baseHeaders, signal, timeoutMs, conditional: false,
                priority: Number(options.priority) || 0,
                trafficClass: options.trafficClass === "background" ? "background" : "foreground"
              });
            } catch (plainError) {
              if (isCancelled(plainError, signal)) throw plainError;
              if (options.jsonpFallback === false || !jsonpEnabled) throw plainError;
              const data = await loadJSONP(url, { signal, timeoutMs });
              return {url, body:JSON.stringify(data), status:200, statusText:"OK", headers:{"content-type":"application/json"}, etag:"", lastModified:"", fetchedAt:Date.now(), transport:"jsonp"};
            }
          } else {
            if (options.jsonpFallback === false || !jsonpEnabled) throw conditionalError;
            const data = await loadJSONP(url, { signal, timeoutMs });
            return {url, body:JSON.stringify(data), status:200, statusText:"OK", headers:{"content-type":"application/json"}, etag:"", lastModified:"", fetchedAt:Date.now(), transport:"jsonp"};
          }
        }

        if (response.status === 304 && cachedEntry) return { ...cachedEntry, fetchedAt: Date.now(), transport: "conditional-cache" };
        if (!response.ok) {
          const status = Number(response.status) || 0;
          const category = categoryForStatus(status);
          throw apiError(`Unable to load ${url}: HTTP ${status}`, {
            ...category, status, url, retryAfterMs: retryAfterMilliseconds(response)
          });
        }
        const body = await response.text();
        try { JSON.parse(body); }
        catch (cause) {
          throw apiError(`Unable to parse the response from ${url}`, {
            category: "parse", code: "RESPONSE_PARSE_ERROR", status: Number(response.status) || 200,
            url, retryable: false, cause
          });
        }
        return {
          url, body, status: Number(response.status) || 200, statusText: response.statusText || "OK",
          headers: responseHeaders(response), etag: response.headers.get("etag") || cachedEntry?.etag || "",
          lastModified: response.headers.get("last-modified") || cachedEntry?.lastModified || "",
          fetchedAt: Date.now(), transport: "fetch"
        };
      }

      return Object.freeze({ executeFetch, fetchSnapshot });
    }
  });
})();
