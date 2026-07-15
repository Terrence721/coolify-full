import { router, useForm } from '@inertiajs/react';

export default function AdminView({ search, users, lotsOfUsers, deleteUserUrl }) {
    const { data, setData, get, processing } = useForm({ search });

    function submitSearch(e) {
        e.preventDefault();
        get('/team/admin', { preserveState: true });
    }

    function deleteUser(user) {
        const confirmation = window.prompt(
            `This will permanently delete "${user.name}" and all resources owned by their default team. Type the user name to confirm:`,
        );
        if (confirmation !== user.name) return;
        const password = window.prompt('Enter your password to confirm:');
        if (!password) return;
        router.delete(deleteUserUrl, { data: { id: user.id, password } });
    }

    return (
        <div>
            <div className="pb-6">
                <div className="flex items-end gap-2">
                    <h1>Team</h1>
                    {/* "+ Add Team" modal intentionally omitted for now — a real, still-open UI gap. */}
                </div>
                <div className="subtitle">Team wide configurations.</div>
                <div className="navbar-main">
                    <nav className="flex items-center gap-6 min-h-10">
                        <a href="/team">General</a>
                        <a href="/team/members">Members</a>
                        <a href="/team/admin" className="dark:text-white">
                            Admin View
                        </a>
                    </nav>
                </div>
            </div>

            <h2>Admin View</h2>
            <div className="subtitle">Manage users of this instance.</div>
            <form onSubmit={submitSearch} className="flex flex-col gap-2 lg:flex-row">
                <input
                    id="team-admin-user-search"
                    name="team-admin-user-search"
                    placeholder="Search for a user"
                    value={data.search}
                    onChange={(e) => setData('search', e.target.value)}
                />
                <button type="submit" disabled={processing}>
                    Search
                </button>
            </form>

            <h3 className="py-4">Users</h3>
            <div className="grid grid-cols-1 gap-2 lg:grid-cols-2">
                {users.length === 0 && <div>No users found other than the root.</div>}
                {users.map((user) => (
                    <div key={user.id} className="flex items-center justify-center gap-2 bg-white box-without-bg dark:bg-coolgray-100">
                        <div>{user.name}</div>
                        <div>{user.email}</div>
                        <div className="flex-1" />
                        <div className="flex items-center justify-center gap-2 mx-4 text-xs font-bold">
                            <button type="button" onClick={() => deleteUser(user)}>
                                Delete
                            </button>
                        </div>
                    </div>
                ))}
                {lotsOfUsers && (
                    <div>There are more users than shown. Please use the search bar to find the user you are looking for.</div>
                )}
            </div>
        </div>
    );
}
