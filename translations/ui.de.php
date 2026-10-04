<?php

/**
 * German phrases are the source keys of the UI; English is its default. Keeping the
 * source phrases as stable keys makes omissions visible instead of hiding them
 * behind generated identifiers.
 *
 * @var array<string, string> $english
 */
$english = require __DIR__.'/ui.en.php';

return array_combine(array_keys($english), array_keys($english));
