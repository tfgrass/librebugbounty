<?php // Content-based cache key also picks up CSS fixes within the same release. ?>
<link rel="stylesheet" href="/css/studio.css?v=<?= substr(hash_file('sha256', __DIR__.'/../../public/css/studio.css'), 0, 16) ?>">
