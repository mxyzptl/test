#!/usr/bin/env node
/* =============================================================================
   Browser measurements for the things only a browser knows (BUG-0013, BUG-0014).

   Why this file exists
   --------------------
   tests/render.php can read the markup and the stylesheet. It cannot resolve a cascade,
   it cannot evaluate a media query, and it cannot lay anything out. BUG-0013 is the proof:
   the `@media (prefers-contrast: more)` block WAS in the stylesheet - tests/render.php even
   asserted its presence, and the suite was green - yet the visitor who asked for higher
   contrast got a LIGHTER plate (0.62 -> 0.42, ~3.1:1, below AA), because the declaration
   `--plate-alpha: calc(var(--plate-alpha) + .18)` is a cycle and silently fell back to the
   inherited value.

   So a `prefers-*` branch has to be MEASURED:
       matchMedia('(prefers-contrast: more)').matches === true      <- the branch is really on
       getComputedStyle(el).backgroundColor                         <- the value really applied

   The same goes for the error chip (BUG-0014): whether it covers the compass labels is a
   question about boxes on a screen, and only a laid-out page can answer it.

   How to run
   ----------
       node tests/browser-check.mjs --url https://test.ma.harmony-solutions.hu
       node tests/browser-check.mjs --url http://127.0.0.1:8099 --chrome /path/to/chrome

   It drives any Chrome/Chromium over the DevTools protocol and has NO dependencies (Node 21+
   for the global WebSocket). It never runs the application: it only opens a URL that is
   already being served. The project's VPS carries no browser, which is why this runner takes
   a URL instead of a directory - point it at the deployed site or at a preview served from
   the server.

   Exit code 0 = every measurement passed, 1 = at least one failed.
   ============================================================================= */

import { spawn } from 'node:child_process';
import { mkdtempSync, rmSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

/* ------------------------------------------------------------------ arguments */

const argv = process.argv.slice(2);
const arg = (name, fallback = null) => {
  const i = argv.indexOf('--' + name);
  return i === -1 ? fallback : argv[i + 1];
};

const BASE = (arg('url') || process.env.SKY_URL || 'https://test.ma.harmony-solutions.hu').replace(/\/$/, '');
const CHROME = arg('chrome') || process.env.CHROME || [
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  '/Applications/Chromium.app/Contents/MacOS/Chromium',
  '/usr/bin/google-chrome',
  '/usr/bin/chromium',
  '/usr/bin/chromium-browser',
].find((p) => existsSync(p));

if (!CHROME) {
  console.error('No Chrome/Chromium found. Pass --chrome <path> or set CHROME.');
  process.exit(2);
}

/* ------------------------------------------------------------- tiny assertions */

let passed = 0;
const failures = [];

const ok = (name, condition, detail = '') => {
  if (condition) {
    passed++;
    console.log(`  PASS  ${name.padEnd(64)} ${detail}`);
    return true;
  }
  failures.push(`${name} — ${detail}`);
  console.log(`  FAIL  ${name.padEnd(64)} ${detail}`);
  return false;
};

const near = (name, actual, expected, tolerance, detail = '') =>
  ok(name, Math.abs(actual - expected) <= tolerance,
    `actual=${actual} expected=${expected} tol=${tolerance} ${detail}`);

const section = (title) => console.log(`\n${'='.repeat(78)}\n${title}\n${'='.repeat(78)}`);

/* ------------------------------------------------------------ WCAG arithmetic */

/** Relative luminance, WCAG 2.1. */
const luminance = ([r, g, b]) => {
  const c = (v) => (v / 255 <= 0.04045 ? v / 255 / 12.92 : ((v / 255 + 0.055) / 1.055) ** 2.4);
  return 0.2126 * c(r) + 0.7152 * c(g) + 0.0722 * c(b);
};

/** White text on `rgb(plate / alpha)` composited over `backdrop`. The backdrop-filter's own
    darkening is left out, exactly as in the spec 10.3 table - the number is the pessimistic one. */
const contrastOn = (alpha, backdrop, plate = [5, 10, 22]) => {
  const composite = plate.map((p, i) => alpha * p + (1 - alpha) * backdrop[i]);
  return 1.05 / (luminance(composite) + 0.05);
};

/** The Sun's corona: the worst backdrop in the whole design (spec 10.3). */
const CORONA = [255, 248, 224];

const parseRgba = (value) => {
  const m = value.match(/rgba?\(([^)]+)\)/);
  if (!m) return null;
  const parts = m[1].split(/[,/]/).map((p) => parseFloat(p.trim()));
  return { rgb: parts.slice(0, 3), alpha: parts.length > 3 ? parts[3] : 1 };
};

