// Ask the article: ranks its sentences against a question, as Ask does on
// sasin91.xyz. Each sentence scores its cosine to the question plus half a
// word-match score, weighted by how rare each matching word is in the
// article, so a name or a term lands on the sentences that use it even when
// the static model's sense of meaning is vague.
//
// The sentences' vectors come with the check (Article_map::pack); the
// question is embedded by welcome/embed with the same model. If that fails,
// ranking is by the words alone and the page says so.

const LEXICAL_WEIGHT = 0.5;
const LIMIT = 5;
// Answers scoring under this share of the best one are dropped, so a
// precise question shows one or two sentences rather than a full list.
const KEEP = 0.65;

// Article_map::STOP_WORDS: words a question is not about.
const STOP_WORDS = new Set(("a about above after again all also am an and any are as at be because been before "
  + "being between both but by can could did do does doing done down during each even every explain few for from "
  + "get gets give gives got had has have having he her here hers him his how however i if in into is it its "
  + "itself just know like make makes many may me might more most much must my need needs new no nor not now of "
  + "off on once one only or other our ours out over own really said same say says see she should so some still "
  + "such than that the their theirs them then there these they thing things this those through to too two under "
  + "until up us use used using very want was way we well were what when where whether which while who whom why "
  + "will with within without would yet you your yours article author tell tells mean means work works against "
  + "across already always another around enough instead never often rather though whole first last better actually "
  + "something anything everything nothing").split(" "));

export const words = (text) => text.toLowerCase().match(/[\p{L}\p{N}]+/gu) ?? [];

const contentWords = (text) => [...new Set(words(text).filter((w) => !STOP_WORDS.has(w)))];

// "index" finds "indexes" and "indexed", not "indexing" in "reindexing".
const sameWord = (a, b) => a === b || (a.length >= 4 && b.startsWith(a) && b.length - a.length <= 2);

/** The check's answer, ready to rank: decoded vectors and word counts. */
export function prepare(answer) {
  const { dim, q8 } = answer.vectors;
  const bytes = Uint8Array.from(atob(q8), (c) => c.charCodeAt(0));
  const view = new DataView(bytes.buffer);
  const sentences = answer.sentences.map((s, i) => {
    const offset = i * (4 + dim);
    const scale = view.getFloat32(offset, true);
    let vector = null;
    if (scale !== 0) {
      vector = new Float32Array(dim);
      for (let d = 0; d < dim; d++) vector[d] = view.getInt8(offset + 4 + d) * scale;
    }
    return { index: i, text: s.text, vector, words: new Set(words(s.text)) };
  });
  return { sentences, n: sentences.length };
}

function rarity(prepared, word) {
  const df = prepared.sentences.filter((s) => [...s.words].some((w) => sameWord(word, w))).length;
  if (df === 0) return 0;
  return Math.max(0.1, Math.log((prepared.n + 1) / df) / Math.log(prepared.n + 1));
}

/** The best LIMIT sentences for `question`: [{index, text, score, matched}]. */
export function rank(prepared, question, vector) {
  const asked = contentWords(question).map((w) => ({ w, r: rarity(prepared, w) })).filter((q) => q.r > 0);
  const results = prepared.sentences.map((s) => {
    let raw = 0;
    const matched = [];
    for (const { w, r } of asked) {
      if ([...s.words].some((x) => sameWord(w, x))) {
        raw += r;
        matched.push(w);
      }
    }
    const lexical = 1 - Math.exp(-raw);
    let cosine = 0;
    if (vector && s.vector) for (let d = 0; d < vector.length; d++) cosine += vector[d] * s.vector[d];
    return { index: s.index, text: s.text, matched, score: (vector ? cosine : 0) + LEXICAL_WEIGHT * lexical };
  });
  results.sort((a, b) => b.score - a.score || a.index - b.index);
  const best = results[0]?.score ?? 0;
  if (best <= 0) return [];
  return results.slice(0, LIMIT).filter((r) => r.score >= best * KEEP);
}

const embedded = new Map();

/** The question's vector from welcome/embed, or null if it has none or the server can't say. */
export async function embed(question) {
  if (!embedded.has(question)) {
    embedded.set(question, (async () => {
      const response = await fetch("welcome/embed", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ text: question }),
      });
      const answer = await response.json();
      if (answer.error) throw new Error(answer.error);
      return answer.vector ? Float32Array.from(answer.vector) : null;
    })().catch((error) => {
      embedded.delete(question);
      throw error;
    }));
  }
  return embedded.get(question);
}

/** `text` as nodes, with the words in `matched` marked. */
export function highlighted(text, matched) {
  const nodes = [];
  let last = 0;
  for (const m of text.matchAll(/[\p{L}\p{N}]+/gu)) {
    const word = m[0].toLowerCase();
    if (!matched.some((w) => sameWord(w, word))) continue;
    if (m.index > last) nodes.push(document.createTextNode(text.slice(last, m.index)));
    const mark = document.createElement("mark");
    mark.textContent = m[0];
    nodes.push(mark);
    last = m.index + m[0].length;
  }
  if (last < text.length) nodes.push(document.createTextNode(text.slice(last)));
  return nodes;
}
