/* Request validation, cancellation and error semantics for the shared API client. */
(() => {
  "use strict";

  const modules = window.P2K_API_MODULES || (window.P2K_API_MODULES = {});
  if (modules.requestSemantics?.create) return;

  modules.requestSemantics = Object.freeze({
    create({ allowedOrigins }) {
      const origins = allowedOrigins;

      function apiError(message, {
        category = "unknown",
        code = "UNKNOWN",
        status = 0,
        url = "",
        retryable = false,
        retryAfterMs = 0,
        attempt = 0,
        cause = null
      } = {}) {
        const error = new Error(String(message || "API request failed."), cause ? { cause } : undefined);
        error.name = "P2KApiError";
        error.category = category;
        error.code = code;
        error.status = Number(status) || 0;
        error.url = String(url || "");
        error.retryable = Boolean(retryable);
        error.retryAfterMs = Math.max(0, Number(retryAfterMs) || 0);
        error.attempt = Number(attempt) || 0;
        if (cause && !error.cause) error.cause = cause;
        return error;
      }

      function normalizeUrl(value) {
        let url;
        try { url = new URL(String(value || ""), window.location.href); }
        catch (cause) {
          throw apiError("The API URL is invalid.", {
            category: "client", code: "INVALID_URL", retryable: false, cause
          });
        }
        if (url.protocol !== "https:" || !origins.has(url.origin)) {
          throw apiError(`API origin is not allowed: ${url.origin}`, {
            category: "client", code: "ORIGIN_NOT_ALLOWED", url: url.href, retryable: false
          });
        }
        return url.href;
      }

      function abortError(url = "") {
        return apiError("The operation was cancelled.", {
          category: "cancelled", code: "CANCELLED", url, retryable: false
        });
      }

      function activityAwareOptions(options = {}) {
        const activity = window.P2K_TAB_ACTIVITY;
        if (!activity) return { options, cleanup() {} };
        if (!activity.isActive()) throw abortError();
        const signals = [options.signal || null, activity.signal?.() || null].filter(Boolean);
        if (!signals.length) return { options, cleanup() {} };
        if (signals.length === 1) return { options: { ...options, signal: signals[0] }, cleanup() {} };
        if (typeof AbortSignal.any === "function") return { options: { ...options, signal: AbortSignal.any(signals) }, cleanup() {} };
        const controller = new AbortController();
        const onAbort = () => controller.abort();
        signals.forEach(signal => signal.addEventListener("abort", onAbort, { once: true }));
        if (signals.some(signal => signal.aborted)) controller.abort();
        return {
          options: { ...options, signal: controller.signal },
          cleanup() { signals.forEach(signal => signal.removeEventListener("abort", onAbort)); }
        };
      }

      function timeoutError(url = "") {
        return apiError(`The request timed out while loading ${url}`, {
          category: "timeout", code: "TIMEOUT", url, retryable: true
        });
      }

      function categoryForStatus(status) {
        if (status === 401 || status === 403) return { category: "forbidden", code: "FORBIDDEN", retryable: false };
        if (status === 404 || status === 410) return { category: "not-found", code: "NOT_FOUND", retryable: false };
        if (status === 408) return { category: "timeout", code: "HTTP_TIMEOUT", retryable: true };
        if (status === 429) return { category: "rate-limit", code: "RATE_LIMIT", retryable: true };
        if (status >= 500) return { category: "server", code: "SERVER_ERROR", retryable: true };
        if (status >= 400) return { category: "client", code: "CLIENT_ERROR", retryable: false };
        return { category: "unknown", code: "HTTP_ERROR", retryable: false };
      }

      function normalizeError(error, url = "", signal = null) {
        if (error?.name === "P2KApiError") return error;
        if (signal?.aborted || error?.name === "AbortError") return abortError(url);
        if (error?.name === "TimeoutError") return timeoutError(url);
        if (error instanceof TypeError) {
          return apiError(`A network error occurred while loading ${url}`, {
            category: "network", code: "NETWORK_ERROR", url, retryable: true, cause: error
          });
        }
        return apiError(error?.message || `Unable to load ${url}`, {
          category: error?.category || "unknown", code: error?.code || "UNKNOWN",
          status: error?.status || 0, url: error?.url || url,
          retryable: Boolean(error?.retryable), retryAfterMs: error?.retryAfterMs || 0, cause: error
        });
      }

      function isCancelled(error, signal = null) {
        return signal?.aborted || error?.category === "cancelled" || error?.name === "AbortError";
      }

      function isTransient(error) {
        const normalized = normalizeError(error, error?.url || "");
        return normalized.retryable || ["network", "timeout", "rate-limit", "server", "cache"].includes(normalized.category);
      }

      function isPermanent(error) { return !isTransient(error) && !isCancelled(error); }

      function userMessage(error) {
        const normalized = normalizeError(error, error?.url || "");
        const messages = {
          cancelled: "The operation was cancelled.", timeout: "The Chess.com API did not respond in time.",
          network: "The Chess.com API could not be reached.", "not-found": "The requested Chess.com resource was not found.",
          forbidden: "Chess.com did not allow access to this resource.",
          "rate-limit": "Chess.com is temporarily limiting requests. Please retry shortly.",
          server: "Chess.com returned a temporary server error.", client: "The request could not be completed.",
          parse: "Chess.com returned data that could not be read.", cache: "The local cache could not provide this response."
        };
        return messages[normalized.category] || normalized.message || "The request failed.";
      }

      function delay(milliseconds, signal = null) {
        const duration = Math.max(0, Number(milliseconds) || 0);
        if (signal?.aborted) return Promise.reject(abortError());
        return new Promise((resolve, reject) => {
          const timer = window.setTimeout(() => {
            signal?.removeEventListener("abort", onAbort);
            resolve();
          }, duration);
          const onAbort = () => {
            window.clearTimeout(timer);
            signal?.removeEventListener("abort", onAbort);
            reject(abortError());
          };
          signal?.addEventListener("abort", onAbort, { once: true });
        });
      }

      return Object.freeze({
        normalizeUrl, apiError, abortError, activityAwareOptions, timeoutError,
        categoryForStatus, normalizeError, isCancelled, isTransient, isPermanent,
        userMessage, delay
      });
    }
  });
})();
