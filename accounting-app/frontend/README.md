# Accountance — Frontend

Next.js (App Router) frontend for the Accountance accounting web app.

This is the **Phase 1 foundation**: design tokens, theming, UI primitives, and
API/formatting utilities. Authentication, company context, and the accounting
screens are added in later phases.

## Requirements

- Node.js 20.9+ (developed against Node 24)
- npm 10+

## Getting started

```bash
cd accounting-app/frontend
npm install
cp .env.example .env.local   # then edit NEXT_PUBLIC_API_BASE_URL
npm run dev
```

The app runs at http://localhost:3000.

### Environment variables

| Variable                   | Required | Description                                                                 |
| -------------------------- | -------- | --------------------------------------------------------------------------- |
| `NEXT_PUBLIC_API_BASE_URL` | For API  | Base URL of the Laravel API **including** `/api`, reachable from the browser. |

Values prefixed with `NEXT_PUBLIC_` are inlined into the client bundle at build
time. Changing `.env.local` requires a dev-server restart (and a rebuild in
production). If unset, the app still renders; API calls fail with a clear
"not configured" error.

`.env.local` is git-ignored. `.env.example` documents the required variables.

## Scripts

| Script            | Purpose                             |
| ----------------- | ----------------------------------- |
| `npm run dev`     | Start the development server        |
| `npm run build`   | Production build                    |
| `npm run start`   | Serve the production build          |
| `npm run lint`    | ESLint                              |

Type checking runs as part of `npm run build` (`tsc` via Next).

## Project structure

```
src/
  app/                 App Router routes, layout, global styles
  components/
    theme/             Light/Dark provider, pre-paint script, toggle
    ui/                Reusable primitives (Button, Card, Input, ...)
  lib/
    api/               API client, config, envelope types, pagination adapter
    format/            Precision-safe money and date formatting
    utils/             Small helpers (cn)
```

## Conventions

- **Theme**: class-based (`dark` on `<html>`), applied before first paint, with
  tokens defined in `src/app/globals.css`.
- **API access**: go through `src/lib/api/client.ts`. Auth and company-context
  headers are attached in Phase 2 via `setRequestHeadersProvider`, so call sites
  do not change when the token strategy is finalised.
- **Money**: values are decimal strings; format with `formatMoney` /
  `formatDecimal` (never `Number`). See `src/lib/format/money.ts`.
- **Dates**: business dates are `YYYY-MM-DD`; use `formatDateOnly`.
  Timestamps are ISO-8601 UTC; use `formatTimestamp` (defaults to UTC).
