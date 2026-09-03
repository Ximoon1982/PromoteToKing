/* Request/cache/retry coordination for the stable P2K API facade. */
(() => {
  "use strict";

  const modules = window.P2K_API_MODULES = window.P2K_API_MODULES || {};
  modules.requestCoordinator = Object.freeze({
    create(dependencies) {
      const {
        defaultAttempts, defaultTimeoutMs, retryBaseDelayMs, counters, integer,
        normalizeUrl, apiError, activityAwareOptions, normalizeError, isTransient,
        isCancelled, abortError, recordError, recordWarning, delay, jitter,
        endpointPriority, schedule, fetchSnapshot, cachedData, queueObservation,
        noteSuccess, noteTransientFailure
      } = dependencies;
      const backgroundRefreshes = new Map();

      async function refresh(url, cachedEntry, options) {
        const cache = window.P2K_API_CACHE || null;
        const priority = Number.isFinite(Number(options.priority)) ? Number(options.priority) : endpointPriority(url);
        const task = async sharedSignal => {
          const snapshot = await schedule(
            () => fetchSnapshot(url, cachedEntry, { ...options, signal: sharedSignal || options.signal }),
            { priority, signal: sharedSignal || options.signal, url }
          );
          if (options.cacheMode !== "no-store" && cache?.put) await cache.put(snapshot);
          try {
            window.dispatchEvent(new CustomEvent("p2k-api-cache-updated", {
              detail: { url, fetchedAt: snapshot.fetchedAt, transport: snapshot.transport }
            }));
          } catch (_) { /* Older WebViews. */ }
          return snapshot;
        };
        if (cache?.coordinate && options.cacheMode !== "no-store") {
          const attempts = integer(options.attempts, defaultAttempts, 1, 6);
          const leaseMs = Math.max(90_000, (integer(options.timeoutMs, defaultTimeoutMs, 1_000, 120_000) * attempts) + 15_000);
          return cache.coordinate(url, task, { signal: options.signal, leaseMs });
        }
        return task(options.signal);
      }

      async function jsonOnce(url, options = {}) {
        const normalizedUrl = normalizeUrl(url);
        counters.requests += 1;
        const cache = window.P2K_API_CACHE || null;
        const requestedMode = String(options.cacheMode || (options.networkOnly ? "network-only" : "default")).toLowerCase();
        let cachedEntry = requestedMode === "no-store" ? null : await cache?.get?.(normalizedUrl);
        const policy = cache?.policyFor?.(normalizedUrl, cachedEntry) || {
          freshFor: 0, usableFor: 0, staleIfErrorFor: 0, networkPreferred: false
        };
        const cacheMode = policy.networkPreferred && requestedMode === "default" ? "network-only" : requestedMode;
        if (cacheMode === "no-store") cachedEntry = null;
        let age = cachedEntry ? Date.now() - Number(cachedEntry.fetchedAt || 0) : Infinity;

        if (cacheMode === "cache-only") {
          if (!cachedEntry || age > policy.usableFor) {
            throw apiError(`No cached response is available for ${normalizedUrl}`, {
              category: "cache", code: "CACHE_MISS", url: normalizedUrl, retryable: false
            });
          }
          counters.cacheHits += 1;
          return { data: await cachedData(cachedEntry, normalizedUrl), cacheState: "HIT", transport: cachedEntry.transport || "cache" };
        }

        if (cacheMode === "default" && cachedEntry && age <= policy.usableFor) {
          if (age > policy.freshFor && !backgroundRefreshes.has(normalizedUrl) && (window.P2K_TAB_ACTIVITY?.isActive?.() ?? true)) {
            const promise = refresh(normalizedUrl, cachedEntry, {
              ...options, signal: null, priority: -100, trafficClass: "background", cacheMode: "network-only"
            }).then(async snapshot => {
              // The caller received stale-but-usable cache data, but the completed background
              // network validation is still valuable to the universal observation pipeline.
              try {
                const data = await cachedData(snapshot, normalizedUrl);
                queueObservation(normalizedUrl, data, {
                  ...options, cacheState: "REFRESH", transport: snapshot.transport || "fetch"
                });
              } catch (_) { /* Cache update remains valid even if opportunistic ingest cannot decode it. */ }
              return snapshot;
            }).catch(error => {
              recordWarning("Background refresh failed.", { url: normalizedUrl, category: error?.category || "unknown" });
            }).finally(() => backgroundRefreshes.delete(normalizedUrl));
            backgroundRefreshes.set(normalizedUrl, promise);
          }
          if (age <= policy.freshFor) counters.cacheHits += 1;
          else counters.cacheStaleHits += 1;
          try {
            return {
              data: await cachedData(cachedEntry, normalizedUrl),
              cacheState: age <= policy.freshFor ? "HIT" : "STALE",
              transport: cachedEntry.transport || "cache"
            };
          } catch (error) {
            if (error.code !== "CACHE_PARSE_ERROR") throw error;
            cachedEntry = null;
            age = Infinity;
          }
        }

        counters.cacheMisses += 1;
        try {
          const snapshot = await refresh(normalizedUrl, cachedEntry, { ...options, cacheMode });
          noteSuccess();
          return {
            data: await cachedData(snapshot, normalizedUrl),
            cacheState: cachedEntry ? "REFRESH" : "MISS",
            transport: snapshot.transport || "fetch"
          };
        } catch (error) {
          const normalized = normalizeError(error, normalizedUrl, options.signal);
          if (isTransient(normalized)) noteTransientFailure(normalized);
          if (cachedEntry && isTransient(normalized) && age <= Number(policy.staleIfErrorFor || policy.usableFor || 0)) {
            counters.staleIfErrorFallbacks += 1;
            const warning = {
              url: normalizedUrl, category: normalized.category,
              fetchedAt: cachedEntry.fetchedAt, ageMs: age
            };
            recordWarning("Using cached data because the network refresh failed.", warning);
            try {
              window.dispatchEvent(new CustomEvent("p2k-api-stale-fallback", { detail: warning }));
            } catch (_) { /* Older WebViews. */ }
            return {
              data: await cachedData(cachedEntry, normalizedUrl),
              cacheState: "STALE_IF_ERROR", transport: cachedEntry.transport || "cache", warning
            };
          }
          throw normalized;
        }
      }

      async function jsonDetailed(url, options = {}) {
        const normalizedUrl = normalizeUrl(url);
        const activity = activityAwareOptions(options);
        options = activity.options;
        const signal = options.signal || null;
        const attempts = integer(options.attempts, defaultAttempts, 1, 6);
        let lastError = null;
        try {
          for (let attempt = 1; attempt <= attempts; attempt += 1) {
            try {
              const result = await jsonOnce(normalizedUrl, options);
              result.observationSource = options.observationSource;
              if (result.cacheState !== "STALE_IF_ERROR") {
                result.observationClaimToken = options.observationClaimToken;
                result.observationClaimKind = options.observationClaimKind;
              }
              queueObservation(normalizedUrl, result.data, result);
              return Object.freeze({ ...result, url: normalizedUrl, attempts: attempt });
            } catch (error) {
              const normalized = normalizeError(error, normalizedUrl, signal);
              normalized.attempt = attempt;
              if (isCancelled(normalized, signal)) {
                counters.cancellations += 1;
                throw abortError(normalizedUrl);
              }
              lastError = normalized;
              recordError(normalized);
              if (!normalized.retryable || attempt >= attempts) break;
              counters.retries += 1;
              const wait = Math.max(normalized.retryAfterMs || 0, retryBaseDelayMs * attempt);
              await delay(jitter(wait), signal);
            }
          }
          throw lastError || apiError(`Unable to load ${normalizedUrl}`, { url: normalizedUrl });
        } finally {
          activity.cleanup();
        }
      }

      async function json(url, options = {}) { return (await jsonDetailed(url, options)).data; }
      return Object.freeze({ json, jsonDetailed });
    }
  });
})();