/* ------------------------------------------------------------------ CDP client */

const userDataDir = mkdtempSync(join(tmpdir(), 'sky-cdp-'));
const port = 9500 + Math.floor(Math.random() * 400);

const chrome = spawn(CHROME, [
  '--headless=new',
  `--remote-debugging-port=${port}`,
  `--user-data-dir=${userDataDir}`,
  '--no-first-run',
  '--no-default-browser-check',
  '--disable-background-timer-throttling',
  '--disable-renderer-backgrounding',
  '--force-device-scale-factor=1',
  'about:blank',
], { stdio: ['ignore', 'ignore', 'pipe'] });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const browserWsUrl = async () => {
  for (let i = 0; i < 100; i++) {
    try {
      const res = await fetch(`http://127.0.0.1:${port}/json/version`);
      return (await res.json()).webSocketDebuggerUrl;
    } catch {
      await sleep(100);
    }
  }
  throw new Error('Chrome did not open its debugging port');
};

const ws = new WebSocket(await browserWsUrl());
await new Promise((resolve, reject) => {
  ws.addEventListener('open', resolve, { once: true });
  ws.addEventListener('error', reject, { once: true });
});

let nextId = 1;
const pending = new Map();
const listeners = [];

ws.addEventListener('message', (event) => {
  const message = JSON.parse(event.data);
  if (message.id && pending.has(message.id)) {
    const { resolve, reject } = pending.get(message.id);
    pending.delete(message.id);
    message.error ? reject(new Error(JSON.stringify(message.error))) : resolve(message.result);
    return;
  }
  listeners.forEach((fn) => fn(message));
});

const send = (method, params = {}, sessionId) =>
  new Promise((resolve, reject) => {
    const id = nextId++;
    pending.set(id, { resolve, reject });
    ws.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
  });

const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
const cdp = (method, params) => send(method, params, sessionId);

await cdp('Page.enable');
await cdp('Runtime.enable');
await cdp('Network.enable');
await cdp('Log.enable');

/* Console noise is collected with the phase it happened in, so that the errors we cause on
   purpose (killing the network) are not confused with the ones the app produces on its own. */
let phase = 'startup';
let apiRequests = 0;
const consoleErrors = [];
listeners.push((message) => {
  if (message.sessionId !== sessionId) return;
  if (message.method === 'Runtime.consoleAPICalled' && ['error', 'warning'].includes(message.params.type)) {
    consoleErrors.push({ phase, kind: 'console.' + message.params.type, text: JSON.stringify(message.params.args?.[0]?.value ?? '') });
  }
  if (message.method === 'Log.entryAdded' && ['error', 'warning'].includes(message.params.entry.level)) {
    consoleErrors.push({ phase, kind: message.params.entry.source, text: message.params.entry.text });
  }
  if (message.method === 'Network.requestWillBeSent' && /sky\.php/.test(message.params.request.url)) {
    apiRequests++;
  }
  if (message.method === 'Runtime.exceptionThrown') {
    consoleErrors.push({ phase, kind: 'exception', text: message.params.exceptionDetails.text });
  }
});

const evaluate = async (expression) => {
  const { result, exceptionDetails } = await cdp('Runtime.evaluate', {
    expression: `(() => { ${expression} })()`,
    returnByValue: true,
    awaitPromise: true,
  });
  if (exceptionDetails) throw new Error(exceptionDetails.text + ' ' + (exceptionDetails.exception?.description ?? ''));
  return result.value;
};

