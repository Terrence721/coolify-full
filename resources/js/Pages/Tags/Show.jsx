import { router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';

export default function Show({ tags, tag, applications, services, deploymentsPerTagPerServer }) {
    const { url } = usePage();

    // Replaces the original Livewire page's wire:poll="getDeployments" — periodically
    // re-fetches just the deployment status props so in-progress/queued deployments stay
    // live without a full page reload. Simple interval-based polling rather than the
    // Echo/broadcast approach used for genuinely real-time (Hard-bucket) pages.
    useEffect(() => {
        if (!tag) return undefined;

        const interval = setInterval(() => {
            router.reload({ only: ['deploymentsPerTagPerServer'] });
        }, 3000);

        return () => clearInterval(interval);
    }, [tag, url]);

    function redeployAll(e) {
        e.preventDefault();
        if (!window.confirm('All resources with this tag will be redeployed. During redeploy resources will be temporarily unavailable. Continue?')) {
            return;
        }
        router.post(tag.redeployUrl);
    }

    return (
        <div>
            <div className="flex items-start gap-2 pb-10">
                <div>
                    <h1 className="pb-2">Tags</h1>
                    <div>Tags help you to perform actions on multiple resources.</div>
                </div>
            </div>
            <div className="flex flex-wrap gap-2">
                {tags.length === 0 && <div>No tags yet defined yet. Go to a resource and add a tag there.</div>}
                {tags.map((oneTag) => (
                    <a
                        key={oneTag.name}
                        className={`min-w-32 coolbox dark:text-white font-bold flex justify-center items-center ${tag?.name === oneTag.name ? 'dark:bg-coollabs' : ''}`}
                        href={oneTag.href}
                    >
                        {oneTag.name}
                    </a>
                ))}
            </div>

            {tag && (
                <div>
                    <h3 className="py-4">Tag Details</h3>
                    <div className="flex items-end gap-2">
                        <label className="flex flex-col gap-1 w-[500px]">
                            Deploy Webhook URL
                            <input readOnly value={tag.webhook} />
                        </label>
                        <button type="button" onClick={redeployAll}>
                            Redeploy All
                        </button>
                    </div>

                    <div className="grid grid-cols-1 gap-2 pt-4 lg:grid-cols-2 xl:grid-cols-3">
                        {applications?.map((application) => (
                            <a key={application.href} href={application.href} className="coolbox group">
                                <div className="flex flex-col justify-center">
                                    <div className="box-title">{application.projectEnvironment}</div>
                                    <div className="box-description">{application.name}</div>
                                    <div className="box-description">{application.description}</div>
                                </div>
                            </a>
                        ))}
                        {services?.map((service) => (
                            <a key={service.href} href={service.href} className="flex flex-col coolbox group">
                                <div className="flex flex-col">
                                    <div className="box-title">{service.projectEnvironment}</div>
                                    <div className="box-description">{service.name}</div>
                                    <div className="box-description">{service.description}</div>
                                </div>
                            </a>
                        ))}
                    </div>

                    <div className="flex items-center gap-2">
                        <h3 className="py-4">Deployments</h3>
                    </div>
                    <div className="grid grid-cols-1">
                        {Object.keys(deploymentsPerTagPerServer ?? {}).length === 0 && <div>No deployments running.</div>}
                        {Object.entries(deploymentsPerTagPerServer ?? {}).map(([serverName, deployments]) => (
                            <div key={serverName}>
                                <h4 className="py-4">{serverName}</h4>
                                <div className="grid grid-cols-1 gap-2 lg:grid-cols-3">
                                    {deployments.map((deployment) => (
                                        <a
                                            key={deployment.id}
                                            href={deployment.deployment_url}
                                            className={`gap-2 cursor-pointer coolbox group border-l-2 border-dotted ${
                                                deployment.status === 'queued' ? 'dark:border-coolgray-300' : ''
                                            } ${deployment.status === 'in_progress' ? 'border-warning-500' : ''}`}
                                        >
                                            <div className="flex flex-col mx-6">
                                                <div className="font-bold dark:text-white">{deployment.application_name}</div>
                                                <div className="description">{deployment.status}</div>
                                            </div>
                                            <div className="flex-1" />
                                        </a>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}
