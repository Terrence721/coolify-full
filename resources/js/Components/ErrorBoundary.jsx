import { Component } from 'react';

/**
 * Catches render errors in the subtree below it and shows a fallback instead of leaving
 * Inertia's persistently-mounted React tree blank - see issue #126: with zero boundaries
 * anywhere in the app, any component throwing during render blanked the *entire* page (nav/
 * sidebar chrome included) with no recovery until a manual browser reload. React has no
 * function-component equivalent for componentDidCatch()/getDerivedStateFromError() yet, so this
 * has to be a class component.
 */
export default class ErrorBoundary extends Component {
    state = { hasError: false };

    static getDerivedStateFromError() {
        return { hasError: true };
    }

    componentDidCatch(error, info) {
        console.error('ErrorBoundary caught a render error:', error, info);
    }

    render() {
        if (this.state.hasError) {
            return this.props.fallback;
        }

        return this.props.children;
    }
}
