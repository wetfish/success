# Mission

Build a career lifecycle tool that treats your work history as one continuous, structured record — from the first time you describe a past job through landing the next role, doing the work, getting paid, and maintaining the relationships that carry your career forward. Make it useful enough that the author wants to dogfood it, and cheap enough to host that being unemployed isn't a barrier to using it.

This document captures the principles that follow from that mission. It's intentionally placed before the technical documentation because these are the questions worth answering before writing any code. If you're landing here as a new contributor, read this first. If you're future-self trying to remember why a decision was made, this is where the reasoning lives.

---

## Design philosophy

The decisions below should drive every feature, schema choice, and UI tradeoff. When in doubt, return to these.

### The data model is the moat

Anyone can build an AI resume generator in a weekend. The thing that's hard to replicate, and that gets more valuable the longer a user uses the app, is a deeply structured record of their career: the projects they shipped, the technologies they touched, the metrics they moved, the constraints they worked under, the people they worked with. Resume generation, interview prep, and everything downstream are *applications* of this data. The data itself is the product.

This means we invest disproportionately in the schema and the data-entry experience early on, even if it means the visible-features list looks short for a while.

### Rigid schema, freeform input

Users should never have to think in normalized tables. They should be able to paste interview prep notes, performance review self-assessments, or stream-of-consciousness brain dumps, and the app should extract structured records from that text. The schema is rigid (so AI features can rely on it), but the front door is soft (so data entry doesn't feel like filing taxes).

The implication: an extraction pipeline is a first-class feature, not a one-time onboarding step. Anytime the user accumulates new context (interview notes, review docs, journal entries), they should be able to feed it in and get draft records to confirm.

### Capture the shape of stories, not just descriptions

Senior-level work is rarely a list of features. It's a sequence of stories: *here was the problem, here were the constraints, here's what I did, here's why I did it that way, here's what happened.* Resume tools that flatten work into bullet points lose the story shape, and users have to reconstruct it from memory in interviews.

The schema captures problem, constraints, approach, outcome, and rationale as distinct fields on projects (and major accomplishments). Some users will fill all five; some will fill one. The point is that the schema *invites* the story, rather than reducing everything to a flat description.

### Single source of truth, derived views

A resume bullet, a portfolio summary, an interview talking point, and a cover letter paragraph might all describe the same accomplishment in different voices. The schema stores the underlying accomplishment once. Every output is derived. If the output is wrong, the user fixes the source, not the output.

This means: no "summary" fields where the user describes work they've already described elsewhere. No duplicated data between the catalog and the generated resume. Tailored resumes are immutable artifacts (so you can audit what was actually sent), but they're built from the canonical catalog every time.

### Career themes are first-class user-authored data

Beyond the structured catalog, the user has narrative threads in their head — *"I'm someone who works on distributed systems with a privacy bent,"* *"My career has alternated between deep technical work and team leadership."* These are creative acts of self-framing that don't emerge automatically from project data. The schema gives them a home (a `career_themes` or similar table), and the AI uses them as the spine of tailored resumes: pick the relevant theme(s) for a given job, then pull the best evidence under each.

### Self-documenting code

No single-letter variables, no aggressive abbreviation. Field names should read as English: `is_personal_appearance`, not `featured`; `contribution_level`, not `level`; `funding_last_round_amount`, not `last_amt`. Code is read more than it's written. See `docs/05-ai-development-notes.md` for related conventions on AI-assisted development.

---

## Anti-goals

It's worth being explicit about what this project will *not* become, since adjacent features will always seem appealing:

- A generic CRM
- A general project management tool
- A payroll or HR system
- A job board or applicant tracking system
- A note-taking app
- A LinkedIn replacement

Features that pattern-match to "wouldn't it be cool if Success also did X" should be evaluated against the career lifecycle focus. Re-read this section before approving any feature that pulls toward generic productivity territory.

---

## How this document relates to the rest of the docs

- **This file (`00-mission.md`)** — why we're building it, what we believe, what we won't do
- **`01-database-schema.md`** — the concrete schema that flows from these principles
- **`02-services-and-commands.md`** through **`04-frontend.md`** — the technical layers built on top of the schema
- **`05-ai-development-notes.md`** — operational conventions for AI-assisted development
- **`06-planned-features.md`** — the actual roadmap: milestones, deferred features, open questions

When this document and a technical doc disagree, this document is the tiebreaker. The technical docs describe what is; this one describes what should be.