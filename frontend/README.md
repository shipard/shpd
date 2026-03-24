# Shipard Frontend

Svelte 5 SPA frontend for the Shipard application.

## Prerequisites

- Node.js 20+
- npm 10+

## Setup

```bash
npm install
```

## Development

```bash
npm run dev
```

Starts the Vite dev server (default: http://localhost:5173). API requests to `/api/*` are proxied to `http://localhost:80`.

## Production build

```bash
npm run build
```

Outputs static files to `../public/app/` (relative to this directory). The build is served by nginx at `/app/`.

## Preview production build

```bash
npm run preview
```

Serves the production build locally for testing.
