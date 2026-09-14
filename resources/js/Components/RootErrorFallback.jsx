// Deliberately no Inertia Link/router, unlike AppLayout's own page-content error boundary
// fallback - this one is mounted by the root ErrorBoundary in inertia-app.jsx, the true last
// resort for anything outside that inner boundary's coverage (e.g. AppLayout itself throwing),
// so it can't assume Inertia's own render tree is in a safe state to navigate within. A plain
// reload is the one recovery path guaranteed to still work.
export default function RootErrorFallback() {
    return (
        <div style={{ padding: '2rem', textAlign: 'center', fontFamily: 'sans-serif' }}>
            <h1>Something went wrong.</h1>
            <p>Please reload the page.</p>
            <button type="button" onClick={() => window.location.reload()}>
                Reload
            </button>
        </div>
    );
}