const setViewport = (width, height) =>
  cdp('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });

const navigate = async (url) => {
  const loaded = new Promise((resolve) => {
    const fn = (m) => {
      if (m.sessionId === sessionId && m.method === 'Page.loadEventFired') {
        listeners.splice(listeners.indexOf(fn), 1);
        resolve();
      }
    };
    listeners.push(fn);
  });
  await cdp('Page.navigate', { url });
  await loaded;
  /* Two frames: the collision check runs in a double rAF, and --info-h is written in one. */
  await evaluate('return new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));');
};

const setMedia = (features) => cdp('Emulation.setEmulatedMedia', { features });

const waitFor = async (expression, timeoutMs = 12000) => {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (await evaluate(`return !!(${expression});`)) return true;
    await sleep(150);
  }
  return false;
};

const finish = async () => {
  try { ws.close(); } catch {}
  chrome.kill();
  await sleep(200);
  try { rmSync(userDataDir, { recursive: true, force: true }); } catch {}

  console.log(`\n${'='.repeat(78)}`);
  console.log(`TOTAL: ${passed} passed, ${failures.length} failed   (url: ${BASE})`);
  if (failures.length) {
    console.log('\nFAILURES:');
    failures.forEach((f) => console.log('  - ' + f));
  }
  console.log('='.repeat(78));
  process.exit(failures.length ? 1 : 0);
};

/* =============================================================================
   1. prefers-contrast: more - measured, not read off the stylesheet (BUG-0013)
   ============================================================================= */

section('1. prefers-contrast: more — the measured plate (BUG-0013, spec 10.3)');

/**
 * Wait until the plate stops moving before reading it.
 *
 * --plate-alpha is a registered custom property with a 1200 ms transition (spec 7.4), and the
 * collision class arrives from JS after load - so an immediate read catches a value in flight
 * (0.455 on its way from 0.42 to 0.62 tells you nothing about either). Two identical reads
 * 250 ms apart mean the transition is over.
 */
const settlePlate = async (timeoutMs = 4000) => {
  const read = () => evaluate(`return getComputedStyle(document.querySelector('.hello')).backgroundColor;`);
  const deadline = Date.now() + timeoutMs;
  let previous = await read();
  while (Date.now() < deadline) {
    await sleep(250);
    const current = await read();
    if (current === previous) return current;
    previous = current;
  }
  return previous;
};

/** Reads what the plate is actually painted with, right now, in this browser. */
const readPlate = () => evaluate(`
  const hello = document.querySelector('.hello');
  const style = getComputedStyle(hello);
  return {
    background: style.backgroundColor,
    effective: style.getPropertyValue('--plate-alpha-eff').trim(),
    base: style.getPropertyValue('--plate-alpha').trim(),
    conflict: hello.classList.contains('hello--conflict'),
    matchesContrast: matchMedia('(prefers-contrast: more)').matches,
    matchesReducedMotion: matchMedia('(prefers-reduced-motion: reduce)').matches
  };
`);

await setViewport(1280, 800);
phase = 'contrast';

/* The worst case of the design: midday, the Sun's corona directly behind the headline. */
await setMedia([]);
await navigate(`${BASE}/?t=2026-06-21T12:30`);
ok('the collision case is reachable (?t=2026-06-21T12:30)',
  await waitFor(`document.querySelector('.hello').classList.contains('hello--conflict')`),
  'spec 8.4 — .hello--conflict is what makes the plate 0.62');

await settlePlate();
const plateBefore = await readPlate();
const alphaBefore = parseRgba(plateBefore.background)?.alpha ?? NaN;
ok('no preference: matchMedia says the branch is OFF', plateBefore.matchesContrast === false,
  `matchMedia=${plateBefore.matchesContrast}`);
near('no preference: the collision plate is the spec 8.4 value', alphaBefore, 0.62, 0.005,
  `background=${plateBefore.background}`);

/* Now ask for high contrast the way the operating system would. */
await setMedia([{ name: 'prefers-contrast', value: 'more' }]);
await settlePlate();
const plateAfter = await readPlate();
const alphaAfter = parseRgba(plateAfter.background)?.alpha ?? NaN;

