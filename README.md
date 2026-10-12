# Slop

slop.trongate.dev: paste a link to an article and get a three-sentence TL;DR
and whether it's slop (generic, padded text with little real content).

A stock Trongate v2 app whose home page is the `welcome` module:

- `Article_reader` fetches the page (public addresses only, `Page_reader`)
  and keeps the article body as prose sentences, counting code blocks and
  links.
- `Slop_index` embeds each sentence with potion-multilingual
  (`model/`, a static embedding model in plain PHP, from the recruiter's
  taxonomy) and indexes it against 40 slop and 40 substance reference
  sentences (`Slop_reference`) embedded with the same model. It counts
  filler, repeated points, figures and stock AI words, and picks the TL;DR
  as the three sentences nearest the article's centre.
- Laya (`Laya_client`) judges those counts in one English paragraph: yes/no
  "is this slop" and a 0-4 substance score. It is the Trongate.cloud project
  service `laya`, which every app in the project reaches at `LAYA_URL`.
  Without it the page still shows the reading, with no verdict.
- `Article_map` adds a way around the article, as sasin91.xyz's AI article
  has: a timeline (one sentence per part, cut where the topic turns),
  suggested questions about the terms it keeps using, and every sentence's
  vector. The page's Ask box (`js/ask.js`) ranks sentences against a
  question by meaning and by rare words, embedding the question with
  `welcome/embed`, the same model.

Each check is saved to the `evaluations` table (`Welcome_model`,
`sql/evaluations.sql`): URL, title, TL;DR, Laya's verdict and scores, and the
counts and per-sentence reading as JSON. On trongate.cloud each deploy fills
in the app's database in `config/database.php`; the table is created
on the first save. With no database name nothing is stored and the page works
as before. Checks are limited to 10 per visitor address per 10 minutes.

```bash
make test   # or: php bin/run-tests.php -q modules/welcome/tests
make lint
```
