/* Coordinates completed analyses between retained tool tabs. */
(() => {
  "use strict";

  if (window.P2K_ANALYSIS_COORDINATOR) return;

  const enabled = window.P2K_SITE_CONFIG?.features?.analysisSynchronization !== false;
  const CHANNEL_NAME = "p2k-analysis-coordination-v1";
  const tabId = (() => {
    try { return crypto.randomUUID(); }
    catch (_) { return `${Date.now()}-${Math.random().toString(36).slice(2)}`; }
  })();
  const registrations = new Map();
  const lastSeenBySource = new Map();
  const lastMessageBySource = new Map();
  let channel = null;
  let sequence = 0;

  if (enabled && "BroadcastChannel" in window) {
    try { channel = new BroadcastChannel(CHANNEL_NAME); }
    catch (_) { channel = null; }
  }

  function post(message) {
    if (!enabled) return;
    const payload = { ...message, senderId: tabId, sentAt: Date.now(), sequence: ++sequence };
    try { channel?.postMessage(payload); } catch (_) { /* optional */ }
    try { localStorage.setItem("p2k-analysis-event", JSON.stringify(payload)); } catch (_) { /* optional */ }
  }

  function messageVersion(message) {
    return Number(message?.completedAt || message?.sentAt || 0);
  }

  function remember(message) {
    const source = String(message?.source || "");
    const version = messageVersion(message);
    if (!source || version <= Number(lastSeenBySource.get(source) || 0)) return false;
    lastSeenBySource.set(source, version);
    lastMessageBySource.set(source, message);
    return true;
  }

  function scheduleRegistration(key, registration, message) {
    if (!registration || key === String(message?.source || "")) return;
    if (messageVersion(message) <= messageVersion(registration.lastCompletedMessage)) return;
    if (messageVersion(message) > messageVersion(registration.pendingMessage)) {
      registration.pendingMessage = message;
    }
    if (registration.timer !== null || registration.running) return;

    const attempt = async () => {
      registration.timer = null;
      const pending = registration.pendingMessage;
      if (!pending) return;
      if (window.P2K_TAB_ACTIVITY && !window.P2K_TAB_ACTIVITY.isActive()) {
        registration.pendingMessage = null;
        return;
      }
      if (registration.running || registration.isBusy?.()) {
        registration.timer = window.setTimeout(() => { void attempt(); }, 250);
        return;
      }

      let allowed = true;
      try { allowed = registration.canRefresh ? Boolean(registration.canRefresh(pending)) : true; }
      catch (_) { allowed = false; }
      if (!allowed) {
        registration.pendingMessage = null;
        return;
      }

      registration.pendingMessage = null;
      registration.running = true;
      try {
        await registration.refresh({
          synchronized: true,
          source: String(pending.source || ""),
          completedAt: messageVersion(pending)
        });
        registration.lastCompletedMessage = pending;
      } catch (error) {
        console.warn(`P2K analysis synchronization: ${key} refresh failed`, error);
      } finally {
        registration.running = false;
        if (registration.pendingMessage) {
          registration.timer = window.setTimeout(() => { void attempt(); }, 0);
        }
      }
    };

    registration.timer = window.setTimeout(() => { void attempt(); }, 0);
  }

  function distribute(message) {
    for (const [key, registration] of registrations) {
      scheduleRegistration(key, registration, message);
    }
  }

  function handle(message) {
    if (!enabled || !message || message.senderId === tabId || message.type !== "analysis-complete") return;
    if (!remember(message)) return;
    distribute(message);
  }

  channel?.addEventListener("message", event => { handle(event.data); });
  window.addEventListener("storage", event => {
    if (event.key !== "p2k-analysis-event" || !event.newValue) return;
    try { handle(JSON.parse(event.newValue)); } catch (_) { /* ignore malformed storage events */ }
  });

  function register(key, options = {}) {
    const normalizedKey = String(key || "").trim();
    if (!normalizedKey || typeof options.refresh !== "function") return () => {};
    const registration = {
      ...options,
      running: false,
      timer: null,
      pendingMessage: null,
      lastCompletedMessage: null
    };
    registrations.set(normalizedKey, registration);

    const latest = [...lastMessageBySource.values()]
      .filter(message => String(message?.source || "") !== normalizedKey)
      .sort((a, b) => messageVersion(b) - messageVersion(a))[0];
    if (latest) scheduleRegistration(normalizedKey, registration, latest);

    return () => {
      if (registration.timer !== null) window.clearTimeout(registration.timer);
      registrations.delete(normalizedKey);
    };
  }

  function complete(source, detail = {}) {
    if (detail.synchronized || (window.P2K_TAB_ACTIVITY && !window.P2K_TAB_ACTIVITY.isActive())) return;
    const completedAt = Date.now();
    const message = {
      type: "analysis-complete",
      source: String(source),
      completedAt,
      detail,
      senderId: tabId,
      sentAt: completedAt,
      sequence: sequence + 1
    };
    lastSeenBySource.set(message.source, completedAt);
    lastMessageBySource.set(message.source, message);
    post({ type: message.type, source: message.source, completedAt, detail });
  }

  function diagnostics() {
    return Object.freeze({
      enabled,
      broadcastChannelAvailable: Boolean(channel),
      registeredTools: [...registrations.keys()],
      pendingTools: [...registrations.entries()]
        .filter(([, registration]) => Boolean(registration.pendingMessage))
        .map(([key]) => key),
      lastSeen: Object.fromEntries(lastSeenBySource)
    });
  }

  function close() {
    for (const registration of registrations.values()) {
      if (registration.timer !== null) window.clearTimeout(registration.timer);
    }
    registrations.clear();
    try { channel?.close(); } catch (_) { /* optional */ }
    channel = null;
  }

  window.addEventListener("pagehide", close, { once: true });
  window.P2K_ANALYSIS_COORDINATOR = Object.freeze({ register, complete, diagnostics, close });
})();
