(function() {
  'use strict';

  const endpoint = 'https://collector.maxk.site/api/log';

  let lcpValue = 0;
  let clsValue = 0;
  let inpValue = 0;

  const reportedErrors = new Set();
  let errorCount = 0;
  const MAX_ERRORS = 10;

  function getSessionId() {
    let sid = sessionStorage.getItem('_collector_sid');
    if (!sid) {
      sid = Math.random().toString(36).substring(2) + Date.now().toString(36);
      sessionStorage.setItem('_collector_sid', sid);
      document.cookie = '_collector_sid=' + sid + '; path=/; SameSite=Lax';
    }
    return sid;
  }

  function detectImagesEnabled() {
    try {
      return typeof Image !== 'undefined' && Image.length === 0;
    } catch (e) {
      return false;
    }
  }

  function detectCSSEnabled() {
    try {
      var testEl = document.createElement('div');
      testEl.style.display = 'none';
      return testEl.style.display === 'none';
    } catch (e) {
      return false;
    }
  }

  function getTechnographics() {
    let networkInfo = {};
    if ('connection' in navigator) {
      const conn = navigator.connection;
      networkInfo = {
        effectiveType: conn.effectiveType,
        downlink: conn.downlink,
        rtt: conn.rtt,
        saveData: conn.saveData
      };
    }

    return {
      userAgent: navigator.userAgent,
      language: navigator.language,
      cookiesEnabled: navigator.cookieEnabled,
      javascriptEnabled: true,
      imagesEnabled: detectImagesEnabled(),
      cssEnabled: detectCSSEnabled(),
      viewportWidth: window.innerWidth,
      viewportHeight: window.innerHeight,
      screenWidth: window.screen.width,
      screenHeight: window.screen.height,
      pixelRatio: window.devicePixelRatio,
      cores: navigator.hardwareConcurrency || 0,
      memory: navigator.deviceMemory || 0,
      network: networkInfo,
      colorScheme: window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light',
      timezone: Intl.DateTimeFormat().resolvedOptions().timeZone
    };
  }

  function round(n) {
    return Math.round(n * 100) / 100;
  }

  function observeLCP() {
    const observer = new PerformanceObserver(function(list) {
      const entries = list.getEntries();
      const lastEntry = entries[entries.length - 1];
      lcpValue = lastEntry.renderTime || lastEntry.loadTime;
    });
    observer.observe({ type: 'largest-contentful-paint', buffered: true });
    return observer;
  }

  function observeCLS() {
    const observer = new PerformanceObserver(function(list) {
      for (const entry of list.getEntries()) {
        if (!entry.hadRecentInput) {
          clsValue += entry.value;
        }
      }
    });
    observer.observe({ type: 'layout-shift', buffered: true });
    return observer;
  }

  function observeINP() {
    const interactions = [];

    const observer = new PerformanceObserver(function(list) {
      for (const entry of list.getEntries()) {
        if (entry.interactionId) {
          interactions.push(entry.duration);
        }
      }
      if (interactions.length > 0) {
        interactions.sort(function(a, b) { return b - a; });
        inpValue = interactions[0];
      }
    });
    observer.observe({ type: 'event', buffered: true, durationThreshold: 16 });
    return observer;
  }

  const thresholds = {
    lcp: [2500, 4000],
    cls: [0.1, 0.25],
    inp: [200, 500]
  };

  function getVitalsScore(metric, value) {
    const t = thresholds[metric];
    if (!t) return null;
    if (value <= t[0]) return 'good';
    if (value <= t[1]) return 'needsImprovement';
    return 'poor';
  }

  function getNavigationTiming() {
    const entries = performance.getEntriesByType('navigation');
    if (!entries.length) return {};

    const n = entries[0];

    return {
      dnsLookup: round(n.domainLookupEnd - n.domainLookupStart),
      tcpConnect: round(n.connectEnd - n.connectStart),
      tlsHandshake: n.secureConnectionStart > 0 ? round(n.connectEnd - n.secureConnectionStart) : 0,
      ttfb: round(n.responseStart - n.requestStart),
      download: round(n.responseEnd - n.responseStart),
      domInteractive: round(n.domInteractive - n.fetchStart),
      domComplete: round(n.domComplete - n.fetchStart),
      loadEvent: round(n.loadEventEnd - n.fetchStart),
      fetchTime: round(n.responseEnd - n.fetchStart),
      transferSize: n.transferSize,
      headerSize: n.transferSize - n.encodedBodySize
    };
  }

  function getResourceSummary() {
    const resources = performance.getEntriesByType('resource');

    const summary = {
      script: { count: 0, totalSize: 0, totalDuration: 0 },
      link: { count: 0, totalSize: 0, totalDuration: 0 },
      img: { count: 0, totalSize: 0, totalDuration: 0 },
      font: { count: 0, totalSize: 0, totalDuration: 0 },
      fetch: { count: 0, totalSize: 0, totalDuration: 0 },
      xmlhttprequest: { count: 0, totalSize: 0, totalDuration: 0 },
      other: { count: 0, totalSize: 0, totalDuration: 0 }
    };

    resources.forEach(function(r) {
      const type = summary[r.initiatorType] ? r.initiatorType : 'other';
      summary[type].count++;
      summary[type].totalSize += r.transferSize || 0;
      summary[type].totalDuration += r.duration || 0;
    });

    return {
      totalResources: resources.length,
      byType: summary
    };
  }

  function send(payload) {
    const blob = new Blob([JSON.stringify(payload)], { type: 'application/json' });

    if (navigator.sendBeacon) {
      navigator.sendBeacon(endpoint, blob);
    } else {
      fetch(endpoint, {
        method: 'POST',
        body: blob,
        keepalive: true
      }).catch(function(err) {
        console.error('Analytics beacon failed:', err);
      });
    }
  }

  function collect() {
    const payload = {
      url: window.location.href,
      title: document.title,
      referrer: document.referrer,
      timestamp: new Date().toISOString(),
      type: 'pageview',
      session: getSessionId(),
      technographics: getTechnographics(),
      timing: getNavigationTiming(),
      resources: getResourceSummary()
    };

    send(payload);
  }

  function sendVitals() {
    // Don't send if LCP hasn't been recorded yet — avoids polluting DB with zero rows
    if (lcpValue === 0) return;

    const vitals = {
      lcp: { value: round(lcpValue), score: getVitalsScore('lcp', lcpValue) },
      cls: { value: round(clsValue * 1000) / 1000, score: getVitalsScore('cls', clsValue) },
      inp: { value: round(inpValue), score: getVitalsScore('inp', inpValue) }
    };
    send({
      type: 'vitals',
      vitals: vitals,
      url: window.location.href,
      session: getSessionId(),
      timestamp: new Date().toISOString()
    });
  }

  function reportError(errorData) {
    if (errorCount >= MAX_ERRORS) return;

    const key = errorData.type + ':' + errorData.message + ':' + (errorData.source || '') + ':' + (errorData.line || '');
    if (reportedErrors.has(key)) return;
    reportedErrors.add(key);
    errorCount++;

    send({
      type: 'error',
      error: errorData,
      timestamp: new Date().toISOString(),
      url: window.location.href,
      session: getSessionId()
    });
  }

  window.addEventListener('error', function(event) {
    if (event instanceof ErrorEvent) {
      reportError({
        type: 'js-error',
        message: event.message,
        source: event.filename,
        line: event.lineno,
        column: event.colno,
        stack: event.error ? event.error.stack : ''
      });
    } else {
      const target = event.target;
      if (target && (target.tagName === 'IMG' || target.tagName === 'SCRIPT' || target.tagName === 'LINK')) {
        reportError({
          type: 'resource-error',
          tagName: target.tagName,
          src: target.src || target.href || ''
        });
      }
    }
  }, true);

  window.addEventListener('unhandledrejection', function(event) {
    const reason = event.reason;
    reportError({
      type: 'promise-rejection',
      message: reason instanceof Error ? reason.message : String(reason),
      stack: reason instanceof Error ? reason.stack : ''
    });
  });

  function initMouseTracking() {
    let lastMoveTime = 0;
    const moveThrottle = 100;

    document.addEventListener('mousemove', function(event) {
      const now = Date.now();
      if (now - lastMoveTime < moveThrottle) return;
      lastMoveTime = now;

      send({
        type: 'mouse_move',
        x: event.clientX,
        y: event.clientY,
        pageX: event.pageX,
        pageY: event.pageY,
        timestamp: new Date().toISOString(),
        url: window.location.href,
        session: getSessionId()
      });
    });

    document.addEventListener('click', function(event) {
      send({
        type: 'mouse_click',
        x: event.clientX,
        y: event.clientY,
        pageX: event.pageX,
        pageY: event.pageY,
        button: event.button,
        timestamp: new Date().toISOString(),
        url: window.location.href,
        session: getSessionId()
      });
    });
  }

  function initKeyboardTracking() {
    function handleKeyEvent(event) {
      if (event.isComposing || event.keyCode === 229) {
        return;
      }

      send({
        type: event.type === 'keydown' ? 'key_down' : 'key_up',
        key: event.key,
        code: event.code,
        altKey: event.altKey,
        ctrlKey: event.ctrlKey,
        shiftKey: event.shiftKey,
        metaKey: event.metaKey,
        repeat: event.repeat,
        timestamp: new Date().toISOString(),
        url: window.location.href,
        session: getSessionId()
      });
    }

    document.addEventListener('keydown', handleKeyEvent);
    document.addEventListener('keyup', handleKeyEvent);
  }

  function initScrollTracking() {
    let lastScrollTime = 0;
    const scrollThrottle = 200;

    window.addEventListener('scroll', function() {
      const now = Date.now();
      if (now - lastScrollTime < scrollThrottle) return;
      lastScrollTime = now;

      const scrollX = window.pageXOffset || document.documentElement.scrollLeft;
      const scrollY = window.pageYOffset || document.documentElement.scrollTop;

      send({
        type: 'scroll',
        x: scrollX,
        y: scrollY,
        timestamp: new Date().toISOString(),
        url: window.location.href,
        session: getSessionId()
      });
    });
  }

  function initIdleTracking() {
    let lastActivityTime = Date.now();
    let idleStartTime = null;
    let isIdle = false;
    let idleCheckInterval = null;

    const IDLE_THRESHOLD = 2000;
    const CHECK_INTERVAL = 500;

    function recordActivity() {
      const now = Date.now();
      
      if (isIdle) {
        const idleDuration = now - idleStartTime;
        send({
          type: 'idle_end',
          idleStart: new Date(idleStartTime).toISOString(),
          idleEnd: new Date(now).toISOString(),
          duration: idleDuration,
          timestamp: new Date().toISOString(),
          url: window.location.href,
          session: getSessionId()
        });
        isIdle = false;
      }
      
      lastActivityTime = now;
    }

    function checkIdle() {
      const now = Date.now();
      const timeSinceActivity = now - lastActivityTime;
      
      if (!isIdle && timeSinceActivity >= IDLE_THRESHOLD) {
        isIdle = true;
        idleStartTime = lastActivityTime;
      }
    }

    document.addEventListener('mousemove', recordActivity);
    document.addEventListener('click', recordActivity);
    document.addEventListener('keydown', recordActivity);
    document.addEventListener('scroll', recordActivity);
    document.addEventListener('touchstart', recordActivity);

    idleCheckInterval = setInterval(checkIdle, CHECK_INTERVAL);
  }

  initMouseTracking();
  initKeyboardTracking();
  initScrollTracking();
  initIdleTracking();
  observeLCP();
  observeCLS();
  observeINP();

  window.addEventListener('load', function() {
    setTimeout(collect, 0);
  });

  document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'hidden') {
      sendVitals();
    }
  });

  // pagehide fires on tab close/navigation where visibilitychange may not fire
  window.addEventListener('pagehide', sendVitals);

})();
