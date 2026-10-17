--TEST--
The check is sent step by step: a line per step, then the result or the error; refused up front it is one JSON error
--FILE--
<?php
require __DIR__ . '/setup.inc';
require __DIR__ . '/../Check_progress.php';

var_dump(Check_progress::wanted(['HTTP_ACCEPT' => 'application/x-ndjson, application/json']));
var_dump(Check_progress::wanted(['HTTP_ACCEPT' => 'application/json']));

echo "-- streamed\n";
$progress = new Check_progress(true);
$progress->step('fetch');
$progress->step('read');
$progress->finish(['tldr' => 'Imports took 4 s.']);

echo "-- failed after the first step\n";
$progress = new Check_progress(true);
$progress->step('fetch');
$progress->fail(422, 'The page answered HTTP 403.');

echo "\n-- refused before any step\n";
$progress = new Check_progress(true);
$progress->fail(429, 'Try again in 2 minutes.');

echo "\n-- not streamed\n";
$progress = new Check_progress(false);
$progress->step('fetch');
$progress->finish(['tldr' => 'Imports took 4 s.']);

echo "\n-- unknown step\n";
try {
    $progress->step('nope');
} catch (LogicException $e) {
    echo $e->getMessage(), "\n";
}

echo "-- Slop_index::assess tells the TL;DR and map steps, in order\n";
$article = Article_reader::from_text("Faster imports\n\nThe importer now batches inserts in groups of 500 rows. Imports of 2 million rows took 40 minutes before the change. They now take 4 minutes on the same machine.");
$steps = [];
Slop_index::load()->assess($article, function (string $key) use (&$steps) { $steps[] = $key; });
echo implode(', ', $steps), "\n";

echo "-- every step key the server sends is on the page\n";
foreach (['fetch', 'read', 'score', 'tldr', 'map', 'laya', 'save'] as $key) {
    echo $key, ': ', isset(Check_progress::STEPS[$key]) ? 'yes' : 'no', "\n";
}
?>
--EXPECT--
bool(true)
bool(false)
-- streamed
{"step":"fetch"}
{"step":"read"}
{"result":{"tldr":"Imports took 4 s."}}
-- failed after the first step
{"step":"fetch"}
{"error":"The page answered HTTP 403.","status":422}

-- refused before any step
{"error":"Try again in 2 minutes."}
-- not streamed
{"tldr":"Imports took 4 s."}
-- unknown step
No check step 'nope'.
-- Slop_index::assess tells the TL;DR and map steps, in order
tldr, map
-- every step key the server sends is on the page
fetch: yes
read: yes
score: yes
tldr: yes
map: yes
laya: yes
save: yes
