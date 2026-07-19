# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

This repository is a single static HTML page: `index.html`. It is the marketing/institutional site for "For You Solution", a Brazilian company with three business divisions (Telecom, Sistemas, Digital). The page is entirely self-contained — there is no build system, no package manager, no server-side code, and no external dependencies.

## Running the site

There is no build or dev-server step. Open `index.html` directly in a browser, or serve the directory with any static file server, e.g.:

```
npx serve .
```

There is no lint, test, or build command configured for this project.

## File structure and conventions

Everything lives in one file, `index.html`, laid out in three parts in this order:

1. **`<head>`** — meta tags, a base64-embedded JPEG favicon, and a single `<style>` block containing all CSS.
2. **`<body>`** — header/nav, `<main>` with all page sections (hero, divisions overview, Telecom, Sistemas, Digital, contact CTA), and `<footer>`.
3. **A single `<script>`** at the end of `<body>` with vanilla JS (no framework) for the mobile nav toggle and scroll-reveal animations via `IntersectionObserver`.

Key points to know before editing:

- **All assets are inlined as `data:` URIs** — the four `@font-face` declarations (Big Shoulders, Plex Mono, Plex Sans) and the two brand logo `<img>` tags (header + footer) each embed a large base64 blob directly in the HTML. These lines are extremely long (tens of thousands of characters). When reading or grepping this file, avoid dumping these lines in full — use tools that let you skip/truncate long lines (e.g. `awk`/`sed` with a length cap, or targeted `grep -o`) rather than reading the whole file at once, since the raw file is ~320KB on ~591 lines.
- **CSS uses custom properties for theming.** Colors are defined as `--ink`, `--paper`, `--brass`, `--signal`, etc. in `:root`, with overrides for `@media (prefers-color-scheme: dark)` and explicit `:root[data-theme="dark"]` / `:root[data-theme="light"]` selectors (the latter for a manual theme toggle, if one is wired up elsewhere — no toggle control exists in the current markup). When changing colors, edit the CSS variables rather than hardcoding new colors in rules.
- **Design language**: uses `clip-path: polygon(...)` extensively for angular/faceted card and button shapes, and CSS `clamp()` for fluid typography/spacing instead of media-query breakpoints where possible. Keep new components consistent with this faceted-card aesthetic.
- **No JS framework or bundler** — the inline `<script>` is plain ES5-style JS (`var`, `function`). Keep additions dependency-free and consistent with this style unless the project's tooling requirements change.
- Content is in Brazilian Portuguese (`lang="pt-BR"`).

## Making edits

Since this is a single monolithic file, prefer targeted edits (Edit tool with unique surrounding context) over rewriting the whole file. When adding new sections, follow the existing pattern: a `<section>` with a `wrap` container, a `.section-head` block with `.eyebrow` + `h2` + descriptive `p`, and a `.reveal` class if the section should animate in on scroll.

## Version control and GitHub sync

This project is tracked in a **private** GitHub repository at `leonardokerve-byte/for-you-solution` (remote `origin`, branch `master`). Authentication uses the GitHub CLI (`gh`), logged in as `leonardokerve-byte`.

**After any change to project files (`index.html`, `CLAUDE.md`, etc.), commit and push automatically — do not wait for the user to ask:**

1. `git add` the changed files (avoid `-A`/`.` blindly; review `git status` first so nothing unintended — e.g. local-only config — gets staged).
2. Commit with a short message describing the change (`Co-Authored-By: Claude <noreply@anthropic.com>` trailer, consistent with normal commit conventions).
3. Push to `origin master`.

A `post-commit` git hook (`.git/hooks/post-commit`) also pushes automatically as a safety net in case a commit is made without an explicit push step. This hook is local-only (not tracked by git) — if the repo is ever re-cloned, recreate it or push manually.

`.claude/settings.local.json` is machine-local Claude Code configuration and is gitignored — it should never be committed.
