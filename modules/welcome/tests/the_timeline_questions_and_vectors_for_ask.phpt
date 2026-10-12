--TEST--
an article's timeline steps through its topics in order, its questions are about the terms it keeps using, and its vectors survive packing
--FILE--
<?php
require __DIR__ . '/setup.inc';
$sentences = [
    // On the nightly import.
    'Every night we import 2.3 million rows from the supplier catalogue into MariaDB.',
    'The nightly import used to take three hours and often ran into the morning.',
    'Most of that time went on the lookup of each product by its SKU column.',
    'The SKU lookup scanned the whole products table for every single row.',
    'We added an index on the SKU column and the import dropped to 48 minutes.',
    // On the office dog.
    'Our office dog is called Biscuit and she is a nine year old beagle.',
    'Biscuit sleeps under the reception desk for most of the afternoon.',
    'Visitors usually bring Biscuit a treat, which the vet has asked them to stop.',
    'On Fridays Biscuit gets a long walk along the harbour with the whole team.',
    'The beagle has her own badge on the staff page of the website.',
];
$reading = Slop_index::load()->assess(['sentences' => $sentences, 'words' => 150, 'code_blocks' => 0, 'links' => 0]);

foreach ($reading['timeline'] as $step) {
    echo "{$step['from']}-{$step['to']}: ", ($step['sentence'] >= $step['from'] && $step['sentence'] <= $step['to'] ? 'inside' : 'outside'), "\n";
}
print_r($reading['questions']);

// The page decodes the same bytes (ask.js prepare()).
$model = Static_model::load(Slop_index::MODEL_DIR);
$bytes = base64_decode($reading['vectors']['q8']);
$dim = $reading['vectors']['dim'];
var_dump($dim === $model->dim, strlen($bytes) === count($sentences) * (4 + $dim));
$worst = 1.0;
foreach ($sentences as $i => $text) {
    $scale = unpack('g', $bytes, $i * (4 + $dim))[1];
    $row = array_map(fn($v) => $v * $scale, array_values(unpack("c$dim", $bytes, $i * (4 + $dim) + 4)));
    $worst = min($worst, Static_model::cosine(Static_model::unit($row), $model->encode($text)['vector']));
}
var_dump($worst > 0.999);

// Too short for more than one step: no timeline.
var_dump(Article_map::timeline(array_slice($reading['sentences'], 0, 6), []));
?>
--EXPECT--
0-4: inside
5-9: inside
Array
(
    [0] => What does it say about SKU column?
    [1] => What does it say about Biscuit?
    [2] => What does it say about import?
    [3] => What does it say about lookup?
)
bool(true)
bool(true)
bool(true)
array(0) {
}
