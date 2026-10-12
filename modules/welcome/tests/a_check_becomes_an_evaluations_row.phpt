--TEST--
A check becomes an evaluations row: URL and its hash, TL;DR, Laya's scores, and the reading as JSON
--FILE--
<?php
require __DIR__ . '/../../../engine/Model.php';
require __DIR__ . '/../Welcome_model.php';
$result = [
    'url' => 'https://example.com/post',
    'title' => 'Faster imports',
    'summary' => 'Article: Faster imports.',
    'sentences' => [['text' => 'Imports took 4 s.', 'lean' => -0.1, 'filler' => false, 'repeats' => null, 'figure' => true]],
    'counts' => ['sentences' => 1, 'filler' => 0],
    'stock_words' => [],
    'tldr' => 'Imports took 4 s.',
    'laya' => ['slop' => 0.12, 'substance' => 3.1, 'substance_label' => 'solid', 'verdict' => 'not slop'],
];
print_r(Welcome_model::evaluation_row($result));
unset($result['url'], $result['laya']);
$row = Welcome_model::evaluation_row($result + ['laya_error' => 'Laya failed: HTTP 500']);
var_dump($row['url'], $row['url_hash'], $row['verdict'], $row['laya_error']);
?>
--EXPECT--
Array
(
    [url] => https://example.com/post
    [url_hash] => 061128ccde4ce4703519b9b2e51c036d85b137886dedcd2908fd11da004ab8c7
    [title] => Faster imports
    [tldr] => Imports took 4 s.
    [verdict] => not slop
    [slop] => 0.12
    [substance] => 3.1
    [substance_label] => solid
    [laya_error] => 
    [counts] => {"sentences":1,"filler":0}
    [stock_words] => {}
    [sentences] => [{"text":"Imports took 4 s.","lean":-0.1,"filler":false,"repeats":null,"figure":true}]
    [summary] => Article: Faster imports.
)
NULL
NULL
NULL
string(21) "Laya failed: HTTP 500"
