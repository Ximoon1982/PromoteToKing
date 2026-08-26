/* Promote to King · Active Convergence & Self-Refresh Pack (ACSR)
 * v2.9.22 shared Active Convergence & Self-Refresh coordinator.
 *
 * Goals:
 * - visible/online pages refresh themselves without timer storms;
 * - unfinished/stale views converge quickly, then relax to a quiet cadence;
 * - one logical refresh key is single-flight across same-origin frames/tabs;
 * - focus/pageshow/online/visibility wake a stale view immediately;
 * - failures back off without disabling manual refresh controls.
 */
(function (global) {
  'use strict';

  const VERSION = '2.9.22-acsr-v1';
  const CHANNEL = 'p2k-acsr-v1';
  const DEFAULTS = Object.freeze({
    minIntervalMs: 15000,
    initialDelayMs: 15000,
    convergedIntervalMs: 120000,
    maxIntervalMs: 600000,
    staleAfterMs: 120000,
    lockTimeoutMs: 45000,
    checkIntervalMs: 5000,
    jitterRatio: 0.08,
    refreshOnFocus: true,
    refreshOnOnline: true,
    refreshOnVisible: true,
    runImmediately: false
  });

  const entries = new Map();
  const owner = `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
  const channel = typeof BroadcastChannel === 'function' ? new BroadcastChannel(CHANNEL) : null;
  let checkTimer = 0;

  function now() { return Date.now(); }
  function clamp(n, lo, hi) { return Math.max(lo, Math.min(hi, n)); }
  function asBool(v, fallback = false) { return v == null ? fallback : Boolean(v); }
  function interactiveProtectionActive() {
    try { return global.P2K_API_CLIENT?.diagnostics?.()?.oauthInteractiveProtection === true; } catch (_) { return false; }
  }
  function activeDocument() {
    return (typeof document === 'undefined' || document.visibilityState !== 'hidden') &&
      (typeof navigator === 'undefined' || navigator.onLine !== false);
  }
  function jitter(ms, ratio) {
    const r = clamp(Number(ratio) || 0, 0, .25);
    return Math.max(1000, Math.round(ms * (1 + ((Math.random() * 2) - 1) * r)));
  }
  function lockName(key) { return `p2k-acsr:${String(key || '').replace(/[^a-z0-9_.:-]+/gi, '-')}`; }
  function storageKey(key) { return `p2k-acsr-lease:${String(key || '').replace(/[^a-z0-9_.:-]+/gi, '-')}`; }

  function normalizedResult(entry, value) {
    if (value && typeof value === 'object') {
      const explicit = value.converged;
      const remaining = Number(value.remaining ?? value.pending ?? value.backlog ?? value.outstanding);
      return {
        converged: explicit == null ? !(Number.isFinite(remaining) && remaining > 0) : Boolean(explicit),
        remaining: Number.isFinite(remaining) ? Math.max(0, remaining) : null,
        nextRefreshMs: Number.isFinite(Number(value.nextRefreshMs)) ? Number(value.nextRefreshMs) : null,
        payload: value
      };
    }
    return { converged: true, remaining: null, nextRefreshMs: null, payload: value };
  }

  function snapshot(entry) {
    return {
      key: entry.key,
      running: entry.running,
      paused: entry.paused,
      failures: entry.failures,
      converged: entry.converged,
      remaining: entry.remaining,
      lastAttemptAt: entry.lastAttemptAt || 0,
      lastSuccessAt: entry.lastSuccessAt || 0,
      nextDueAt: entry.nextDueAt || 0,
      lastReason: entry.lastReason || '',
      lastError: entry.lastError || '',
      version: VERSION
    };
  }

  function emit(entry, event = 'state') {
    try { entry.onState?.(snapshot(entry), event); } catch (_) {}
  }

  function broadcast(type, entry) {
    try { channel?.postMessage({ type, key: entry.key, at: now(), state: snapshot(entry) }); } catch (_) {}
  }

  async function withFallbackLease(key, timeoutMs, callback) {
    if (typeof localStorage === 'undefined') return callback();
    const sk = storageKey(key), stamp = now();
    try {
      const current = JSON.parse(localStorage.getItem(sk) || 'null');
      if (current && current.owner !== owner && Number(current.until || 0) > stamp) return { skipped: 'locked' };
      localStorage.setItem(sk, JSON.stringify({ owner, until: stamp + timeoutMs }));
      const confirmed = JSON.parse(localStorage.getItem(sk) || 'null');
      if (!confirmed || confirmed.owner !== owner) return { skipped: 'locked' };
      try { return await callback(); }
      finally {
        const latest = JSON.parse(localStorage.getItem(sk) || 'null');
        if (latest?.owner === owner) localStorage.removeItem(sk);
      }
    } catch (_) {
      return callback();
    }
  }

  async function withWorkLock(entry, callback) {
    const name = lockName(entry.key);
    if (navigator?.locks?.request) {
      let acquired = false;
      const value = await navigator.locks.request(name, { ifAvailable: true, mode: 'exclusive' }, async lock => {
        if (!lock) return { skipped: 'locked' };
        acquired = true;
        return callback();
      });
      return acquired ? value : { skipped: 'locked' };
    }
    return withFallbackLease(entry.key, entry.lockTimeoutMs, callback);
  }

  function computeNext(entry, normalized) {
    if (normalized.nextRefreshMs != null) return clamp(normalized.nextRefreshMs, entry.minIntervalMs, entry.maxIntervalMs);
    if (!normalized.converged) return entry.minIntervalMs;
    return entry.convergedIntervalMs;
  }

  async function runEntry(entry, reason = 'timer', { force = false } = {}) {
    if (!entry || entry.disposed || entry.paused || entry.running) return { skipped: entry?.running ? 'running' : 'inactive' };
    if (!force && !activeDocument()) return { skipped: 'inactive-document' };
    if (!force && interactiveProtectionActive()) return { skipped: 'interactive-protection' };
    const stamp = now();
    if (!force && entry.lastSuccessAt && stamp < entry.nextDueAt) return { skipped: 'not-due' };
    entry.running = true;
    entry.lastAttemptAt = stamp;
    entry.lastReason = reason;
    entry.lastError = '';
    emit(entry, 'attempt');
    try {
      return await withWorkLock(entry, async () => {
        broadcast('refresh-start', entry);
        try {
          const value = await entry.refresh({ reason, state: snapshot(entry), signal: entry.abortController.signal });
          const normalized = normalizedResult(entry, value);
          entry.failures = 0;
          entry.converged = normalized.converged;
          entry.remaining = normalized.remaining;
          entry.lastSuccessAt = now();
          const interval = computeNext(entry, normalized);
          entry.nextDueAt = entry.lastSuccessAt + jitter(interval, entry.jitterRatio);
          emit(entry, 'success');
          broadcast('refresh-success', entry);
          return normalized;
        } catch (error) {
          if (entry.abortController.signal.aborted) return { skipped: 'aborted' };
          entry.failures += 1;
          entry.lastError = String(error?.message || error || 'Refresh failed');
          const delay = clamp(entry.minIntervalMs * Math.pow(2, Math.min(5, entry.failures)), entry.minIntervalMs, entry.maxIntervalMs);
          entry.nextDueAt = now() + jitter(delay, entry.jitterRatio);
          emit(entry, 'error');
          broadcast('refresh-error', entry);
          if (entry.throwOnError) throw error;
          return { error };
        }
      });
    } finally {
      entry.running = false;
      emit(entry, 'settled');
    }
  }

  function ensureTimer() {
    if (checkTimer || typeof setInterval !== 'function') return;
    checkTimer = setInterval(() => {
      if (!activeDocument() || interactiveProtectionActive()) return;
      const stamp = now();
      entries.forEach(entry => {
        if (!entry.paused && !entry.running && stamp >= (entry.nextDueAt || 0)) void runEntry(entry, 'timer');
      });
    }, DEFAULTS.checkIntervalMs);
  }

  function pulseControllers(reason = 'acsr-wake') {
    try {
      const acamr = global.P2K_ACAMR;
      if (acamr?.active?.()) (acamr.pulse || acamr.restart)?.(reason);
    } catch (_) {}
    try {
      const continuous = global.P2K_CLIENT_CONTINUOUS_REFRESH;
      const status = continuous?.status?.();
      if (status?.running) continuous.pulse?.(reason);
    } catch (_) {}
    try { global.dispatchEvent?.(new CustomEvent('p2k-acsr-controller-pulse', { detail: { reason, at: now() } })); } catch (_) {}
  }

  function wake(reason, predicate) {
    if (!activeDocument() || interactiveProtectionActive()) return;
    pulseControllers(reason);
    const stamp = now();
    entries.forEach(entry => {
      if (entry.paused || entry.running || (predicate && !predicate(entry))) return;
      const stale = !entry.lastSuccessAt || stamp - entry.lastSuccessAt >= entry.staleAfterMs;
      if (stale) void runEntry(entry, reason);
    });
  }

  function register(key, options = {}) {
    if (!key || typeof options.refresh !== 'function') throw new TypeError('P2K_ACSR.register requires a key and refresh function.');
    const existing = entries.get(String(key));
    if (existing) existing.dispose();
    const cfg = { ...DEFAULTS, ...options };
    const entry = {
      key: String(key), refresh: options.refresh, onState: options.onState,
      minIntervalMs: Math.max(1000, Number(cfg.minIntervalMs) || DEFAULTS.minIntervalMs),
      initialDelayMs: Math.max(1000, Number(cfg.initialDelayMs) || Number(cfg.minIntervalMs) || DEFAULTS.initialDelayMs),
      convergedIntervalMs: Math.max(1000, Number(cfg.convergedIntervalMs) || DEFAULTS.convergedIntervalMs),
      maxIntervalMs: Math.max(1000, Number(cfg.maxIntervalMs) || DEFAULTS.maxIntervalMs),
      staleAfterMs: Math.max(1000, Number(cfg.staleAfterMs) || DEFAULTS.staleAfterMs),
      lockTimeoutMs: Math.max(5000, Number(cfg.lockTimeoutMs) || DEFAULTS.lockTimeoutMs),
      jitterRatio: Number(cfg.jitterRatio) || 0,
      refreshOnFocus: asBool(cfg.refreshOnFocus, true), refreshOnOnline: asBool(cfg.refreshOnOnline, true), refreshOnVisible: asBool(cfg.refreshOnVisible, true),
      throwOnError: Boolean(cfg.throwOnError), running: false, paused: false, disposed: false,
      failures: 0, converged: false, remaining: null, lastAttemptAt: 0, lastSuccessAt: 0, nextDueAt: now() + Math.max(1000, Number(cfg.initialDelayMs) || Number(cfg.minIntervalMs) || DEFAULTS.initialDelayMs), lastReason: '', lastError: '',
      abortController: new AbortController(),
      refreshNow(reason = 'manual') { return runEntry(entry, reason, { force: true }); },
      pause() { entry.paused = true; emit(entry, 'paused'); },
      resume({ immediate = false } = {}) { entry.paused = false; if (immediate) entry.nextDueAt = 0; emit(entry, 'resumed'); if (immediate) void runEntry(entry, 'resume'); },
      markDirty(reason = 'dirty') { entry.converged = false; entry.nextDueAt = 0; if (activeDocument()) void runEntry(entry, reason); },
      dispose() { entry.disposed = true; entry.abortController.abort(); entries.delete(entry.key); emit(entry, 'disposed'); },
      state() { return snapshot(entry); }
    };
    entry.maxIntervalMs = Math.max(entry.maxIntervalMs, entry.convergedIntervalMs, entry.minIntervalMs);
    entry.convergedIntervalMs = clamp(entry.convergedIntervalMs, entry.minIntervalMs, entry.maxIntervalMs);
    entries.set(entry.key, entry);
    ensureTimer();
    emit(entry, 'registered');
    if (cfg.runImmediately) void runEntry(entry, 'register');
    return entry;
  }

  channel?.addEventListener('message', event => {
    const msg = event?.data;
    if (!msg || !msg.key || !entries.has(String(msg.key))) return;
    const entry = entries.get(String(msg.key));
    if (msg.type === 'refresh-success' && Number(msg.state?.lastSuccessAt || 0) > entry.lastSuccessAt) {
      entry.lastSuccessAt = Number(msg.state.lastSuccessAt || 0);
      entry.nextDueAt = Number(msg.state.nextDueAt || entry.lastSuccessAt + entry.convergedIntervalMs);
      entry.converged = Boolean(msg.state.converged);
      entry.remaining = msg.state.remaining ?? entry.remaining;
      emit(entry, 'peer-success');
    }
  });

  if (typeof window !== 'undefined') {
    window.addEventListener('focus', () => wake('focus', e => e.refreshOnFocus), { passive: true });
    window.addEventListener('pageshow', () => wake('pageshow', e => e.refreshOnFocus), { passive: true });
    window.addEventListener('online', () => wake('online', e => e.refreshOnOnline), { passive: true });
    document?.addEventListener?.('visibilitychange', () => { if (document.visibilityState === 'visible') wake('visible', e => e.refreshOnVisible); });
    window.addEventListener('p2k-api-interactive-protection', event => { if (event?.detail?.active === false) setTimeout(() => wake('interactive-protection-cleared'), 100); });
  }

  global.P2K_ACSR = Object.freeze({
    version: VERSION,
    register,
    refresh(key, reason = 'manual') { const e = entries.get(String(key)); return e ? e.refreshNow(reason) : Promise.resolve({ skipped: 'unknown-key' }); },
    markDirty(key, reason = 'dirty') { entries.get(String(key))?.markDirty(reason); },
    pulse(reason = 'manual') { pulseControllers(reason); },
    registrations() { return [...entries.values()].map(snapshot); },
    diagnostics() {
      let acamr = null, continuousRefresh = null;
      try { acamr = global.P2K_ACAMR?.status?.() || null; } catch (_) {}
      try { continuousRefresh = global.P2K_CLIENT_CONTINUOUS_REFRESH?.status?.() || null; } catch (_) {}
      return Object.freeze({ version: VERSION, active: activeDocument(), refreshes: [...entries.values()].map(snapshot), acamr, continuousRefresh });
    }
  });
})(window);
