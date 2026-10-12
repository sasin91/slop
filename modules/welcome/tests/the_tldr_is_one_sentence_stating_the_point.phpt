--TEST--
the TL;DR is one short sentence stating the point, not the intro or an aside
--FILE--
<?php
require __DIR__ . '/setup.inc';
// Cut to the gist: lead-ins, list markers, brackets and dashed asides go,
// and a long sentence keeps its main clause.
foreach ([
    'So, the point is that agents should use the shell they already know from training.',
    '(ii) Expect the output of every program to become the input to another program.',
    'For most developer work — building features, running tests, managing git, automating your workflows — the CLI is the cheaper path.',
    'Your custom MCP tools never appear in training data — the model has no prior knowledge of how to call them at all.',
    'Here is the thing: models already know grep, sed and awk because millions of scripts use them, so lean on those tools first.',
] as $sentence) {
    echo Slop_index::gist($sentence), "\n";
}

$sentences = [
    'Last week I spent an evening wiring three MCP servers into my coding agent.',
    'Each server sends its full tool schema before the agent has done anything at all.',
    'For example, the Atlassian server alone takes about forty percent of the context window.',
    'Agents should call the Unix tools they already know from training instead of new MCP schemas.',
    'The shell commands cost a few hundred tokens where the schemas cost tens of thousands.',
    'What should you do with the servers you already have?',
    'Here is how I set up the shell tools on my own machine:',
    'Armin Ronacher, "Skills vs Dynamic MCP Loadouts," Mar 2026.',
];
$reading = Slop_index::load()->assess(['title' => 'Let agents use Unix tools instead of MCP', 'sentences' => $sentences,
    'words' => 120, 'code_blocks' => 0, 'links' => 1]);
echo $reading['tldr'], "\n";
?>
--EXPECT--
Agents should use the shell they already know from training.
Expect the output of every program to become the input to another program.
For most developer work, the CLI is the cheaper path.
Your custom MCP tools never appear in training data.
Models already know grep, sed and awk because millions of scripts use them, so lean on those tools first.
Agents should call the Unix tools they already know from training instead of new MCP schemas.
