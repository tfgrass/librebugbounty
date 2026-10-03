<?php

declare(strict_types=1);

// Used only by the guarded, isolated DDEV acceptance harness. No application
// bootstrap, external resources, technical checks, or target URLs are involved.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!in_array($path, ['/plain', '/dialog'], true)) {
    http_response_code(404);
    exit('Local fixture not found.');
}
$dialog = $path === '/dialog';
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="en">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>N01 local <?= $dialog ? 'dialog' : 'plain' ?> fixture</title>
<style>
body { margin: 0; background: #edf3f7; color: #183248; font: 24px/1.6 system-ui, sans-serif; }
main { max-width: 960px; margin: 90px auto; padding: 42px; border: 3px solid #39739a; border-radius: 20px; background: white; }
h1 { margin: 0 0 24px; font-size: 42px; }
.marker { padding: 16px 24px; background: #d9eee2; border-left: 8px solid #2d7752; }
</style>
<main>
  <h1>N01 — <?= $dialog ? 'Ordinary browser dialog' : 'Plain local page' ?></h1>
  <p>This page belongs to the isolated DDEV acceptance project.</p>
  <p class="marker">Controlled local screenshot fixture. No external resources.</p>
  <p><?= $dialog ? 'An ordinary alert is opened after rendering.' : 'This page does not open a browser dialog.' ?></p>
</main>
<?php if ($dialog): ?>
<script>setTimeout(() => alert('N01 harmless local browser dialog'), 250);</script>
<?php endif; ?>
</html>
