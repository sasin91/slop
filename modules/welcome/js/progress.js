// The check's steps as they happen (the #progress timeline). The server
// sends a line per step as it enters it (Check_progress.php); `send` and
// `show` are the browser's own. A step is waiting, current, done (with how
// long it took), skipped (fetching, for pasted text) or failed.

const $ = (id) => document.getElementById(id);

const seconds = (ms) => (ms < 100 ? "<0.1 s" : `${(ms / 1000).toFixed(1)} s`);

let steps = [];
let current = null;
let enteredAt = 0;
let startedAt = 0;

function mark(step, state, label = "", time = "") {
  step.dataset.state = state;
  step.querySelector(".check-label").textContent = label;
  step.querySelector(".check-time").textContent = time;
}

/** Resets the timeline and enters `send`. */
export function begin() {
  steps = [...document.querySelectorAll(".check-step")];
  for (const step of steps) mark(step, "waiting");
  current = null;
  startedAt = performance.now();
  $("progress").open = true;
  $("progress-title").textContent = "Checking…";
  $("progress-time").textContent = "";
  enter("send");
}

/**
 * Enters step `key`: the current one is done, and any between them that
 * can be skipped were; the rest were done without a word from the server.
 */
export function enter(key) {
  const next = $(`step-${key}`);
  if (!next || next === current) return;
  const now = performance.now();
  if (current) mark(current, "done", "Done", seconds(now - enteredAt));
  for (const step of steps.slice(current ? steps.indexOf(current) + 1 : 0, steps.indexOf(next))) {
    if (step.dataset.skipped) mark(step, "skipped", step.dataset.skipped);
    else mark(step, "done", "Done");
  }
  current = next;
  enteredAt = now;
  mark(next, "current", "Working…");
}

/** The result is drawn: every step is done, and the timeline folds away. */
export function finish() {
  enter("show");
  const now = performance.now();
  mark(current, "done", "Done", seconds(now - enteredAt));
  current = null;
  $("progress-title").textContent = "How this was checked";
  $("progress-time").textContent = `(${seconds(now - startedAt)})`;
  $("progress").open = false;
}

/** The check stopped at the current step. */
export function fail() {
  if (current) mark(current, "failed", "Stopped here");
  current = null;
  $("progress-title").textContent = "The check stopped";
}

/**
 * Reads an application/x-ndjson check: calls `onStep` for each step line
 * and returns the result, or throws the error the server ended with.
 */
export async function readSteps(response, onStep) {
  const reader = response.body.getReader();
  const decoder = new TextDecoder();
  let buffer = "";
  let end = null;
  const take = (line) => {
    if (!line.trim()) return;
    let event;
    try {
      event = JSON.parse(line);
    } catch {
      return; // not ours, e.g. a PHP error printed after a timeout
    }
    if (event.step) onStep(event.step);
    else end = event;
  };
  for (;;) {
    const { value, done } = await reader.read();
    if (done) break;
    buffer += decoder.decode(value, { stream: true });
    const lines = buffer.split("\n");
    buffer = lines.pop();
    lines.forEach(take);
  }
  take(buffer + decoder.decode());
  if (!end) throw new Error("The server stopped before the result. Try again.");
  if (end.error) throw new Error(end.error);
  return end.result;
}
