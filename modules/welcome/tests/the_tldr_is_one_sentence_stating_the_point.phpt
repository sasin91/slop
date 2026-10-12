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

// The point under a takeaway heading beats one stated as well mid-article,
// and the title repeated in a series' list of parts, a quoted example and
// a line of figures don't count.
$sentences = [
    'Most agent prompts today are long English essays with invented checklists.',
    'What people write: "Please never skip step six, and always update the progress file."',
    'Models drift from invented checklists because nothing like them was ever in their training data.',
    'The make run passed 98 of 120 trials, 81.7% overall, 93.3% on flash and 100% on pro.',
    'The Takeaway',
    'A Makefile the model already knows is worth more than a thousand lines of invented prompt rules.',
    'Part 2: Stop inventing prompt languages and use the Unix tools models know',
];
$reading = Slop_index::load()->assess(['title' => 'Stop inventing prompt languages and use the Unix tools models know',
    'sentences' => $sentences, 'words' => 110, 'code_blocks' => 0, 'links' => 0]);
echo $reading['tldr'], "\n";
?>
--EXPECT--
Agents should use the shell they already know from training.
Expect the output of every program to become the input to another program.
For most developer work, the CLI is the cheaper path.
Your custom MCP tools never appear in training data.
Models already know grep, sed and awk because millions of scripts use them, so lean on those tools first.
Agents should call the Unix tools they already know from training instead of new MCP schemas.
A Makefile the model already knows is worth more than a thousand lines of invented prompt rules.
