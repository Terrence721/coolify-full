Frontend Architecture — React 19 + Inertia.js + Vite
This document explains the real frontend architecture inside your Coolify‑Full repository, based on the actual structure under resources/.

🎨 Overview
Coolify‑Full uses a hybrid frontend architecture:

React 19 (your modernization)

Inertia.js (SPA bridge between Laravel + React)

Blade templates (legacy UI still present)

Vite (build system)

TailwindCSS (styling)

SVG assets (svgs/ folder)


resources/
│
├── js/                     # React 19 components, pages, hooks
├── views/                  # Blade templates (legacy)
├── css/                    # TailwindCSS styles
├── lang/                   # Localization files
└── images/                 # Static assets


⚛️ React 19 Architecture
Key Features
Concurrent rendering

Modern component structure

Page‑by‑page migration from Livewire

Inertia.js routing

Axios API calls

Vite dev server + HMR

resources/js/
│
├── Pages/                 # Inertia pages (React)
├── Components/            # Reusable React components
├── Hooks/                 # Custom hooks
├── Layouts/               # Shared layouts
└── Utils/                 # Helpers

🔗 Inertia.js Integration

React 19 SPA ←→ Inertia.js ←→ Laravel Routes


⚡ Vite Build System
Vite handles:

Dev server

HMR

Production builds

Asset bundling

Configuration lives in:

vite.config.js


🎯 Frontend Modernization Goals
Replace Livewire with React 19

Improve UI responsiveness

Introduce modern component patterns

Reduce Blade dependency

Move toward a full SPA architecture
