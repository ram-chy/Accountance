# Frontend Phase 1 — Foundation Implementation Report

- **Date:** 2026-10-11
- **Scope:** Frontend Phase 1 only (foundation). No authentication,
  company-context wiring, or accounting screens.
- **Status:** Complete. Lint, type-check, and production build pass; the app
  server-renders successfully.
- **Not committed:** all work is uncommitted/untracked, per the brief.

## 1. Pre-implementation review

| Check | Result |
| --- | --- |
| Repository root / branch | `E:\Ram\Accountance`, branch `main` |
| `git status --short` before work | Only untracked Phase 0 docs + `prompt/frontend/`; no existing user changes to disturb |
| Existing frontend | None (no `next.config.*`, no frontend manifest) |
| Phase 0 docs | Read: `FRONTEND_PHASE_0_AUDIT.md`, `FRONTEND_BLUEPRINT.md`, `FRONTEND_PHASE_0_REPORT.md` |
| Backend API conventions | Followed the Phase 0 audit (`{ success, data }` envelope, flat list `data` arrays, decimal strings, `YYYY-MM-DD` business dates, ISO-8601 UTC timestamps) |

Backend files were **not** modified (`git diff --stat -- accounting-app/backend`
is empty).

## 2. Stack and versions

Chosen by `create-next-app@latest` and kept as generated:

- Next.js **16.4.0** (App Router, Turbopack, Cache Components enabled)
- React **19.3.0**
- Tailwind CSS **v4** (`@tailwindcss/turbopack`, configured in
  `next.config.ts`; no PostCSS config needed)
- TypeScript `strict: true`, ESLint (flat config, `eslint-config-next`)

No nested git repository was created (`--disable-git`).

## 3. What was built

App location: `accounting-app/frontend`.

```
src/
  app/
    globals.css          Design tokens (:root / .dark) + Tailwind v4 @theme mapping
    layout.tsx           Root layout, metadata, pre-paint theme script
    page.tsx             Foundation preview page (server component)
    not-found.tsx        404 route
  components/
    theme/
      theme-provider.tsx Light/Dark provider via useSyncExternalStore
      theme-toggle.tsx   Accessible toggle (inline SVG, no icon library)
    ui/
      button.tsx         Primary/secondary/outline/ghost/danger, sizes, loading
      card.tsx           Card, CardHeader, CardTitle, CardDescription, Content, Footer
      field.tsx          Field, Label, Input, HelperText, FieldError, FormField
      badge.tsx          neutral/primary/success/warning/danger/info
      spinner.tsx        Accessible spinner (SMIL/CSS, currentColor)
      empty-state.tsx    Neutral empty state
      loading-state.tsx  Busy state with `role="status"`
      error-state.tsx    `role="alert"` error surface with request id
  lib/
    api/config.ts        `NEXT_PUBLIC_API_BASE_URL` handling + defaults
    api/types.ts         Envelope, error body, paginator, query types
    api/errors.ts        `ApiError` (status, validation bag, request id, flags)
    api/client.ts        `apiFetch` + helpers, envelope unwrap, header hook
    api/pagination.ts    `normalizeList` adapter for flat/Laravel payloads
    format/money.ts      Precision-safe decimal/money formatting
    format/date.ts       Date-only and ISO-timestamp formatting
    theme/constants.ts   Shared theme storage key (outside the client boundary)
    utils/cn.ts          Tiny class-name joiner
```

Supporting files: `.env.example`, rewritten `README.md`, `.gitignore` updated to
allow committing `.env.example`.

## 3.1 Vue of the entry page

The app entry (`/`) is a neutral "foundation" preview: brand header + theme
toggle, a status card showing whether `NEXT_PUBLIC_API_BASE_URL` is configured,
foundation-area cards, a UI-primitives demo (buttons, badges, labelled inputs),
and a **clearly-labelled formatting preview using illustrative sample values**
(not real account data, per the brief). It is not the accounting dashboard.

## 4. Key implementation decisions

1. **Theme strategy — lightweight, dependency-free.** Phase 0 left the theme
   library choice open (blueprint §15) and the brief says to avoid unnecessary
   dependencies. Implemented a class-based (`dark` on `<html>`) provider using
   `useSyncExternalStore` plus a pre-paint inline script in the layout, so there
   is no flash and no hydration mismatch. No `next-themes` dependency added.
