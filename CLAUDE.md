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

This project is tracked in a **public** GitHub repository at `leonardokerve-byte/for-you-solution` (remote `origin`, branch `master`). It was switched from private to public on 2026-07-19 to enable GitHub Pages. Authentication uses the GitHub CLI (`gh`), logged in as `leonardokerve-byte`. `gh` is installed at `C:\Program Files\GitHub CLI\gh.exe` but is not on PATH in every shell — invoke it by full path if `gh` is not found.

**Because the repo is public, never commit secrets** (FTP/hosting passwords, API tokens, etc.) into any tracked file, including this one.

**After any change to project files (`index.html`, `CLAUDE.md`, etc.), commit and push automatically — do not wait for the user to ask:**

1. `git add` the changed files (avoid `-A`/`.` blindly; review `git status` first so nothing unintended — e.g. local-only config — gets staged).
2. Commit with a short message describing the change (`Co-Authored-By: Claude <noreply@anthropic.com>` trailer, consistent with normal commit conventions).
3. Push to `origin master`.

A `post-commit` git hook (`.git/hooks/post-commit`) also pushes automatically as a safety net in case a commit is made without an explicit push step. This hook is local-only (not tracked by git) — if the repo is ever re-cloned, recreate it or push manually.

`.claude/settings.local.json` is machine-local Claude Code configuration and is gitignored — it should never be committed.

## Production hosting (live site)

The live production site is served from the user's own domain and paid hosting, **not** from GitHub. The canonical URL is:

- https://www.4yousolution.com.br/ and https://4yousolution.com.br/ (both resolve, both serve the same file; HTTP redirects to HTTPS)

Setup details:

- **Domain**: `4yousolution.com.br`, registered at Registro.br. DNS (nameservers `nebula.dns-parking.com` / `aurora.dns-parking.com`) already points the apex and `www` A records at the Hostinger IP `89.116.115.190` — no DNS changes are needed for routine deploys.
- **Hosting**: Hostinger, account `u500873686`. Web root is `/domains/4yousolution.com.br/public_html/` on the FTP server at `89.116.115.190:21`.
- **Deploy mechanism**: plain FTP upload of `index.html` to that web root (e.g. `curl -T index.html ftp://89.116.115.190:21/domains/4yousolution.com.br/public_html/index.html --user "<user>:<password>"`). **This is a manual/separate step from the GitHub push** — pushing to `origin master` does NOT update the live Hostinger site. After editing `index.html`, re-upload it via FTP if the user wants the production site updated.
- **Credentials**: FTP host/username/password are not stored in this repo (public repo — never commit them). Get them from the user each session, or from the Hostinger hPanel under Files → FTP Accounts if new ones are needed.
- **Old WordPress install**: Hostinger had auto-installed a default WordPress at `public_html` before this project was deployed. Rather than deleting it, it was moved (via FTP `RNFR`/`RNTO`, not deleted) into `public_html/_old_wordpress_backup/` on 2026-07-19, so it can be recovered if anything in it turns out to be needed.
- **GitHub Pages**: also enabled on this repo as a secondary/fallback mirror at `https://leonardokerve-byte.github.io/for-you-solution/`, auto-updating on every push to `master`. The Hostinger domain is the real production URL to give out; Pages is not the canonical site.
