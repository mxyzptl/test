<?php

declare(strict_types=1);

/**
 * The page the visitor actually sees: "Hello World" in front of a full-screen, scroll-free
 * sky that shows where the Sun and the Moon really are over Veresegyhaz right now.
 *
 * The whole graphic is rendered here, server-side, as inline SVG. public/app.js only
 * refreshes it once a minute - with JavaScript switched off the page is still correct,
 * it simply stops updating.
 *
 * Screen spec: Resources/design/egbolt-spec.md . Tokens: Resources/design/tokens.md .
 */

require_once __DIR__ . '/../src/bootstrap.php';

use Sky\Location;
use Sky\Sky;
use Sky\SkyRenderer;

$location = Location::veresegyhaz();

try {
    $renderer = SkyRenderer::fromSnapshot(
        Sky::snapshot(new DateTimeImmutable('now', $location->timezone()), $location)
    );
} catch (Throwable $e) {
    // Design spec 7.3/a: a calculation failure must never cost us the "Hello World".
    // Details go to the server log only.
    error_log(sprintf(
        '[index.php] sky snapshot failed: %s: %s at %s:%d',
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    http_response_code(200);
    $renderer = SkyRenderer::errorState();
}

/**
 * The endpoint app.js polls. Configurable so that the page never carries a baked-in URL;
 * the default is the sibling endpoint of this very deployment.
 */
$apiUrl = getenv('SKY_API_URL');
if (!is_string($apiUrl) || $apiUrl === '') {
    $apiUrl = (string) ($_SERVER['SKY_API_URL'] ?? '');
}
if ($apiUrl === '') {
    $apiUrl = 'api/sky.php';
}

$esc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

?><!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="dark">
<meta name="description" content="Hol jár most a Nap és a Hold Veresegyház felett? Valós idejű égbolt.">
<title>Hello World — az égbolt Veresegyház felett</title>
<link rel="icon" href="data:,">
<link rel="stylesheet" href="style.css">
<style>
/* The sky colour behind the page, so even the pre-paint flash is sky-coloured, not white. */
html { background: <?= $esc($renderer->bootstrapColor()) ?>; }
</style>
<script>document.documentElement.classList.add('js');</script>
</head>
<body>
<main class="stage"
      id="stage"
      data-phase="<?= $esc($renderer->phase()) ?>"
      data-state="<?= $esc($renderer->state()) ?>"
      data-api="<?= $esc($apiUrl) ?>"
      style="<?= $esc($renderer->styleAttribute()) ?>">

<?= $renderer->skyLayers() ?>
<?= $renderer->stars() ?>
<?= $renderer->glow() ?>
<?= $renderer->bodies() ?>
<?= $renderer->ridge() ?>
<?= $renderer->compass() ?>
<?= $renderer->subMarkers() ?>
<div class="vignette" aria-hidden="true"></div>

<div class="hello" id="hello">
  <h1>Hello World</h1>
</div>

<p class="sr-only" id="sky-desc" aria-live="polite" aria-atomic="true"><?= $esc($renderer->description()) ?></p>

<?= $renderer->info() ?>
<?= $renderer->edgeChips() ?>

</main>
<script src="app.js" defer></script>
</body>
</html>
