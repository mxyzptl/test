/* =============================================================================
   Hello World - the sky over Veresegyhaz
   Client-side refresh loop.

   This file is an ENHANCEMENT, never a requirement. The server already rendered a
   correct sky; all this does is keep it current without a page reload:

     - once a minute it fetches the sky endpoint and writes the new values into the
       CSS custom properties the stylesheet already reads,
     - it decides whether a body sits behind the headline, which is the one thing the
       server cannot know (design spec 8.4),
     - and it stays quiet when it fails: the previous picture stays on screen.

   It never interpolates a colour. Every hex comes from the API's `palette` block,
   which is computed once, on the server (design spec 5.3).

   Spec: Resources/design/egbolt-spec.md ch. 7, 8.4, 11, 12.
   Data: Resources/tasks/TASK-0003-csillagaszati-mag.md, "API-szerzodes" v3.
   ============================================================================= */

(function () {
  'use strict';

  var REFRESH_INTERVAL = 60000;
  var FETCH_TIMEOUT = 6000;
  var CHIP_FADE_AFTER = 8000;
  var RESIZE_DEBOUNCE = 150;
  /* Exponential back-off after a failure, per spec 7.3/b. */
  var BACKOFF = [60000, 120000, 240000, 300000];

  var stage = document.getElementById('stage');
  if (!stage) {
    return;
  }

  var apiUrl = stage.dataset.api || 'api/sky.php';

  var hello = document.getElementById('hello');
  var refreshDot = document.getElementById('refresh-dot');
  var description = document.getElementById('sky-desc');
  var infoNote = document.getElementById('info-note');
  var infoNoteMain = document.getElementById('info-note-main');
  var infoNoteSub = document.getElementById('info-note-sub');
  var times = document.getElementById('times');
  var infoStrip = document.querySelector('.info');
  var bodySun = document.getElementById('body-sun');
  var bodyMoon = document.getElementById('body-moon');
  var moonLitPath = document.getElementById('moonLitPath');
  var moonLitClipPath = document.getElementById('moonLitClipPath');
  var moonLitGroup = document.getElementById('moonLitGroup');

  var timer = null;
  var dotTimer = null;
  var chipTimer = null;
  var resizeTimer = null;
  var inFlight = null;
  var failures = 0;
  var errorChip = null;

  /* ------------------------------------------------------------------ helpers */

  function num(value, decimals) {
    var text = value.toFixed(decimals === undefined ? 4 : decimals);
    return text.indexOf('.') === -1 ? text : text.replace(/0+$/, '').replace(/\.$/, '');
  }

  function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
  }

  function norm360(value) {
    return ((value % 360) + 360) % 360;
  }

  function setHidden(element, hidden) {
    if (element) {
      element.hidden = !!hidden;
    }
  }

  function setText(element, text) {
    if (element && element.textContent !== text) {
      element.textContent = text;
    }
  }

  /* Same mapping as src/SkyRenderer.php::screen() - keep the two in step. */
  function screenOf(body) {
    var azimuth = norm360(body.azimuth_deg);
    var altitude = body.altitude_deg;
    var offscreen = null;

    if (azimuth < 90) {
      offscreen = 'left';
    } else if (azimuth > 270) {
      offscreen = 'right';
    }

    var depth = Math.max(0, -altitude);

    return {
      x: clamp((azimuth - 90) / 180 * 100, 0, 100),
      k: clamp(1 - altitude / 90, 0, 1),
      visible: !!body.visible && offscreen === null,
      offscreen: offscreen,
      depth: depth,
      sub: 0.30 * Math.min(depth / 18, 1),
      azimuth: azimuth
    };
  }

  /* Design spec 6.2.1. The bright limb faces +x before rotation. */
  function moonLitPathData(illumination) {
    var r = 40;
    var k = clamp(illumination, 0, 1);
    var a = Math.max(r * Math.abs(1 - 2 * k), r * 0.005);
    var sweep = k < 0.5 ? 0 : 1;

    return 'M 0 -40 A 40 40 0 0 1 0 40 A ' + num(a) + ' 40 0 0 ' + sweep + ' 0 -40 Z';
  }

  /* ------------------------------------------------------- screen-reader text */

  var MONTHS = ['január', 'február', 'március', 'április', 'május', 'június',
    'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];

  var DIRECTIONS = ['északi', 'észak-északkeleti', 'északkeleti', 'kelet-északkeleti',
    'keleti', 'kelet-délkeleti', 'délkeleti', 'dél-délkeleti',
    'déli', 'dél-délnyugati', 'délnyugati', 'nyugat-délnyugati',
    'nyugati', 'nyugat-északnyugati', 'északnyugati', 'észak-északnyugati'];

  var PHASES = {
    day: 'Nappali égbolt',
    golden: 'Arany órai égbolt',
    civil: 'Polgári szürkület',
    nautical: 'Nautikai szürkület',
    astronomical: 'Csillagászati szürkület',
    night: 'Éjszakai égbolt'
  };

  function bodySentence(name, body, moon) {
    var direction = DIRECTIONS[Math.round(norm360(body.azimuth_deg) / 22.5) % 16];

    if (!body.visible) {
      return name + ' ' + Math.round(-body.altitude_deg) + ' fokkal a horizont alatt van, '
        + direction + ' irányban';
    }

    var text = name + ' ' + Math.round(body.altitude_deg) + ' fokkal a horizont felett, '
      + direction + ' irányban';

    if (moon && typeof moon.illumination === 'number') {
      text += ', ' + Math.round(moon.illumination * 100) + ' százalékban megvilágítva, '
        + (moon.waxing ? 'növekvő fázisban' : 'fogyó fázisban');
    }

    return text;
  }

  function describe(data) {
    var moment = new Date(data.generated_at);
    var text = 'Az égbolt Veresegyház felett, ';

    if (!isNaN(moment.getTime())) {
      var parts = new Intl.DateTimeFormat('hu-HU', {
        timeZone: 'Europe/Budapest',
        year: 'numeric', month: 'numeric', day: 'numeric',
        hour: '2-digit', minute: '2-digit', hour12: false
      }).formatToParts(moment).reduce(function (acc, part) {
        acc[part.type] = part.value;
        return acc;
      }, {});

      text += parts.year + '. ' + MONTHS[parseInt(parts.month, 10) - 1] + ' '
        + parseInt(parts.day, 10) + '. ' + parts.hour + ':' + parts.minute + '-kor. ';
    }

    text += (PHASES[data.sky.phase] || 'Égbolt') + '. ';
    text += bodySentence('A Nap', data.sun, null) + '. ';
    text += bodySentence('A Hold', data.moon, data.moon) + '. ';

    if (typeof data.moon.illumination !== 'number') {
      text += 'A Hold fázisa most nem határozható meg pontosan. ';
    }

    text += 'Napkelte ' + timeText(data.times.sunrise) + ', napnyugta '
      + timeText(data.times.sunset) + '.';

    return text;
  }

  function timeText(value, missing) {
    return typeof value === 'string' && value !== '' ? value : (missing || '—');
  }

  /* ------------------------------------------------------------------- render */

  function applyPalette(palette, sunAltitude) {
    /* Cross-fade: write the new gradient into whichever layer is currently invisible,
       then swap. Two layers is what makes a colour change a fade and not a jump. */
    var active = stage.dataset.skyActive === 'b' ? 'b' : 'a';
    var next = active === 'a' ? 'b' : 'a';

    palette.stops.forEach(function (stop, index) {
      stage.style.setProperty('--sky' + next.toUpperCase() + '-' + index, stop);
      stage.style.setProperty('--sky-' + index, stop);
    });
    stage.dataset.skyActive = next;

    stage.style.setProperty('--glow-rgb', palette.glow_rgb);
    stage.style.setProperty('--glow-a', num(palette.glow_a));
    stage.style.setProperty('--ground-base', palette.ground_base);
    stage.style.setProperty('--dayness', num(clamp((sunAltitude + 6) / 12, 0, 1)));
    stage.style.setProperty('--dayness2', num(clamp((sunAltitude + 12) / 18, 0, 1)));
    stage.style.setProperty('--star-f', num(palette.star_f));
    stage.style.setProperty('--star-dim', num(palette.star_dim));
    stage.style.setProperty('--plate-alpha', num(palette.plate_alpha));
    stage.style.setProperty('--vignette-alpha', num(palette.vignette_alpha));
    stage.style.setProperty('--moon-opacity', num(palette.moon_opacity));
    stage.style.setProperty('--sun-core', palette.sun_core);
    stage.style.setProperty('--sun-edge', palette.sun_edge);
    stage.style.setProperty('--sun-corona-k', num(palette.sun_corona_k));
    stage.style.setProperty('--sun-corona-a', num(palette.sun_corona_a));
  }

  function applyBodies(data) {
    var sun = screenOf(data.sun);
    var moon = screenOf(data.moon);

    stage.style.setProperty('--sun-x', num(sun.x) + '%');
    stage.style.setProperty('--sun-k', num(sun.k));
    stage.style.setProperty('--sun-vis', sun.visible ? '1' : '0');
    stage.style.setProperty('--sun-sub', num(sun.sub));
    stage.style.setProperty('--moon-x', num(moon.x) + '%');
    stage.style.setProperty('--moon-k', num(moon.k));
    stage.style.setProperty('--moon-vis', moon.visible ? '1' : '0');
    stage.style.setProperty('--moon-sub', num(moon.sub));

    setHidden(bodySun, !sun.visible);
    setHidden(bodyMoon, !moon.visible);

    /* The Moon's phase: illuminated fraction gives the terminator, the bright-limb angle
       gives the tilt. Contract v3: the angle runs from the zenith towards INCREASING
       azimuth, i.e. clockwise on screen, so the SVG rotation is chi - 90.
       Sanity check for a tester: the bright edge always faces the drawn Sun. */
    var illumination = typeof data.moon.illumination === 'number'
      ? clamp(data.moon.illumination, 0, 1)
      : 0.5;

    var chi = typeof data.moon.bright_limb_angle_deg === 'number'
      ? norm360(data.moon.bright_limb_angle_deg)
      : fallbackChi(data, sun, moon);

    stage.style.setProperty('--moon-illum', num(illumination));
    stage.style.setProperty('--moon-chi', num(chi) + 'deg');

    var d = moonLitPathData(illumination);
    var rotation = 'rotate(' + num(chi - 90) + ')';

    if (moonLitPath) {
      moonLitPath.setAttribute('d', d);
    }
    if (moonLitClipPath) {
      moonLitClipPath.setAttribute('d', d);
      moonLitClipPath.setAttribute('transform', rotation);
    }
    if (moonLitGroup) {
      moonLitGroup.setAttribute('transform', rotation);
    }

    applyEdge('sun', 'Nap', sun);
    applyEdge('moon', 'Hold', moon);
    applySubMarker('sun', sun);
    applySubMarker('moon', moon);
  }

  /* Spec 6.2.2 fallback: the bright limb always points at the Sun, so if the server
     could not give us an angle we can read it off the two screen positions. */
  function fallbackChi(data, sun, moon) {
    if (sun.offscreen === null && moon.offscreen === null) {
      var box = stage.getBoundingClientRect();
      var horizon = (parseFloat(getComputedStyle(stage).getPropertyValue('--horizon-y')) || 78) / 100;

      /* Screen pixels, y growing downwards; the vector points from the Moon to the Sun. */
      var dx = (sun.x - moon.x) / 100 * box.width;
      var dy = (sun.k - moon.k) * box.height * horizon;

      if (dx !== 0 || dy !== 0) {
        /* Inverse of the unit vector (sin chi, -cos chi) of contract v3. */
        return norm360(Math.atan2(dx, -dy) * 180 / Math.PI);
      }
    }

    /* Last resort: a waxing Moon has the Sun towards the larger azimuth, i.e. screen right. */
    return data.moon.waxing ? 100 : 280;
  }

  function applyEdge(key, label, screen) {
    var chip = document.getElementById('edgechip-' + key);
    if (chip) {
      chip.hidden = screen.offscreen === null;
      chip.classList.toggle('edgechip--left', screen.offscreen === 'left');
      chip.classList.toggle('edgechip--right', screen.offscreen === 'right');
      if (screen.offscreen !== null) {
        setText(chip, label + ': É-i sáv · ' + Math.round(screen.azimuth) + '°');
      }
    }

    ['left', 'right'].forEach(function (side) {
      var band = document.getElementById('edgeband-' + side);
      if (!band) {
        return;
      }
      var wanted = document.querySelector('.edgechip--' + side + ':not([hidden])');
      band.hidden = !wanted;
    });
  }

  function applySubMarker(key, screen) {
    var show = !screen.visible && screen.offscreen === null;
    var marker = document.getElementById('submarker-' + key);
    var line = document.getElementById('subline-' + key);

    setHidden(marker, !show);
    setHidden(line, !show);

    if (marker) {
      marker.classList.toggle('submarker--deep', screen.depth > 12);
    }
  }

  function applyInfo(data, state) {
    setHidden(times, state !== 'ok');
    setHidden(infoNote, state === 'ok');

    if (state === 'ok') {
      setText(document.getElementById('time-sunrise'), timeText(data.times.sunrise));
      setText(document.getElementById('time-sunset'), timeText(data.times.sunset));
      setText(document.getElementById('time-moonrise'), timeText(data.times.moonrise, 'ma nincs'));
      setText(document.getElementById('time-moonset'), timeText(data.times.moonset, 'ma nincs'));
    } else if (state === 'empty') {
      if (infoNote) {
        infoNote.classList.remove('info__note--error');
      }
      setText(infoNoteMain, 'Most sem a Nap, sem a Hold nincs a horizont felett.');
      setText(infoNoteSub, 'Legközelebb: napkelte ' + timeText(data.times.sunrise)
        + ' · holdkelte ' + timeText(data.times.moonrise, 'ma nincs'));
    }
  }

  function apply(data) {
    var state = (data.sun.visible || data.moon.visible) ? 'ok' : 'empty';

    applyPalette(data.palette, data.sky.sun_altitude_deg);
    applyBodies(data);
    applyInfo(data, state);

    stage.dataset.phase = data.sky.phase;
    stage.dataset.state = state;

    setText(description, describe(data));

    scheduleCollisionCheck();
  }

  /* ------------------------------------------------ headline / body collision */

  /* The plate's opacity is the only thing standing between white text and the Sun's
     white corona. Without this the headline reads at about 1.5:1 at noon (spec 10.3). */
  function collides(element, helloRect, factor) {
    if (!element || element.hidden) {
      return false;
    }

    var rect = element.getBoundingClientRect();
    if (rect.width === 0) {
      return false;
    }

    var radius = (rect.width / 2) * factor;
    var dx = Math.abs((rect.left + rect.width / 2) - (helloRect.left + helloRect.width / 2));
    var dy = Math.abs((rect.top + rect.height / 2) - (helloRect.top + helloRect.height / 2));

    return dx < helloRect.width / 2 + radius && dy < helloRect.height / 2 + radius;
  }

  function checkCollision() {
    if (!hello) {
      return;
    }

    var helloRect = hello.getBoundingClientRect();

    /* The Sun's wrapper IS the corona box, so its own half-width is already the reach.
       The Moon's wrapper is the disc, whose halo extends to about 1.6x (spec 8.4). */
    var conflict = collides(bodySun, helloRect, 1) || collides(bodyMoon, helloRect, 1.6);

    hello.classList.toggle('hello--conflict', conflict);
  }

  function scheduleCollisionCheck() {
    requestAnimationFrame(function () {
      requestAnimationFrame(checkCollision);
    });
  }

  /* ------------------------------------------------------------- error states */

  /* How tall the .info strip actually is, in px, published to the stylesheet as --info-h.
     The error chip is positioned above it (spec 7.3/b: "az .info sav fole csuszik"), and the
     strip's height is not a constant: it is a 2x2 grid on a phone, one row on a tablet, and it
     grows again when the empty-state note replaces the times. The chip used to clear a
     hard-coded 44px, which is why it sat on top of the compass labels and the info text
     (BUG-0014). Measuring is cheap - it happens on failure and on a debounced resize, never in
     the refresh loop - and .info's own height does not depend on the chip, so there is no
     layout feedback loop here. */
  function measureInfoStrip() {
    if (!infoStrip) {
      return;
    }

    var height = Math.round(infoStrip.getBoundingClientRect().height);
    if (height > 0) {
      stage.style.setProperty('--info-h', height + 'px');
    }
  }

  function ensureErrorChip() {
    if (errorChip) {
      return errorChip;
    }

    errorChip = document.createElement('div');
    errorChip.className = 'errorchip';
    errorChip.setAttribute('role', 'status');

    var text = document.createElement('span');
    text.className = 'errorchip__text';

    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'errorchip__button';
    button.textContent = 'Újrapróbálom';
    button.addEventListener('click', function () {
      button.disabled = true;
      button.setAttribute('aria-busy', 'true');
      button.textContent = 'Frissítés…';
      refresh(true);
    });

    errorChip.appendChild(text);
    errorChip.appendChild(button);
    errorChip.__text = text;
    errorChip.__button = button;
    stage.appendChild(errorChip);

    return errorChip;
  }

  function showErrorChip() {
    /* Measure first: the chip's `bottom` is read on the very frame it is inserted. */
    measureInfoStrip();

    var chip = ensureErrorChip();

    chip.__text.textContent = failures >= 3
      ? 'Az égbolt 3 perce nem frissült. Az adatok elavultak lehetnek.'
      : 'Nem sikerült frissíteni az égboltot.';

    chip.__button.disabled = false;
    chip.__button.removeAttribute('aria-busy');
    chip.__button.textContent = 'Újrapróbálom';
    chip.classList.remove('errorchip--faded');

    window.clearTimeout(chipTimer);
    /* The message fades after 8 s, but the button stays usable (spec 7.3/b). */
    chipTimer = window.setTimeout(function () {
      chip.classList.add('errorchip--faded');
    }, CHIP_FADE_AFTER);
  }

  function hideErrorChip() {
    if (!errorChip) {
      return;
    }

    window.clearTimeout(chipTimer);
    errorChip.remove();
    errorChip = null;
  }

  /* --------------------------------------------------------------- the loop */

  function showDot() {
    /* A refresh faster than 400 ms should not flash a dot at anybody. */
    window.clearTimeout(dotTimer);
    dotTimer = window.setTimeout(function () {
      setHidden(refreshDot, false);
    }, 400);
  }

  function hideDot() {
    window.clearTimeout(dotTimer);
    setHidden(refreshDot, true);
  }

  function schedule(delay) {
    window.clearTimeout(timer);
    timer = window.setTimeout(function () {
      refresh(false);
    }, delay);
  }

  function resetRetryButton() {
    if (errorChip) {
      errorChip.__button.disabled = false;
      errorChip.__button.removeAttribute('aria-busy');
      errorChip.__button.textContent = 'Újrapróbálom';
    }
  }

  function refresh(manual) {
    if (inFlight) {
      /* A request is already on its way; do not stack them, but never leave the
         button stuck in its busy state either. */
      resetRetryButton();
      return;
    }

    var controller = new AbortController();
    var timeout = window.setTimeout(function () {
      controller.abort();
    }, FETCH_TIMEOUT);

    inFlight = controller;
    showDot();

    fetch(apiUrl, {
      signal: controller.signal,
      cache: 'no-store',
      headers: { Accept: 'application/json' }
    })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }
        return response.json();
      })
      .then(function (data) {
        if (!data || !data.sun || !data.moon || !data.sky || !data.palette || !data.times) {
          throw new Error('incomplete payload');
        }

        failures = 0;
        apply(data);
        hideErrorChip();
        schedule(REFRESH_INTERVAL);
      })
      .catch(function () {
        /* Deliberately silent: the picture on screen stays, and a failed refresh is not
           something the visitor - or the console - needs to hear about in detail. */
        failures += 1;
        showErrorChip();
        schedule(BACKOFF[Math.min(failures - 1, BACKOFF.length - 1)]);
      })
      .then(function () {
        window.clearTimeout(timeout);
        inFlight = null;
        hideDot();
      });

    if (manual) {
      window.clearTimeout(timer);
    }
  }

  /* --------------------------------------------------------------- lifecycle */

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      window.clearTimeout(timer);
      timer = null;
      return;
    }

    refresh(false);
  });

  function onViewportChange() {
    window.clearTimeout(resizeTimer);
    resizeTimer = window.setTimeout(function () {
      checkCollision();
      measureInfoStrip();
    }, RESIZE_DEBOUNCE);
  }

  window.addEventListener('resize', onViewportChange);
  window.addEventListener('orientationchange', onViewportChange);

  scheduleCollisionCheck();
  requestAnimationFrame(measureInfoStrip);
  schedule(REFRESH_INTERVAL);
})();
