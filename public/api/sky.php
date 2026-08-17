<?php

declare(strict_types=1);

/**
 * GET /api/sky.php[?t=<ISO8601>]
 *
 * Returns the current (or requested) position of the Sun and the Moon over Veresegyhaz,
 * plus the sky phase and the rise/set times, as JSON. The contract this file implements
 * is recorded in Resources/tasks/TASK-0003-csillagaszati-mag.md.
 *
 * Errors: 400 on an invalid ?t=, 405 on a non-GET method, 500 on anything unexpected.
 * Details of a 500 go to the server error log only - never into the response body.
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use Sky\Location;
use Sky\RequestTime;
use Sky\Sky;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/**
 * Sends the response and stops.
 *
 * The encoding is guarded (BUG-0009, S3-3): before, a json_encode() failure would have sent a
 * status line with a ZERO-BYTE body, so a client doing JSON.parse() gets a parse error instead
 * of an error message. Whatever happens, something valid JSON goes out.
 *
 * @param array<string, mixed> $payload
 */
function respond(int $status, array $payload): never
{
    try {
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    } catch (JsonException $e) {
        error_log('[sky.php] response encoding failed: ' . $e->getMessage());

        $status = 500;
        $body = '{"error":"Belső hiba a számítás közben."}';
    }

    http_response_code($status);
    echo $body;
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    respond(405, ['error' => 'Csak GET kérés támogatott.']);
}

$location = Location::veresegyhaz();
$timezone = $location->timezone();

try {
    $raw = $_GET['t'] ?? null;

    if ($raw !== null && !is_string($raw)) {
        respond(400, ['error' => 'A "t" paraméter formátuma érvénytelen.']);
    }

    $when = $raw === null || $raw === ''
        ? new DateTimeImmutable('now', $timezone)
        : RequestTime::parse($raw, $timezone);
} catch (InvalidArgumentException $e) {
    // Client error: the message is written for the client on purpose and carries no internals.
    respond(400, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    // BUG-0009: everything reachable from this block is driven by ?t=, so anything that gets
    // here is a rejected input, not a broken server - and it must not leave the client with a
    // 500 and an empty body. DateTimeZone is the concrete reason this catch exists: since PHP
    // 8.3 it throws DateInvalidTimeZoneException, which is NOT an InvalidArgumentException and
    // therefore slipped past the catch above. The offset is validated in RequestTime now, so
    // this is the belt to that braces; it still logs, because arriving here means something
    // was missed.
    error_log(sprintf(
        '[sky.php] unexpected failure while parsing ?t=: %s: %s at %s:%d',
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    respond(400, ['error' => 'A "t" paraméter formátuma érvénytelen.']);
}

try {
    respond(200, Sky::snapshot($when, $location));
} catch (Throwable $e) {
    error_log(sprintf(
        '[sky.php] unexpected failure: %s: %s at %s:%d',
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    respond(500, ['error' => 'Belső hiba a számítás közben.']);
}