2. **API base URL — `NEXT_PUBLIC_API_BASE_URL`.** Per the brief (§8), the
   foundation uses a public base URL suitable for browser requests. The
   token-storage decision remains **open**; the client exposes
   `setRequestHeadersProvider(...)` so Phase 2 can attach auth/company headers
   without touching call sites, or switch to a same-origin BFF by changing only
   the base URL.
3. **API client.** Unwraps the `{ success, data }` envelope, treats `204`/empty
   as `undefined`, raises `ApiError` for non-2xx and for `success: false` on
   2xx, surfaces `x-request-id`, and never retries writes.
4. **Pagination adapter.** `normalizeList` accepts the backend's flat `data`
   array and a Laravel paginator alike. Because the backend exposes no
   `total`/`last_page` today (audit finding P2-1), `hasMore` falls back to
   "a full page was returned"; no totals are fabricated.
5. **Money formatting is precision-safe.** Values are formatted from the decimal
   string using string/BigInt arithmetic and `Intl` for separators — never
   via `Number` — so large amounts keep their exact value.
6. **Dates.** Business dates (`YYYY-MM-DD`) are parsed manually and formatted in
   UTC so a calendar date never shifts; timestamps default to UTC and accept a
   company time zone argument for Phase 2.
7. **No test framework added.** The brief allows tests only "if the repository
   already has a compatible test setup"; the frontend has none, and adding a
   framework would contradict "do not install unnecessary dependencies". The
   utility modules are pure and ready for Vitest in a later phase (see §7).
8. **No icon library.** The two icons in use are inline SVG.

## 5. Verification performed

| Gate | Command | Result |
| --- | --- | --- |
| Lint | `npm run lint` | Pass, 0 problems |
| Type-check | `npx tsc --noEmit` | Pass, 0 errors |
| Build | `NEXT_PUBLIC_API_BASE_URL=… npm run build` | Pass; `/` and `/_not-found` prerendered as static |
| SSR smoke test | `npm run dev` + `curl /` | `HTTP 200`, title `Accountance`, expected content present |

The SSR smoke test caught a real defect: the pre-paint theme key was imported
from a `"use client"` module into the server layout, so Next replaced it with a
client reference and the emitted script contained `var k=undefined`. The key now
lives in the non-client module `src/lib/theme/constants.ts`; the emitted script
is `var k="accountance-theme"`.

**Not verified:** live browser hydration/console. No desktop browser is
connected to this session, so the automated browser tools could not attach. The
SSR HTML and production build were verified instead; the provider is written to
avoid hydration mismatch by design (server snapshot `light`, then adopts the
pre-paint value).

## 6. Deviations from the Phase 0 blueprint

- **No BFF / httpOnly cookie** implemented (recommendation was never approved,
  and Phase 1 has no auth). The direct-browser client + header hook keeps that
  option open — see §4.2.
- **No TanStack Query / react-hook-form / zod yet.** These belong with the
  screens that use them (Phase 2+); installing them now would add unused
  dependencies.
- **No chart library** (trend charts were deferred in the blueprint).

## 7. Recommended next steps (Phase 2)

1. **Resolve token storage** (BFF + httpOnly cookie vs. direct bearer) — the
   only decision that meaningfully changes the client wiring. Register
   `setRequestHeadersProvider` accordingly.
2. Authentication screens and a protected layout; 401 handling that clears
   session state and redirects.
3. Company context: company switcher fed by `GET /api/companies`, with the
   active company sent as the required header, plus per-company time zone into
   `formatTimestamp`.
4. Permissions-aware navigation from `GET /api/auth/me`.
5. Add Vitest (+ React Testing Library) and cover `formatMoney`, `formatDecimal`,
   `formatDateOnly`, `formatTimestamp`, and `normalizeList`.
6. Introduce TanStack Query when the first data-backed screen lands.

## 8. Files added/changed (all uncommitted)

- New app: everything under `accounting-app/frontend/`.
- New doc: this report (`FRONTEND_PHASE_1_REPORT.md`).
- Untouched: `accounting-app/backend/**`, `docs/FRONTEND_API_HANDOFF.md`,
  existing Phase 0 docs, `prompt/frontend/`.