ok('prefers-contrast: more really is on (matchMedia)', plateAfter.matchesContrast === true,
  `matchMedia('(prefers-contrast: more)').matches=${plateAfter.matchesContrast}`);
ok('the plate gets DARKER, not lighter (this is the bug)', alphaAfter > alphaBefore,
  `${alphaBefore} -> ${alphaAfter} (before the fix: 0.62 -> 0.42)`);
near('the collision plate reaches the spec 10.3 value', alphaAfter, 0.80, 0.005,
  `background=${plateAfter.background} --plate-alpha-eff=${plateAfter.effective}`);
ok('getComputedStyle resolves --plate-alpha-eff to a number', Math.abs(parseFloat(plateAfter.effective) - 0.80) < 0.005,
  `--plate-alpha-eff=${plateAfter.effective} (registered @property, so it is resolved, not a token string)`);

const contrastAfter = contrastOn(alphaAfter, CORONA);
const contrastBefore = contrastOn(alphaBefore, CORONA);
ok('white on that plate clears WCAG AA over the corona', contrastAfter >= 4.5,
  `${contrastAfter.toFixed(2)} : 1 (AA needs 4.5; without the preference ${contrastBefore.toFixed(2)} : 1)`);
ok('the high-contrast user is no longer worse off than everyone else', contrastAfter >= contrastBefore,
  `${contrastBefore.toFixed(2)} : 1 -> ${contrastAfter.toFixed(2)} : 1`);

/* And the case with no collision at all: the surcharge must apply there too. */
await setMedia([]);
await navigate(`${BASE}/?t=2026-08-17T18:45`);
await settlePlate();
const dayBefore = parseRgba((await readPlate()).background)?.alpha ?? NaN;
await setMedia([{ name: 'prefers-contrast', value: 'more' }]);
await settlePlate();
const dayAfter = await readPlate();
const dayAlpha = parseRgba(dayAfter.background)?.alpha ?? NaN;
ok('without a collision the preference still applies (+.18)', Math.abs(dayAlpha - (dayBefore + 0.18)) < 0.005,
  `${dayBefore} -> ${dayAlpha} (conflict class: ${dayAfter.conflict})`);

/* The other preference branch, checked the same way instead of being taken on trust. */
section('2. prefers-reduced-motion: reduce — measured the same way (spec 11)');

await setMedia([]);
await navigate(`${BASE}/?t=2026-06-21T12:30`);
const motionOn = await evaluate(`
  return { matches: matchMedia('(prefers-reduced-motion: reduce)').matches,
           running: document.getAnimations().filter(a => a.playState === 'running').length,
           star: getComputedStyle(document.querySelector('.star')).animationName };
`);
ok('baseline: the page really is animated', motionOn.running > 0,
  `${motionOn.running} running animation(s), .star animation-name=${motionOn.star}`);

await setMedia([{ name: 'prefers-reduced-motion', value: 'reduce' }]);
await navigate(`${BASE}/?t=2026-06-21T12:30`);
const motionOff = await evaluate(`
  return { matches: matchMedia('(prefers-reduced-motion: reduce)').matches,
           running: document.getAnimations().filter(a => a.playState === 'running' && a.effect
                      && a.effect.getComputedTiming().duration > 1).length,
           corona: getComputedStyle(document.querySelector('.sun__corona') || document.body).animationName,
           helloVisible: getComputedStyle(document.querySelector('.hello')).opacity };
`);
ok('reduced motion really is on (matchMedia)', motionOff.matches === true, `matchMedia=${motionOff.matches}`);
ok('nothing keeps animating under reduced motion', motionOff.running === 0,
  `${motionOff.running} long-running animation(s) left; .sun__corona animation-name=${motionOff.corona}`);
ok('and the content does not disappear with the animation', parseFloat(motionOff.helloVisible) === 1,
  `.hello opacity=${motionOff.helloVisible}`);
await setMedia([]);

/* =============================================================================
   3. The refresh error chip - both failure paths, every breakpoint (BUG-0014)
   ============================================================================= */

section('3. The refresh error chip — geometry at every breakpoint (BUG-0014, spec 7.3/b)');

