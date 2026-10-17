// The home page: sends the link (or pasted text) to welcome/check
// and draws the answer, following the check step by step (progress.js).
// The reading and the verdict happen on the server
// (Welcome.php); this file only shows them, and lets the visitor ask the
// article questions (ask.js) and walk its timeline.

import { embed, highlighted, prepare, rank } from "./ask.js";
import { begin, enter, fail, finish, readSteps } from "./progress.js";

const $ = (id) => document.getElementById(id);

const percent = (p) => `${Math.round(p * 100)}%`;

function showError(text) {
  $("error").textContent = text;
  $("error").hidden = !text;
}

function el(tag, text, className = "") {
  const node = document.createElement(tag);
  node.textContent = text;
  if (className) node.className = className;
  return node;
}

function drawVerdict(answer) {
  const box = $("verdict");
  box.replaceChildren();
  if (answer.laya) {
    const slop = answer.laya.verdict === "slop";
    box.className = `verdict ${slop ? "is-slop" : "not-slop"}`;
    box.append(
      el("strong", slop ? "Slop" : "Not slop"),
      el("span", ` Laya: ${percent(answer.laya.slop)} likely slop, substance ${answer.laya.substance.toFixed(1)} of 4 (${answer.laya.substance_label}).`),
    );
  } else {
    box.className = "verdict unknown";
    box.append(el("strong", "No verdict"), el("span", ` ${answer.laya_error}`));
  }
}

function draw(answer) {
  drawVerdict(answer);
  const title = $("title");
  title.replaceChildren();
  if (answer.url) {
    const link = el("a", answer.title || answer.url);
    link.href = answer.url;
    link.rel = "noopener noreferrer";
    title.append(link);
  } else {
    title.textContent = answer.title || "Pasted text";
  }
  $("tldr").textContent = answer.tldr;
  drawAsk(answer);
  drawTimeline(answer);
  $("summary").textContent = answer.summary;
  $("legend").textContent = "(filler is marked, repeats are struck through)";
  $("sentences").replaceChildren(
    ...answer.sentences.map((s, i) => {
      const classes = [s.filler ? "filler" : "", s.repeats !== null ? "repeat" : ""].join(" ").trim();
      const item = el("li", s.text, classes);
      item.id = `sentence-${i}`;
      item.title = `Leans ${s.lean > 0 ? "to filler" : "to substance"} by ${Math.abs(s.lean).toFixed(2)}`
        + (s.repeats !== null ? `; repeats sentence ${s.repeats + 1}` : "");
      return item;
    }),
  );
  $("result").hidden = false;
}

// ---------- Ask and the timeline ----------

let asking = null; // the answer being asked about, prepared by ask.js

/** Opens "Every sentence" at sentence `i` and flashes it. */
function showSentence(i) {
  $("every-sentence").open = true;
  const item = $(`sentence-${i}`);
  if (!item) return;
  item.classList.remove("found");
  void item.offsetWidth; // restart the flash
  item.classList.add("found");
  item.scrollIntoView({ behavior: "smooth", block: "center" });
}

function sentenceButton(i, nodes) {
  const button = document.createElement("button");
  button.type = "button";
  button.className = "link-button";
  button.append(...nodes);
  button.addEventListener("click", () => showSentence(i));
  return button;
}

function drawTimeline(answer) {
  $("timeline-panel").hidden = answer.timeline.length === 0;
  $("timeline").replaceChildren(
    ...answer.timeline.map((step) => {
      const item = document.createElement("li");
      const where = step.from === step.to ? `sentence ${step.from + 1}` : `sentences ${step.from + 1}–${step.to + 1}`;
      item.append(sentenceButton(step.sentence, [document.createTextNode(step.text)]), el("div", where, "muted where"));
      return item;
    }),
  );
}

function drawAsk(answer) {
  asking = prepare(answer);
  $("ask").value = "";
  $("answers").replaceChildren();
  $("ask-note").hidden = true;
  $("questions").replaceChildren(
    ...answer.questions.map((question) => {
      const button = el("button", question);
      button.type = "button";
      button.addEventListener("click", () => {
        $("ask").value = question;
        ask(question);
      });
      return button;
    }),
  );
}

async function ask(question) {
  question = question.trim();
  if (!asking || !question) return;
  const prepared = asking;
  let vector = null;
  let note = "";
  try {
    vector = await embed(question);
  } catch {
    note = "Couldn't read the question's meaning, so these match its words only.";
  }
  if (prepared !== asking || $("ask").value.trim() !== question) return; // asked something else since
  const results = rank(prepared, question, vector);
  if (!results.length) note = "Nothing in the article matches that. Try other words.";
  $("ask-note").textContent = note;
  $("ask-note").hidden = !note;
  $("answers").replaceChildren(
    ...results.map((r, i) => {
      const item = document.createElement("li");
      item.className = "reveal";
      item.style.setProperty("--i", i);
      item.append(sentenceButton(r.index, highlighted(r.text, r.matched)), el("div", `sentence ${r.index + 1}`, "muted where"));
      return item;
    }),
  );
}

$("ask-form").addEventListener("submit", (event) => {
  event.preventDefault();
  ask($("ask").value);
});

async function check(event) {
  event.preventDefault();
  const url = $("article-url").value.trim();
  const text = $("article-text").value.trim();
  showError("");
  if (!url && !text) {
    showError("Paste a link to an article, or its text.");
    return;
  }
  const button = $("check");
  button.disabled = true;
  button.querySelector(".label").textContent = "Reading…";
  begin();
  try {
    const response = await fetch("welcome/check", {
      method: "POST",
      // ndjson: each step as the server gets to it (progress.js). A check
      // refused up front still comes back as one JSON error.
      headers: { "Content-Type": "application/json", Accept: "application/x-ndjson, application/json" },
      body: JSON.stringify(url ? { url } : { text }),
    });
    let answer;
    if ((response.headers.get("Content-Type") ?? "").includes("application/x-ndjson")) {
      answer = await readSteps(response, enter);
    } else {
      try {
        answer = await response.json();
      } catch {
        throw new Error(`The server answered HTTP ${response.status} without a result. Try again.`);
      }
      if (answer.error) throw new Error(answer.error);
    }
    draw(answer);
    finish();
  } catch (error) {
    fail();
    showError(error.message === "Failed to fetch" ? "Couldn't reach the server. Check your connection and try again." : error.message);
  } finally {
    button.disabled = false;
    button.querySelector(".label").textContent = "Check";
  }
}

$("check-form").addEventListener("submit", check);
