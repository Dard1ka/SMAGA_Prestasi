# docs/dev-notes/ — coordination notes

Short, factual hand-off notes between the people and AI agents working on STH in parallel.
They are the "what did you actually build" layer. They are **not** the decision log
(`docs/DECISIONS.md`) and not the contract (`docs/ARCHITECTURE.md`).

## Who writes here
- Wave agents: one file per agent, named after its ID: `W1-A.md`, `W1-B.md`, … `W2-C.md`.
- Humans (optional, same format): `user.md`, `S.md`, `I.md`, or `S-<topic>.md` for a larger piece.

## Protocol
1. **Before you start:** read `CLAUDE.md`, then every file in this folder.
2. **While working:** only touch files your area owns (see `CLAUDE.md` › Ownership and `docs/TEAM.md`).
   If you need a change in someone else's file, write it under "Requests" in *your own* note.
3. **When you finish (or stop):** write or overwrite your own note using the template below.
4. **Then** read all notes again. If another note conflicts with yours (a class name, route name,
   column, component prop), fix your side if the spec (`ARCHITECTURE.md`, `DECISIONS.md`) agrees
   with them; otherwise list the conflict under "Requests" and tell the lead.
5. Never edit another person's or agent's note. Never delete notes; the lead may move finished ones to
   `archive/` after their content is reflected in `CLAUDE.md` › Status.

## Rules for content
- Facts only: files, class names, signatures, route names, commands you ran and their result.
- Mark anything not verified as `(not run)` or `(planned)`.
- No secrets, no `.env` values, no real student/teacher data, and never the initial-password pattern
  written out with a real date. Fictional data from `docs/design/DESIGN-SPEC-v2.md` §5 only.
- A product or architecture change is a decision: propose it in "Requests"; the lead records it in
  `docs/DECISIONS.md` (new entry, never an edit of an old one).
- Keep it short: aim for under 80 lines.

## Template

```markdown
# <ID> — <area>  ·  status: in progress | done | blocked

## Files (owned by me)
- path/to/File.php — one-line purpose

## Contracts other areas can use
- `App\Domain\Points\PointCalculator::calculate(Level, ResultType, WeightCategory, ?WeightVersion): string`
- route `siswa.lomba.show` → GET /siswa/lomba/{competition}
- component `<x-sth.button variant="primary">`

## Commands verified
- `docker compose run --rm --no-deps app php artisan test --filter=Points` → 12 passed

## Assumptions / deviations from the spec
- …

## Requests for other owners
- @W1-C: …

## Follow-ups (not done)
- …
```
