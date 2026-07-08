import { router } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({
    name,
    email,
    impersonating,
    search: initialSearch,
    foundUsers,
    activeSubscribers,
    inactiveSubscribers,
    backUrl,
    switchUserUrl,
}) {
    const [search, setSearch] = useState(initialSearch ?? '');

    function submitSearch(e) {
        e.preventDefault();
        router.get('/admin', { search }, { preserveState: true });
    }

    function goBack(e) {
        e.preventDefault();
        router.post(backUrl);
    }

    function switchUser(userId) {
        router.post(switchUserUrl, { user_id: userId });
    }

    return (
        <div>
            <h1>Admin Dashboard</h1>
            <div className="flex gap-2 pt-4">
                <h3>Who am I now?</h3>
                {impersonating && (
                    <button type="button" onClick={goBack}>
                        Go back to root
                    </button>
                )}
            </div>
            <div className="pb-4">
                {name} ({email})
            </div>
            <form onSubmit={submitSearch} className="flex flex-col gap-2 lg:flex-row">
                <input placeholder="Search for a user" value={search} onChange={(e) => setSearch(e.target.value)} />
                <button type="submit">Search</button>
            </form>
            <div className="pt-4">Active Subscribers : {activeSubscribers}</div>
            <div>Inactive Subscribers : {inactiveSubscribers}</div>
            {initialSearch && (
                <>
                    {foundUsers.length > 0 ? (
                        <div className="flex flex-wrap gap-2 pt-4">
                            {foundUsers.map((user) => (
                                <div key={user.id} className="coolbox w-64 group" onClick={() => switchUser(user.id)}>
                                    <div className="flex flex-col gap-2">
                                        <div className="box-title">{user.name}</div>
                                        <div className="box-description">{user.email}</div>
                                        <div className="box-description">Active: {user.hasActiveSubscription ? 'Yes' : 'No'}</div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <div>No users found with {initialSearch}</div>
                    )}
                </>
            )}
        </div>
    );
}
