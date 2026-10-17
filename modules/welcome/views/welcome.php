<!DOCTYPE html>
<html lang="en">
<head>
    <base href="<?= $base_url ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TL;DR slop</title>
    <link rel="stylesheet" href="welcome_module/css/welcome.css">
</head>
<body>
<main>
    <div class="hero">
        <h1>TL;DR slop</h1>
        <p class="muted">Paste a link to an article. You get the gist in three sentences and whether it's slop: generic, padded text with little real content.</p>
    </div>

    <form class="panel" id="check-form">
        <div class="url-row">
            <input type="url" id="article-url" placeholder="https://dev.to/…" autocomplete="off">
            <button type="submit" id="check"><span class="label">Check</span></button>
        </div>
        <details id="paste">
            <summary>Or paste the text</summary>
            <textarea id="article-text" rows="8" placeholder="Paste the article here when the site won't let us read it"></textarea>
        </details>
        <p class="error" id="error" role="alert" hidden></p>
    </form>

    <details class="panel" id="progress" open>
        <summary><span id="progress-title">What happens when you press Check</span> <span class="muted" id="progress-time"></span></summary>
        <ol class="check-steps" aria-live="polite">
            <?php $number = 0; foreach ($steps as $key => $step): $number++; ?>
                <li class="check-step" id="step-<?= $key ?>" data-state="waiting"<?= isset($step['skipped']) ? ' data-skipped="' . htmlspecialchars($step['skipped']) . '"' : '' ?>>
                    <span class="check-dot" aria-hidden="true"><?= $number ?></span>
                    <div class="check-head">
                        <strong><?= htmlspecialchars($step['title']) ?></strong>
                        <span class="check-label"></span>
                        <span class="check-time"></span>
                    </div>
                    <p class="check-tldr"><?= htmlspecialchars($step['tldr']) ?></p>
                    <details class="check-about">
                        <summary>What happens</summary>
                        <?php foreach ($step['about'] as $line): ?>
                            <p><?= htmlspecialchars($line) ?></p>
                        <?php endforeach; ?>
                    </details>
                </li>
            <?php endforeach; ?>
        </ol>
    </details>

    <section class="panel" id="result" hidden aria-live="polite">
        <div class="verdict" id="verdict"></div>
        <h2 id="title"></h2>
        <h3>TL;DR</h3>
        <p id="tldr"></p>
        <div id="ask-panel">
            <h3><label for="ask">Ask the article</label></h3>
            <form class="url-row" id="ask-form" role="search">
                <input type="search" id="ask" placeholder="What does it say about…" autocomplete="off" maxlength="300">
                <button type="submit">Ask</button>
            </form>
            <div class="questions" id="questions"></div>
            <p class="muted" id="ask-note" hidden></p>
            <ol class="answers" id="answers" aria-live="polite"></ol>
        </div>
        <div id="timeline-panel">
            <h3>Timeline</h3>
            <p class="muted">The article as a progression of points, one sentence each.</p>
            <ol class="timeline" id="timeline"></ol>
        </div>
        <h3>What Laya was told</h3>
        <p class="muted" id="summary"></p>
        <details id="every-sentence">
            <summary>Every sentence <span class="muted" id="legend"></span></summary>
            <ol class="sentences" id="sentences"></ol>
        </details>
    </section>
</main>
<script type="module" src="welcome_module/js/app.js"></script>
</body>
</html>
