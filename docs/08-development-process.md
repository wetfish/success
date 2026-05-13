# Development process

How changes flow through this codebase. The patterns below aren't about *what* the code does — that lives in the other docs — but about *how to break work up* so it stays reviewable, safely revertable, and easy to land incrementally.

If you're picking up a multi-file change and wondering "how should I slice this," start here. If you're encountering a series of small commits that all look related and wondering "why didn't this land as one big PR," this is where the reasoning lives.

---

## Slicing changes by integrity, not by file count

When a change touches many files, the question is how to break it into reviewable chunks. The wrong answer is "however many files feel right." The right answer is to find the natural boundaries where the system stays internally consistent, and slice along those.

### Why this matters

Slicing on integrity boundaries means every commit point is a state the project can live with. The tests pass, the application boots, the feature works end-to-end as far as it's been built. If something needs to be reverted, the revert is clean. If review of slice B uncovers a problem, slice A is already merged and useful on its own; the work isn't trapped behind a half-built dependency.

The opposite pattern — slicing arbitrarily — produces "this commit is broken but the next commit fixes it" sequences. Each individual commit becomes unreviewable in isolation. Bisecting becomes useless because failures don't pinpoint which change introduced them. Reverting requires understanding the chain rather than the individual change.

### When the principle applies

Any change that touches more than two or three files in different concerns. Single-file changes don't need slicing. Mechanical fan-out across many files (e.g., "rename X to Y everywhere") is one logical change even if it touches twenty files — it's a single integrity boundary by nature.

The principle matters most when changes span layers: a schema change plus a model change plus a controller change plus a view change. Each layer has its own concerns and its own potential breakage points.

### How to apply it

Before writing any code:

1. **List every file that needs to change.** Be exhaustive. Include tests that would otherwise fail, docs that would otherwise be wrong, view templates that consume controller data, anything downstream that touches the change.

2. **Map the dependencies between files.** Which files reference which? If file X exports a constant and file Y reads it, removing the constant in X breaks Y. That's an integrity boundary — X and Y must change together. If file Z is a pure new addition that nothing else references yet, it has no integrity constraint and can land on its own.

3. **Group by "must change together."** Files that share a breakage relationship form a single chunk. Files that don't share any can be split.

4. **Order chunks so each leaves the system working.** Pure additions can come first — they're risk-free baselines. Wiring changes that depend on those additions come next. Cleanup or polish (docs, comments, tests for behaviors not yet broken) can come last or be folded in where natural.

5. **Sanity-check each chunk individually.** "If I stopped after just this chunk, would tests pass? Would the application boot? Would the feature-so-far work?" If the answer is no, the chunk boundary is wrong.

### Anti-patterns

**Slicing by file count alone.** "Let's split this 12-file change into two 6-file PRs." The split might happen to land on a clean boundary, but more often it splits arbitrarily and creates a broken state in between. File count is a heuristic for review size, not a constraint on what changes belong together.

**"This commit is broken, but the next one fixes it."** Common when slicing reactively. The slice was wrong; redraw the boundary.

**Tightly-coupled changes split across PRs to keep PR sizes down.** If file A removes a constant and file B reads from that constant, splitting them across PRs means whichever lands first leaves the codebase broken. If the combined PR is genuinely too large to review, the right answer is to find a *different* slicing — usually a pure-additions-first chunk followed by a wiring chunk — not to ship known breakage.

**Avoiding new files for the sake of fewer files.** Extracting a small helper class, a trait, or a value object is sometimes the clearest answer, and the file count cost is trivial. The judgment is about clarity, not file count. The opposite is also true: if a "shared" helper has one consumer, the duplication of inlining it in two places might read more honestly than the indirection of a separate file.

### Example: the enum extraction slice

A recent slice extracted `OrganizationType` and `OrganizationStatus` enums from hardcoded arrays scattered across the codebase. The full change set was 8 files. The slicing:

- **Slice A (2 files):** Define the enum classes. Pure additions. Nothing referenced them yet, so the codebase compiled and behaved identically before and after.
- **Slice B (5 files):** Wire the consumers — validation rules, controller, draft schema service, AI prompt provider, form view — to read from the enums. Drop the old const arrays. All five files had to land together because slice B's first file removed constants that the other four read from.
- **Slice C (1 file):** Document the convention in `07-conventions.md`. Doc-only, independent.

The integrity boundary forced slice B's five files to stay together. Slice A could have been folded into B, but separating it gave a low-risk merge step that established the new files as a baseline before any wiring changes touched them. Slice C had no integrity constraint at all and could have come at any point.

A tempting alternative would have been to split slice B in half to keep individual chunks smaller. That doesn't work here: every file in slice B both references the new enums and removes references to the old constants. There's no clean boundary inside the chunk where cutting it would leave the codebase in a working state. When the natural unit of work is five files, ship it as five files.

---

## What gets documented vs. left as tribal knowledge

Not every decision deserves to be written down. The threshold is roughly: *would a future contributor make a different choice than the established one without this guidance?*

Things that deserve docs:
- Decisions that have a real alternative and would plausibly be made differently (the enum-as-source-of-truth pattern over hardcoded arrays — a contributor unaware of the drift problem might reasonably default to const arrays).
- Decisions with non-obvious reasoning (why source-document tagging is AI-only — a contributor would reasonably assume it follows the same picker pattern as other taggable entities).
- Constraints that aren't enforceable in code (slicing principles can't be linted).

Things that don't:
- Style choices already enforced by linters or editor config (indentation, line length).
- Idiomatic Laravel patterns that anyone familiar with the framework would default to (using form requests, resource controllers, etc.).
- One-off choices that aren't likely to recur ("we named this field `is_personal_appearance` because it read clearer than alternatives").

When in doubt, lean toward documenting. A doc that turns out to be unnecessary is cheap; a missing doc that costs the next contributor an hour of rediscovery is expensive.

---

## Reviewing chunks

Each chunk lands as a series of file-level changes that get reviewed individually. Reviewers typically open files one at a time and build mental context as they go.

This shapes how chunks should be presented:

- **Order files within a chunk so the conceptual story flows.** If a chunk adds a new enum and updates four consumers, present the enum file first. Reviewers build mental context as they read; ordering matters.
- **Each file's diff should be self-explanatory in context.** A reviewer reading file 3 of 5 in a chunk shouldn't have to flip back to file 1 to understand what's happening. Inline comments in the code, where useful, explain the change locally.
- **Cross-file dependencies are explained in the chunk's summary**, not buried in individual file comments.

When something goes wrong with a chunk — a test fails, behavior breaks, the user spots an issue during review — the response is to fix it in place, not in the next chunk. A chunk that needed a follow-up fix is a chunk that wasn't ready; the fix should land before the next chunk starts so each commit point still satisfies the integrity boundary.