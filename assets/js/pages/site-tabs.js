/* Inline loader for the four public Promote to King tools. */
(() => {
  "use strict";

  const ROUTES = Object.freeze({
    find: "FindMatch.htm",
    upcoming: "AnalyzeMatches.htm",
    creation: "MatchCreationAnalyzer.htm",
    open: "AnalyzeMatch.html"
  });

  const LEGACY_KEYS = new Set(["creation"]);
  const MAIN_TITLE = "Promote to King Tools";

  const SIMULATED_OAUTH_TRUE_VALUES = new Set([
    "1", "true", "yes", "on", "enabled"
  ]);

  function simulatedOAuthEnabled() {
    const params = new URLSearchParams(window.location.search);
    const explicitValue = params.get("oauth") ?? params.get("simulatedOAuth");
    if (explicitValue !== null) {
      return SIMULATED_OAUTH_TRUE_VALUES.has(
        String(explicitValue).trim().toLowerCase()
      );
    }

    return window.P2K_ENABLE_SIMULATED_OAUTH === true ||
      window.P2K_SITE_CONFIG?.features?.simulatedOAuth === true;
  }

  function carrySimulatedOAuthFlag(url, key) {
    if (key === "find" && simulatedOAuthEnabled()) {
      url.searchParams.set("oauth", "1");
    }
    return url;
  }

  class LocalFileModeError extends Error {
    constructor(route) {
      super(
        `Inline tab loading cannot read ${route} from a file:// page.`
      );
      this.name = "LocalFileModeError";
      this.route = route;
    }
  }

  const tabs = Array.from(
    document.querySelectorAll(".site-tab[data-key][data-route]")
  );
  const content = document.getElementById("tool-content");
  const initialLoading = document.getElementById("tool-loading");

  let currentKey = "";
  let currentRoute = "";
  let activeRenderToken = 0;
  let titleObserver = null;
  let isolatedFrameCleanup = null;

  function fail(message, error = null) {
    if (error) console.error(error);
    content.innerHTML = "";
    content.className = "tool-content";
    const box = document.createElement("div");
    box.className = "tool-loading p2k-error";
    box.textContent = message;
    content.appendChild(box);
  }

  function showLocalServerInstructions(route) {
    content.innerHTML = "";
    content.className = "tool-content";

    const box = document.createElement("div");
    box.className = "tool-local-help";

    const title = document.createElement("strong");
    title.textContent = "Local inline tabs need a web server";
    box.appendChild(title);

    const explanation = document.createElement("p");
    explanation.textContent =
      `The browser blocked ${route} because index.html was opened as a ` +
      "file:// page. This is a browser security restriction, not a missing " +
      "repository file.";
    box.appendChild(explanation);

    const instruction = document.createElement("p");
    instruction.textContent =
      "Run the local launcher included in Correction v3, then use the " +
      "localhost address it opens.";
    box.appendChild(instruction);

    const command = document.createElement("code");
    command.textContent =
      "py serve_local.py C:\\path\\to\\PromoteToKing";
    box.appendChild(command);

    content.appendChild(box);
  }

  function routeForKey(key) {
    return ROUTES[key] || ROUTES.find;
  }

  function selectedKey() {
    const requested = window.location.hash.replace(/^#/, "").trim();
    return Object.hasOwn(ROUTES, requested) ? requested : "find";
  }

  function selectTab(key) {
    const selected = tabs.find(tab => tab.dataset.key === key) || tabs[0];

    tabs.forEach(tab => {
      const active = tab === selected;
      tab.setAttribute("aria-selected", String(active));
      tab.tabIndex = active ? 0 : -1;
    });

    content.setAttribute("aria-labelledby", selected.id);
    initialLoading.textContent =
      `Loading ${selected.textContent.trim()}…`;
  }

  function standaloneURL(tab) {
    const key = tab?.dataset?.key || "find";
    const url = new URL(
      String(tab?.dataset?.route || routeForKey(key)),
      window.location.href
    );
    return carrySimulatedOAuthFlag(url, key).href;
  }

  function openStandalone(tab) {
    const popup = window.open(
      standaloneURL(tab),
      "_blank",
      "noopener"
    );

    if (popup) {
      popup.opener = null;
    }
  }

  function navigateTo(key) {
    if (!Object.hasOwn(ROUTES, key) || key === currentKey) return;

    history.replaceState(null, "", `#${key}`);

    /*
     * A clean reload prevents page-controller globals and event listeners from
     * one tool leaking into another. The selected content still appears below
     * the same toggle bar after the reload.
     */
    window.location.reload();
  }

  function installTabEvents() {
    tabs.forEach((tab, index) => {
      const key = tab.dataset.key;

      if (ROUTES[key] !== tab.dataset.route) {
        throw new Error(
          `Tab route mismatch for ${key}: ${tab.dataset.route}`
        );
      }

      tab.addEventListener("click", event => {
        /*
         * Normal click selects the inline tool. Ctrl-click on Windows/Linux
         * or Cmd-click on macOS opens the definitive page standalone.
         */
        if (event.ctrlKey || event.metaKey) {
          event.preventDefault();
          openStandalone(tab);
          return;
        }

        navigateTo(key);
      });

      tab.addEventListener("auxclick", event => {
        if (event.button !== 1) return;
        event.preventDefault();
        openStandalone(tab);
      });

      tab.addEventListener("keydown", event => {
        if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) {
          return;
        }

        event.preventDefault();

        let targetIndex;
        if (event.key === "Home") {
          targetIndex = 0;
        } else if (event.key === "End") {
          targetIndex = tabs.length - 1;
        } else {
          const direction = event.key === "ArrowRight" ? 1 : -1;
          targetIndex = (index + direction + tabs.length) % tabs.length;
        }

        const target = tabs[targetIndex];
        target.focus();
        navigateTo(target.dataset.key);
      });
    });
  }

  function removeToolAssets() {
    isolatedFrameCleanup?.();
    isolatedFrameCleanup = null;

    document
      .querySelectorAll("[data-p2k-inline-tool-asset]")
      .forEach(node => node.remove());
  }

  const FIND_MATCH_EMBEDDED_STYLE = `
    html,
    body {
      min-height: 0 !important;
      margin: 0 !important;
      padding: 0 !important;
      overflow: hidden !important;
      background: transparent !important;
    }

    .finder {
      width: 100% !important;
      max-width: none !important;
      min-height: 0 !important;
      margin: 0 !important;
      padding: 0 !important;
      border: 0 !important;
      border-radius: 0 !important;
      background: transparent !important;
      background-image: none !important;
      box-shadow: none !important;
    }

    .finder > .header {
      display: none !important;
    }
  `;

  function installFindMatchEmbeddedStyle(frameDocument) {
    let style = frameDocument.getElementById(
      "p2k-index-find-match-embedded-style"
    );

    if (!style) {
      style = frameDocument.createElement("style");
      style.id = "p2k-index-find-match-embedded-style";
      style.textContent = FIND_MATCH_EMBEDDED_STYLE;
      (frameDocument.head || frameDocument.documentElement)
        .appendChild(style);
    }
  }

  function renderFindMatchIsolated(pageURL, token) {
    if (token !== activeRenderToken) return;

    removeToolAssets();
    content.innerHTML = "";
    content.className = "tool-content p2k-tool-find";

    const frame = document.createElement("iframe");
    frame.className = "tool-seamless-runtime";
    frame.title = "Match Assistant";
    frame.src = pageURL.href;
    frame.setAttribute("scrolling", "no");
    /*
     * Trusted same-origin page: preserve the same capabilities as the
     * standalone Match Assistant.
     */

    const updateHeight = () => {
      if (token !== activeRenderToken) return;

      let frameDocument;
      try {
        frameDocument = frame.contentDocument;
      } catch (_) {
        return;
      }

      if (!frameDocument?.documentElement || !frameDocument.body) return;

      installFindMatchEmbeddedStyle(frameDocument);

      const height = Math.max(
        frameDocument.documentElement.scrollHeight,
        frameDocument.body.scrollHeight,
        frameDocument.documentElement.offsetHeight,
        frameDocument.body.offsetHeight,
        180
      );
      frame.style.height = `${Math.ceil(height)}px`;
    };

    frame.addEventListener("load", () => {
      if (token !== activeRenderToken) return;

      /*
       * FindMatch may replace its own document once. Remove observers and
       * timers attached to the previous iframe document first.
       */
      isolatedFrameCleanup?.();

      let resizeObserver = null;
      let interval = null;
      let animationFrame = null;

      const scheduleHeightUpdate = () => {
        if (animationFrame !== null) return;

        animationFrame = window.requestAnimationFrame(() => {
          animationFrame = null;
          updateHeight();
        });
      };

      updateHeight();

      try {
        const frameDocument = frame.contentDocument;

        if (
          frameDocument?.body &&
          frameDocument?.documentElement &&
          "ResizeObserver" in window
        ) {
          resizeObserver = new ResizeObserver(scheduleHeightUpdate);
          resizeObserver.observe(frameDocument.body);
          resizeObserver.observe(frameDocument.documentElement);
        }

        /*
         * Low-frequency fallback for the historical document replacement and
         * browsers that do not report all content-size changes.
         */
        interval = window.setInterval(updateHeight, 700);
      } catch (error) {
        console.warn(
          "Unable to install Match Assistant auto-height observer.",
          error
        );
      }

      isolatedFrameCleanup = () => {
        resizeObserver?.disconnect();

        if (animationFrame !== null) {
          window.cancelAnimationFrame(animationFrame);
          animationFrame = null;
        }

        if (interval !== null) {
          window.clearInterval(interval);
          interval = null;
        }
      };
    });

    content.appendChild(frame);
    keepMainDocumentTitle();
  }

  function resolveAssetURL(value, pageURL) {
    return new URL(value, pageURL).href;
  }

  async function loadStyleLink(link, pageURL) {
    const href = link.getAttribute("href");
    if (!href) return;

    const node = document.createElement("link");
    node.rel = "stylesheet";
    node.href = resolveAssetURL(href, pageURL);
    node.dataset.p2kInlineToolAsset = "true";

    await new Promise(resolve => {
      node.addEventListener("load", resolve, { once: true });
      node.addEventListener("error", resolve, { once: true });
      document.head.appendChild(node);
    });
  }

  function loadInlineStyle(style) {
    const node = document.createElement("style");
    node.dataset.p2kInlineToolAsset = "true";
    node.textContent = style.textContent || "";
    document.head.appendChild(node);
  }

  async function installStyles(pageDocument, pageURL) {
    const styleNodes = Array.from(
      pageDocument.querySelectorAll(
        'link[rel~="stylesheet"][href], style'
      )
    );

    for (const node of styleNodes) {
      if (node.tagName === "LINK") {
        await loadStyleLink(node, pageURL);
      } else {
        loadInlineStyle(node);
      }
    }

    /*
     * Re-append the main stylesheet after child styles. This keeps the index
     * shell stable when a historical page contains global body rules.
     */
    const hostOverride = document.createElement("link");
    hostOverride.rel = "stylesheet";
    hostOverride.href = new URL(
      "assets/css/site.css",
      window.location.href
    ).href;
    hostOverride.dataset.p2kInlineToolAsset = "true";
    document.head.appendChild(hostOverride);
  }

  function isStatcounterScript(url) {
    return /(?:^|\.)statcounter\.com$/i.test(url.hostname) ||
      /(?:^|\.)c\.statcounter\.com$/i.test(url.hostname);
  }

  async function executeScript(sourceScript, pageURL) {
    const type = String(sourceScript.getAttribute("type") || "")
      .trim()
      .toLowerCase();

    if (type && ![
      "text/javascript",
      "application/javascript",
      "module"
    ].includes(type)) {
      return;
    }

    const sourceURL = sourceScript.getAttribute("src");
    const script = document.createElement("script");
    script.dataset.p2kInlineToolAsset = "true";

    if (type) script.type = type;

    if (sourceURL) {
      const absoluteURL = new URL(sourceURL, pageURL);
      if (isStatcounterScript(absoluteURL)) return;

      script.src = absoluteURL.href;
      script.async = false;

      await new Promise((resolve, reject) => {
        script.addEventListener("load", resolve, { once: true });
        script.addEventListener(
          "error",
          () => reject(
            new Error(`Unable to load script: ${absoluteURL.href}`)
          ),
          { once: true }
        );
        document.body.appendChild(script);
      });
      return;
    }

    script.textContent = sourceScript.textContent || "";
    document.body.appendChild(script);
  }

  async function executeScripts(pageDocument, pageURL) {
    const scripts = Array.from(pageDocument.querySelectorAll("script"));
    for (const script of scripts) {
      await executeScript(script, pageURL);
    }
  }

  function removeEmbeddedBranding(fragment, key) {
    if (key === "find") {
      fragment.querySelectorAll(".header").forEach(header => {
        const text = header.textContent || "";
        const hasP2KLogo = header.querySelector(
          'img[src*="p2k-logo"], img[alt*="Promote to King"]'
        );
        if (/Promote to King Match Assistant/i.test(text) || hasP2KLogo) {
          header.remove();
        }
      });
      return;
    }

    if (key === "upcoming") {
      fragment
        .querySelectorAll("#p2kUpcomingAnalyzer > .p2k-header")
        .forEach(header => header.remove());
      return;
    }

    if (key === "creation") {
      fragment
        .querySelectorAll("#p2kCreationAnalyzer > .p2k-header")
        .forEach(header => header.remove());
      return;
    }

    if (key === "open") {
      fragment
        .querySelectorAll(
          "#p2kUpcomingAnalyzer > .p2k-header h1, " +
          "#p2kUpcomingAnalyzer > .p2k-header h2, " +
          "#p2kDirectClubLogo, " +
          '#p2kUpcomingAnalyzer > .p2k-header img[src*="p2k-logo"], ' +
          '#p2kUpcomingAnalyzer > .p2k-header img[alt*="Promote to King"]'
        )
        .forEach(node => node.remove());
    }
  }

  function bodyFragment(pageDocument, key) {
    const fragment = document.createDocumentFragment();
    const bodyNodes = Array.from(pageDocument.body.childNodes);

    bodyNodes.forEach(node => {
      if (node.nodeType === Node.ELEMENT_NODE &&
          node.tagName === "SCRIPT") {
        return;
      }
      fragment.appendChild(document.importNode(node, true));
    });

    const staging = document.createElement("div");
    staging.appendChild(fragment);
    removeEmbeddedBranding(staging, key);

    const result = document.createDocumentFragment();
    while (staging.firstChild) {
      result.appendChild(staging.firstChild);
    }
    return result;
  }

  function keepMainDocumentTitle() {
    document.title = MAIN_TITLE;

    titleObserver?.disconnect();
    const titleNode = document.querySelector("head > title");
    if (!titleNode) return;

    titleObserver = new MutationObserver(() => {
      if (document.title !== MAIN_TITLE) {
        document.title = MAIN_TITLE;
      }
    });
    titleObserver.observe(titleNode, {
      childList: true,
      characterData: true,
      subtree: true
    });
  }

  async function renderDocumentHTML(html, pageURL, key, token) {
    if (token !== activeRenderToken) return;

    const parsed = new DOMParser().parseFromString(
      String(html || ""),
      "text/html"
    );

    removeToolAssets();
    content.innerHTML = "";
    content.className = `tool-content p2k-tool-${key}`;

    await installStyles(parsed, pageURL);
    if (token !== activeRenderToken) return;

    content.appendChild(bodyFragment(parsed, key));
    await executeScripts(parsed, pageURL);

    keepMainDocumentTitle();
  }

  function installDocumentWriteCapture(onCaptured) {
    const original = {
      open: document.open.bind(document),
      write: document.write.bind(document),
      writeln: document.writeln.bind(document),
      close: document.close.bind(document)
    };

    let capturing = false;
    let chunks = [];
    let restored = false;

    function restore() {
      if (restored) return;
      restored = true;
      document.open = original.open;
      document.write = original.write;
      document.writeln = original.writeln;
      document.close = original.close;
    }

    document.open = function capturedOpen() {
      capturing = true;
      chunks = [];
      return document;
    };

    document.write = function capturedWrite(...values) {
      if (!capturing) {
        return original.write(...values);
      }
      chunks.push(values.join(""));
    };

    document.writeln = function capturedWriteln(...values) {
      if (!capturing) {
        return original.writeln(...values);
      }
      chunks.push(values.join("") + "\n");
    };

    document.close = function capturedClose() {
      if (!capturing) {
        return original.close();
      }

      const html = chunks.join("");
      capturing = false;
      restore();

      Promise.resolve(onCaptured(html)).catch(error => {
        fail("Unable to display the selected tool.", error);
      });
    };

    return restore;
  }

  async function loadTool(key) {
    currentKey = key;
    currentRoute = routeForKey(key);
    const token = ++activeRenderToken;
    const pageURL = carrySimulatedOAuthFlag(
      new URL(currentRoute, window.location.href),
      key
    );

    selectTab(key);
    keepMainDocumentTitle();

    if (window.location.protocol === "file:") {
      throw new LocalFileModeError(currentRoute);
    }

    /*
     * FindMatch is a full-document legacy application. It downloads another
     * HTML document and replaces itself with document.open/write/close.
     * Running that generated app in the index document changes its global
     * document context and can stall the search after the button is pressed.
     *
     * Keep it in a same-origin isolated runtime, remove all visible iframe
     * presentation, hide its own title/P2K branding, and continuously match
     * its content height. The other three tools remain true inline content.
     */
    if (key === "find") {
      renderFindMatchIsolated(pageURL, token);
      return;
    }

    const response = await fetch(pageURL.href, { cache: "no-store" });
    if (!response.ok) {
      throw new Error(
        `Unable to load ${currentRoute}: HTTP ${response.status}`
      );
    }

    const html = await response.text();
    const parsed = new DOMParser().parseFromString(html, "text/html");

    if (!LEGACY_KEYS.has(key)) {
      await renderDocumentHTML(html, pageURL.href, key, token);
      return;
    }

    /*
     * Match Assistant and Match Creation still generate their final document
     * with document.open/write/close. Capture that generated HTML and render it
     * into #tool-content instead of allowing it to replace index.html.
     */
    removeToolAssets();
    content.innerHTML = "";
    content.className = `tool-content p2k-tool-${key}`;
    await installStyles(parsed, pageURL.href);
    content.appendChild(bodyFragment(parsed, key));

    const captured = new Promise(resolve => {
      installDocumentWriteCapture(async finalHTML => {
        await renderDocumentHTML(
          finalHTML,
          pageURL.href,
          key,
          token
        );
        resolve();
      });
    });

    await executeScripts(parsed, pageURL.href);
    keepMainDocumentTitle();

    /*
     * The controller fetch is asynchronous. Do not block the interface; the
     * captured promise completes when the historical page has been generated.
     */
    void captured;
  }

  installTabEvents();

  const key = selectedKey();
  loadTool(key).catch(error => {
    if (error instanceof LocalFileModeError) {
      console.warn(error.message);
      showLocalServerInstructions(error.route);
      return;
    }

    fail(`Unable to load ${routeForKey(key)}.`, error);
  });
})();