/** Kick the refresh loop the way returning to the tab does, instead of waiting 60 s. */
const triggerRefresh = () => evaluate(`document.dispatchEvent(new Event('visibilitychange')); return true;`);

const goOffline = async () => {
  /* The DEVICE loses the network: navigator.onLine flips and the browser fires `offline`. */
  await cdp('Network.emulateNetworkConditions', { offline: true, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
};
const goOnline = () => cdp('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
const blockApi = () => cdp('Network.setBlockedURLs', { urls: ['*sky.php*'] });
const unblockApi = () => cdp('Network.setBlockedURLs', { urls: [] });

/** Every box the chip is allowed to touch, measured in the live layout. */
const measureChip = () => evaluate(`
  const box = (el) => { const r = el.getBoundingClientRect();
    return { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height),
             top: r.top, bottom: r.bottom, left: r.left, right: r.right }; };
  const chip = document.querySelector('.errorchip');
  if (!chip) return null;
  const text = chip.querySelector('.errorchip__text');
  const button = chip.querySelector('.errorchip__button');
  return {
    chip: box(chip),
    text: text.textContent,
    lines: (() => { const range = document.createRange(); range.selectNodeContents(text);
      /* getClientRects() on the span itself returns ONE rect - it is a block-level flex item.
         Only a Range over its text reports the actual line boxes. */
      return range.getClientRects().length; })(),
    textHeight: Math.round(text.getBoundingClientRect().height),
    lineHeight: parseFloat(getComputedStyle(text).lineHeight) || 0,
    button: box(button),
    info: box(document.querySelector('.info')),
    labels: [...document.querySelectorAll('.compass__label')].map(l => ({ text: l.textContent, box: box(l) })),
    aboveHorizon: chip.classList.contains('errorchip--above-horizon'),
    opacity: getComputedStyle(chip).opacity,
    online: navigator.onLine,
    viewport: { w: innerWidth, h: innerHeight },
    scroll: { w: document.documentElement.scrollWidth, h: document.documentElement.scrollHeight }
  };
`);

const overlaps = (a, b) => a.left < b.right && b.left < a.right && a.top < b.bottom && b.top < a.bottom;

const viewports = [
  { name: '375x667 phone', w: 375, h: 667 },
  { name: '768x1024 tablet', w: 768, h: 1024 },
  { name: '1280x800 desktop', w: 1280, h: 800 },
  { name: '667x375 landscape phone', w: 667, h: 375 },
];

/* Two genuinely different failure paths. The chip must survive both: one of them travels
   through the browser's offline machinery (navigator.onLine === false, `offline` event),
   the other keeps the device online and only the endpoint fails - the closest thing to a
   5xx that can be produced from outside the server. */
const paths = [
  { name: 'server fails (device online)', start: blockApi, stop: unblockApi, expectOnline: true },
  { name: 'device offline', start: goOffline, stop: goOnline, expectOnline: false },
];

for (const path of paths) {
  for (const viewport of viewports) {
    phase = `chip/${path.name}/${viewport.name}`;
    await setViewport(viewport.w, viewport.h);
    await unblockApi();
    await goOnline();
    await navigate(`${BASE}/?t=2026-06-21T12:30`);
    await path.start();
    await triggerRefresh();

    const appeared = await waitFor(`document.querySelector('.errorchip')`);
    if (!ok(`${path.name} @ ${viewport.name}: the chip appears`, appeared, 'spec 7.3/b')) {
      await path.stop();
      continue;
    }
    /* The chip slides in over 240 ms and app.js verifies its placement two frames later; what
       the visitor looks at is the settled state, so that is what gets measured. */
    await sleep(600);

    const m = await measureChip();
    ok(`${path.name} @ ${viewport.name}: navigator.onLine is as intended`,
      m.online === path.expectOnline, `navigator.onLine=${m.online}`);
    ok(`${path.name} @ ${viewport.name}: the message is at most 2 lines`,
      m.lines <= 2, `${m.lines} line box(es) (text ${m.textHeight}px / line-height ${m.lineHeight}px), chip ${m.chip.w}x${m.chip.h}px at (${m.chip.x},${m.chip.y}) — before the fix: 5 lines in a 205px box`);

    const covered = m.labels.filter((l) => overlaps(m.chip, l.box)).map((l) => l.text);
    ok(`${path.name} @ ${viewport.name}: no compass label is covered`,
      covered.length === 0,
      covered.length ? `covered: ${covered.join(' ')}` : `all of ${m.labels.map((l) => l.text).join(' ')} clear (chip above the horizon: ${m.aboveHorizon})`);
    ok(`${path.name} @ ${viewport.name}: the chip does not overlap the .info strip`,
      !overlaps(m.chip, m.info),
      `chip bottom=${m.chip.bottom.toFixed(1)} info top=${m.info.top.toFixed(1)}`);
    ok(`${path.name} @ ${viewport.name}: the chip stays inside the viewport`,
      m.chip.left >= 0 && m.chip.right <= viewport.w && m.chip.top >= 0 && m.chip.bottom <= viewport.h,
      `chip ${JSON.stringify({ l: m.chip.left, r: m.chip.right, t: Math.round(m.chip.top), b: Math.round(m.chip.bottom) })}`);
    ok(`${path.name} @ ${viewport.name}: the retry button keeps its 44px target`,
      m.button.w >= 44 && m.button.h >= 44, `${m.button.w}x${m.button.h}px`);
    ok(`${path.name} @ ${viewport.name}: still nothing to scroll`,
      m.scroll.w === viewport.w && m.scroll.h === viewport.h,
      `scrollWidth/Height=${m.scroll.w}/${m.scroll.h}`);

    await path.stop();
  }
}

/* --------------------------- the longer message: three failures in a row (spec 7.3/b) */

section('3b. After the third failure the message doubles in length — and must still cover nothing');

for (const viewport of [viewports[0], viewports[3]]) {
  phase = `long/${viewport.name}`;
  await setViewport(viewport.w, viewport.h);
  await blockApi();
  await navigate(`${BASE}/?t=2026-06-21T12:30`);
  /* Three consecutive failures switch the text to "Az égbolt 3 perce nem frissült…", which is
     twice as long - the case most likely to wrap into the compass labels. */
  for (let i = 0; i < 3; i++) {
    await triggerRefresh();
    await sleep(700);
  }
  await sleep(700);

  const m = await measureChip();
  ok(`${viewport.name}: the third-failure text is the longer one`,
    /3 perce/.test(m.text), `"${m.text}"`);
  ok(`${viewport.name}: the longer message is still at most 2 lines`, m.lines <= 2,
    `${m.lines} line box(es), chip ${m.chip.w}x${m.chip.h}px`);
  const coveredLong = m.labels.filter((l) => overlaps(m.chip, l.box)).map((l) => l.text);
  ok(`${viewport.name}: the longer message covers no compass label`, coveredLong.length === 0,
    coveredLong.length ? `covered: ${coveredLong.join(' ')}` : `clear (chip above the horizon: ${m.aboveHorizon})`);
  ok(`${viewport.name}: the longer message does not overlap the .info strip`, !overlaps(m.chip, m.info),
    `chip bottom=${m.chip.bottom.toFixed(1)} info top=${m.info.top.toFixed(1)}`);

  await unblockApi();
}

/* ------------------------------------------- the 8 s fade, once per failure path (S3-1) */

section('4. The chip fades after 8 s, the button stays (spec 7.3/b, S3-1)');

for (const path of paths) {
  phase = `fade/${path.name}`;
  await setViewport(375, 667);
  await unblockApi();
  await goOnline();
  await navigate(`${BASE}/?t=2026-06-21T12:30`);
  await path.start();
  await triggerRefresh();
  await waitFor(`document.querySelector('.errorchip')`);
  await sleep(500); /* the chip-in animation is 240 ms; reading during it proves nothing */

  const early = await evaluate(`return getComputedStyle(document.querySelector('.errorchip')).opacity;`);
  await sleep(9200);
  const late = await evaluate(`
    const chip = document.querySelector('.errorchip');
    const button = chip.querySelector('.errorchip__button');
    return { opacity: getComputedStyle(chip).opacity, faded: chip.classList.contains('errorchip--faded'),
             buttonDisabled: button.disabled, buttonVisible: getComputedStyle(button).visibility };
  `);

  ok(`${path.name}: the chip is opaque when it arrives`, parseFloat(early) > 0.9, `opacity=${early}`);
  ok(`${path.name}: the chip has faded ~9 s later`, parseFloat(late.opacity) < 0.9,
    `opacity=${late.opacity} (class errorchip--faded=${late.faded}) — before the fix it stayed 1 for 8+ minutes`);
  ok(`${path.name}: the button is still there and still usable`,
    late.buttonDisabled === false && late.buttonVisible === 'visible',
    `disabled=${late.buttonDisabled} visibility=${late.buttonVisible}`);

  await path.stop();
}

/* --------------------------------------------------- keyboard reachability (spec 14.3) */

section('5. Keyboard: Tab reaches the retry button, Enter fires it (spec 9.1, 14.3)');

phase = 'keyboard';
await setViewport(375, 667);
await blockApi();
await navigate(`${BASE}/?t=2026-06-21T12:30`);
await triggerRefresh();
await waitFor(`document.querySelector('.errorchip')`);

const key = async (keyName, code, keyCode, text) => {
  /* `rawKeyDown` is enough to move focus, but a default action (Enter activating a button)
     only happens for a keyDown that carries its text. */
  await cdp('Input.dispatchKeyEvent', {
    type: text ? 'keyDown' : 'rawKeyDown',
    key: keyName, code, windowsVirtualKeyCode: keyCode, nativeVirtualKeyCode: keyCode,
    ...(text ? { text, unmodifiedText: text } : {}),
  });
  await cdp('Input.dispatchKeyEvent', { type: 'keyUp', key: keyName, code, windowsVirtualKeyCode: keyCode, nativeVirtualKeyCode: keyCode });
};

await key('Tab', 'Tab', 9);
const focused = await evaluate(`
  const active = document.activeElement;
  const style = active ? getComputedStyle(active) : null;
  return { className: active ? active.className : null, tag: active ? active.tagName : null,
           outlineColor: style ? style.outlineColor : null, outlineWidth: style ? style.outlineWidth : null };
`);
ok('Tab lands on the retry button', focused.className === 'errorchip__button', `activeElement=${focused.tag}.${focused.className}`);
ok('the focus ring is the spec 9.1 colour', /255,\s*211,\s*122/.test(focused.outlineColor || ''),
  `outline=${focused.outlineWidth} ${focused.outlineColor} (#FFD37A)`);

/* Enter must reach the same handler as a click: the label switches to "Frissítés…". */
await evaluate(`window.__label = document.querySelector('.errorchip__button').textContent; return true;`);
const requestsBeforeEnter = apiRequests;
await key('Enter', 'Enter', 13, '\r');
await sleep(800);
const afterEnter = await evaluate(`
  const button = document.querySelector('.errorchip__button');
  return { before: window.__label, now: button ? button.textContent : '(chip gone)',
           busy: button ? button.getAttribute('aria-busy') : null };
`);
ok('Enter triggers the retry (a new request goes out)', apiRequests > requestsBeforeEnter,
  `${requestsBeforeEnter} -> ${apiRequests} request(s) to the endpoint; button label "${afterEnter.before}" -> "${afterEnter.now}" (aria-busy=${afterEnter.busy})`);

await unblockApi();

/* ------------------------------------------------------------------- the console */

section('6. Console');

const appErrors = consoleErrors.filter((e) => !/^chip\/|^fade\/|^keyboard$/.test(e.phase));
const networkOnly = consoleErrors.filter((e) => /net::ERR_|Failed to load resource|favicon/i.test(e.text));
ok('no console error outside the deliberately broken-network phases',
  appErrors.length === 0,
  appErrors.length ? JSON.stringify(appErrors.slice(0, 4)) : '0 errors, 0 warnings');
console.log(`  note  ${String(networkOnly.length).padEnd(3)} network-level entries were caused by this test itself ` +
  `(blocked endpoint / offline device / missing favicon), which is expected`);

await finish();
