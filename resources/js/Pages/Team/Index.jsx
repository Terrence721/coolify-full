import { router, useForm, usePage } from '@inertiajs/react';

export default function Index({ team, canUpdate, canDelete, deletionBlockedReason, blockingResources, updateUrl, deleteUrl }) {
    const { permissions } = usePage().props;
    const { data, setData, put, processing, errors } = useForm({
        name: team.name,
        description: team.description ?? '',
    });

    function submit(e) {
        e.preventDefault();
        put(updateUrl);
    }

    function deleteTeam() {
        const confirmation = window.prompt(
            `This will permanently delete "${team.name}" and cannot be undone. Type the team name to confirm:`,
        );
        if (confirmation !== team.name) return;
        router.delete(deleteUrl);
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
                        <a href="/team" className="dark:text-white">
                            General
                        </a>
                        <a href="/team/members">Members</a>
                        {permissions?.isInstanceAdmin && <a href="/team/admin">Admin View</a>}
                        <div className="flex-1" />
                    </nav>
                </div>
            </div>

            <form onSubmit={submit} className="flex flex-col">
                <h2>General</h2>
                <div className="subtitle">Manage the general settings of this team.</div>
                <div className="flex items-end gap-2 pb-6">
                    <label className="flex flex-col gap-1">
                        Name
                        <input
                            id="team-name"
                            name="team-name"
                            disabled={!canUpdate}
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                        />
                        {errors.name && <span className="text-error">{errors.name}</span>}
                    </label>
                    <label className="flex flex-col gap-1">
                        Description
                        <input
                            id="team-description"
                            name="team-description"
                            disabled={!canUpdate}
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                        />
                        {errors.description && <span className="text-error">{errors.description}</span>}
                    </label>
                    {canUpdate && (
                        <button type="submit" disabled={processing}>
                            Save
                        </button>
                    )}
                </div>
            </form>

            {canDelete && (
                <div>
                    <h2>Danger Zone</h2>
                    <div className="pb-4">Woah. I hope you know what are you doing.</div>
                    <h4 className="pb-4">Delete Team</h4>
                    {deletionBlockedReason === 'default-team' && <div>This is the default team. You can't delete it.</div>}
                    {deletionBlockedReason === 'last-team' && <div>You can't delete your last / personal team.</div>}
                    {deletionBlockedReason === 'not-empty' && (
                        <div>
                            <div className="pb-4">You need to delete the following resources to be able to delete the team:</div>
                            {['projects', 'servers', 'privateKeys', 'sources'].map(
                                (key) =>
                                    blockingResources[key]?.length > 0 && (
                                        <div key={key}>
                                            <h4 className="py-4 capitalize">{key}:</h4>
                                            <ul className="pl-8 list-disc">
                                                {blockingResources[key].map((name) => (
                                                    <li key={name}>{name}</li>
                                                ))}
                                            </ul>
                                        </div>
                                    ),
                            )}
                        </div>
                    )}
                    {!deletionBlockedReason && (
                        <>
                            <div className="pb-4">This will delete your team. Beware! There is no coming back!</div>
                            <button type="button" onClick={deleteTeam}>
                                Delete
                            </button>
                        </>
                    )}
                </div>
            )}
        </div>
    );
}
