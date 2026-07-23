import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ActivityLog from '../../Components/ActivityLog';
import PrivateKeyCreateModal from '../../Components/PrivateKeyCreateModal';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function postJson(url, body) {
    const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        body: JSON.stringify(body ?? {}),
    });
    const data = await response.json().catch(() => ({}));

    return { ok: response.ok, status: response.status, data };
}

function Highlighted({ text }) {
    return <span className="font-bold dark:text-white">{text}</span>;
}

function StepShell({ title, question, explanation, actions }) {
    return (
        <div className="flex flex-col w-full max-w-3xl gap-8 lg:flex-row">
            <div className="flex-1 space-y-6">
                <h2 className="text-2xl font-bold">{title}</h2>
                {question && <p className="dark:text-neutral-300">{question}</p>}
                <div className="flex flex-col w-full gap-4">{actions}</div>
            </div>
            {explanation && <div className="w-full space-y-3 text-sm lg:w-80 dark:text-neutral-400">{explanation}</div>}
        </div>
    );
}

function ProgressBar({ step }) {
    const labels = ['Server', 'Connection', 'Complete'];

    return (
        <div className="flex items-center gap-2 mb-8">
            {labels.map((label, index) => (
                <div key={label} className="flex items-center gap-2">
                    <div
                        className={`flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold ${
                            index <= step ? 'bg-coollabs text-white dark:bg-warning' : 'bg-neutral-200 dark:bg-coolgray-300'
                        }`}
                    >
                        {index + 1}
                    </div>
                    <span className="text-xs dark:text-neutral-400">{label}</span>
                    {index < labels.length - 1 && <div className="w-8 h-px mx-2 bg-neutral-300 dark:bg-coolgray-400" />}
                </div>
            ))}
        </div>
    );
}

/**
 * React port of App\Livewire\Boarding\Index — the first-run onboarding wizard. See
 * BoardingController's docblock for the full scope writeup (why ByHetzner/ValidateAndInstall
 * stay Livewire, why Hetzner Cloud creation isn't offered here, why validateServer() collapses
 * the original's two overlapping SSH-validation engines into one).
 *
 * Step state lives in component state, synced to `?step=` via history.replaceState for
 * refresh/back-button friendliness — not full Inertia navigation per step, since most
 * transitions don't need fresh server data (the pieces that do — server/project creation,
 * validation — go through real POSTs).
 */
export default function Index({
    localhostServer,
    privateKeys: initialPrivateKeys,
    projects: initialProjects,
    minDockerVersion,
    createServerUrl,
    validateUrl,
    createProjectUrl,
    skipUrl,
    resourceCreateBaseUrl,
    privateKeyCreateUrl,
    privateKeyGenerateUrl,
}) {
    const { props } = usePage();
    const isCloud = props.permissions?.isCloud;

    const [step, setStep] = useState(() => new URLSearchParams(window.location.search).get('step') || 'welcome');
    const [privateKeys, setPrivateKeys] = useState(initialPrivateKeys ?? []);
    const [projects] = useState(initialProjects ?? []);
    const [selectedServer, setSelectedServer] = useState(null);
    const [selectedPrivateKeyId, setSelectedPrivateKeyId] = useState(privateKeys[0]?.id ?? null);
    const [showCreateKeyModal, setShowCreateKeyModal] = useState(false);
    const [createdProject, setCreatedProject] = useState(null);
    const [error, setError] = useState(null);
    const [installActivity, setInstallActivity] = useState(null);
    const [attempt, setAttempt] = useState(0);
    const [validating, setValidating] = useState(false);
    const lastCreatedKeyId = useRef(null);

    function goTo(nextStep) {
        setError(null);
        setStep(nextStep);
        const url = new URL(window.location.href);
        url.searchParams.set('step', nextStep);
        window.history.replaceState({}, '', url);
    }

    function startWelcome() {
        if (isCloud) {
            goTo('private-key');
        } else {
            goTo('explanation');
        }
    }

    function chooseLocalhost() {
        if (!localhostServer) {
            setError('Localhost server is not found. Something went wrong during installation. Please try to reinstall or contact support.');

            return;
        }
        setSelectedServer(localhostServer);
        setAttempt(0);
        goTo('validate-server');
        runValidate(localhostServer.uuid, 0);
    }

    function chooseRemote() {
        goTo('private-key');
    }

    useEffect(() => {
        const createdId = props.flash?.createdPrivateKeyId;
        if (createdId && createdId !== lastCreatedKeyId.current) {
            lastCreatedKeyId.current = createdId;
            setPrivateKeys((prev) => (prev.some((k) => k.id === createdId) ? prev : [...prev, { id: createdId, name: 'New key' }]));
            setSelectedPrivateKeyId(createdId);
            setShowCreateKeyModal(false);
            goTo('create-server');
        }
    }, [props.flash?.createdPrivateKeyId]);

    async function runValidate(serverUuid, attemptNumber) {
        setValidating(true);
        setError(null);
        const { ok, data } = await postJson(validateUrl, { server_uuid: serverUuid, install: true, attempt: attemptNumber });
        setValidating(false);
        if (!ok) {
            setError(data.message ?? 'Validation failed.');

            return;
        }
        if (data.status === 'installing') {
            setInstallActivity({ id: data.activityId, step: data.step });
            setAttempt(data.attempt);

            return;
        }
        setInstallActivity(null);
        if (data.status === 'validated') {
            goTo('create-project');

            return;
        }
        if (data.status === 'unreachable') {
            setError(`Server is not reachable. Please validate your configuration and connection.\n${data.error ?? ''}`);

            return;
        }
        if (data.status === 'unsupported_os') {
            setError('Server OS type is not supported. Please install Docker manually before continuing.');

            return;
        }
        setError(data.error ?? 'Validation failed.');
    }

    function onInstallFinished() {
        setInstallActivity(null);
        runValidate(selectedServer.uuid, attempt);
    }

    async function createProject() {
        const { ok, data } = await postJson(createProjectUrl);
        if (!ok) {
            setError(data.message ?? 'Failed to create project.');

            return;
        }
        setCreatedProject(data);
        goTo('create-resource');
    }

    function selectExistingProject(uuid) {
        const project = projects.find((p) => p.uuid === uuid);
        if (!project) {
            setError('Project not found.');

            return;
        }
        setCreatedProject(project);
        goTo('create-resource');
    }

    function skipBoarding() {
        router.post(skipUrl);
    }

    function deployFirstResource() {
        const params = new URLSearchParams({ server_id: selectedServer.id ?? '' });
        window.location.href = `${resourceCreateBaseUrl}/${createdProject.uuid}/environment/${createdProject.environmentUuid}/new?${params.toString()}`;
    }

    const showFooter = step !== 'welcome' && step !== 'create-resource';

    return (
        <div className="flex flex-col items-center w-full min-h-screen gap-8 px-4 py-16 bg-white dark:bg-base dark:text-white">
            {step === 'welcome' && (
                <div className="w-full max-w-2xl space-y-8 text-center">
                    <h1 className="text-5xl font-bold">Welcome to Coolify</h1>
                    <p className="text-lg dark:text-neutral-400">Connect your first server and start deploying in minutes</p>
                    <div className="flex flex-col items-center gap-3 pt-4">
                        <button type="button" onClick={startWelcome} className="px-12 py-4 text-lg font-bold">
                            Let&apos;s go!
                        </button>
                        <button type="button" onClick={skipBoarding} className="text-sm dark:text-neutral-400 hover:underline">
                            Skip Setup
                        </button>
                    </div>
                </div>
            )}

            {step === 'explanation' && (
                <>
                    <ProgressBar step={0} />
                    <StepShell
                        title="Platform Overview"
                        question="Coolify automates deployment and infrastructure management on your own servers. Deploy applications from Git, manage databases, and monitor everything—without vendor lock-in."
                        explanation={
                            <>
                                <p>
                                    <Highlighted text="Automation:" /> Coolify handles server configuration, Docker management, and deployments
                                    automatically.
                                </p>
                                <p>
                                    <Highlighted text="Self-hosted:" /> All data and configurations live on your infrastructure.
                                </p>
                                <p>
                                    <Highlighted text="Monitoring & Alerts:" /> Get real-time notifications via Discord, Telegram, Email, and other
                                    platforms.
                                </p>
                            </>
                        }
                        actions={
                            <button type="button" onClick={() => goTo('select-server-type')} className="w-full px-8 py-3 lg:w-auto">
                                Continue
                            </button>
                        }
                    />
                </>
            )}

            {step === 'select-server-type' && (
                <>
                    <ProgressBar step={1} />
                    <StepShell
                        title="Choose Server Type"
                        question="Select where to deploy your applications and databases. You can add more servers later."
                        explanation={
                            <>
                                <p>
                                    <Highlighted text="Servers" /> host your applications, databases, and services.
                                </p>
                                <p>
                                    <Highlighted text="Localhost:" /> The machine running Coolify. Not recommended for production workloads.
                                </p>
                                <p>
                                    <Highlighted text="Remote Server:" /> Any SSH-accessible server.
                                </p>
                            </>
                        }
                        actions={
                            <>
                                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                    <button type="button" onClick={chooseLocalhost} className="flex flex-col items-start gap-2 p-6 text-left">
                                        <span className="text-xs font-bold tracking-wide uppercase">Quick Start</span>
                                        <h3 className="text-xl font-bold">This Machine</h3>
                                        <p className="text-sm dark:text-neutral-400">
                                            Deploy on the server running Coolify. Best for testing and single-server setups.
                                        </p>
                                    </button>
                                    <button type="button" onClick={chooseRemote} className="flex flex-col items-start gap-2 p-6 text-left">
                                        <span className="text-xs font-bold tracking-wide uppercase">Recommended</span>
                                        <h3 className="text-xl font-bold">Remote Server</h3>
                                        <p className="text-sm dark:text-neutral-400">
                                            Connect via SSH to any server—cloud VPS, bare metal, or home infrastructure.
                                        </p>
                                    </button>
                                </div>
                                {error && <div className="text-sm text-error">{error}</div>}
                            </>
                        }
                    />
                </>
            )}

            {step === 'private-key' && (
                <>
                    <ProgressBar step={2} />
                    <StepShell
                        title="SSH Authentication"
                        question="Configure SSH key-based authentication for secure server access."
                        explanation={
                            <>
                                <p>
                                    <Highlighted text="SSH Key Authentication:" /> Uses public-key cryptography for secure, password-less server
                                    access.
                                </p>
                                <p>
                                    <Highlighted text="Public Key Deployment:" /> Add the public key to your server&apos;s{' '}
                                    <code className="px-1 py-0.5 text-xs rounded bg-coolgray-300">~/.ssh/authorized_keys</code> file.
                                </p>
                            </>
                        }
                        actions={
                            <>
                                {privateKeys.length > 0 && (
                                    <div className="flex flex-col gap-4 p-4 border rounded-lg border-neutral-200 dark:border-coolgray-400">
                                        <label className="flex flex-col gap-1">
                                            Existing SSH Keys
                                            <select
                                                id="boarding-existing-private-key"
                                                name="boarding-existing-private-key"
                                                value={selectedPrivateKeyId ?? ''}
                                                onChange={(e) => setSelectedPrivateKeyId(Number(e.target.value))}
                                            >
                                                {privateKeys.map((key) => (
                                                    <option key={key.id} value={key.id}>
                                                        {key.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>
                                        <button type="button" onClick={() => goTo('create-server')} className="w-full lg:w-auto">
                                            Use Selected Key
                                        </button>
                                    </div>
                                )}
                                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                    <button
                                        type="button"
                                        onClick={() => setShowCreateKeyModal(true)}
                                        className="flex flex-col items-center gap-2 py-6"
                                    >
                                        <h3 className="text-xl font-bold">Use Existing Key</h3>
                                        <p className="text-sm dark:text-neutral-400">I have my own SSH key</p>
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setShowCreateKeyModal(true)}
                                        className="flex flex-col items-center gap-2 py-6"
                                    >
                                        <h3 className="text-xl font-bold">Generate New Key</h3>
                                        <p className="text-sm dark:text-neutral-400">Create ED25519 key pair</p>
                                    </button>
                                </div>
                            </>
                        }
                    />
                </>
            )}

            {step === 'create-server' && (
                <>
                    <ProgressBar step={2} />
                    <ServerDetailsStep
                        createServerUrl={createServerUrl}
                        privateKeyId={selectedPrivateKeyId}
                        onCreated={(server) => {
                            setSelectedServer(server);
                            setAttempt(0);
                            goTo('validate-server');
                            runValidate(server.uuid, 0);
                        }}
                        error={error}
                        setError={setError}
                    />
                </>
            )}

            {step === 'validate-server' && (
                <>
                    <ProgressBar step={2} />
                    <StepShell
                        title="Server Validation"
                        question={`Coolify will automatically install Docker ${minDockerVersion}+ if not present.`}
                        explanation={
                            <>
                                <p>
                                    <Highlighted text="Automated Setup:" /> Coolify installs Docker Engine, Docker Compose, and configures system
                                    requirements automatically.
                                </p>
                                <p>
                                    <Highlighted text="Version Requirements:" /> Minimum Docker Engine {minDockerVersion}.x required.
                                </p>
                            </>
                        }
                        actions={
                            <div className="w-full space-y-6">
                                <div className="p-6 border rounded-lg bg-neutral-50 dark:bg-coolgray-200 border-neutral-200 dark:border-coolgray-400">
                                    <h3 className="mb-4 font-bold">Validation Steps</h3>
                                    <ul className="space-y-2 text-sm dark:text-neutral-400">
                                        <li>Test SSH Connection</li>
                                        <li>Check OS Compatibility</li>
                                        <li>Install Docker Engine (if needed)</li>
                                        <li>Configure Network</li>
                                    </ul>
                                </div>

                                {installActivity && (
                                    <div className="p-6 border rounded-lg bg-neutral-50 dark:bg-coolgray-200 border-neutral-200 dark:border-coolgray-400">
                                        <ActivityLog
                                            activityId={installActivity.id}
                                            header={installActivity.step === 'prerequisites' ? 'Installing Prerequisites' : 'Installing Docker'}
                                            onFinished={onInstallFinished}
                                        />
                                    </div>
                                )}

                                {error && <div className="p-4 text-sm border rounded-lg border-error text-error whitespace-pre-line">{error}</div>}

                                {!installActivity && (
                                    <button
                                        type="button"
                                        disabled={validating}
                                        onClick={() => runValidate(selectedServer.uuid, attempt)}
                                        className="w-full py-4 font-bold"
                                    >
                                        {validating ? 'Validating…' : error ? 'Retry Validation' : 'Start Validation'}
                                    </button>
                                )}
                            </div>
                        }
                    />
                </>
            )}

            {step === 'create-project' && (
                <>
                    <ProgressBar step={3} />
                    <StepShell
                        title="Project Setup"
                        question={
                            projects.length > 0
                                ? 'You have existing projects. Select one or create a new project to organize your resources.'
                                : 'Create your first project to organize applications, databases, and services.'
                        }
                        explanation={
                            <>
                                <p>
                                    <Highlighted text="Project Organization:" /> Group related resources into logical projects.
                                </p>
                                <p>
                                    <Highlighted text="Environments:" /> Each project includes a production environment by default.
                                </p>
                            </>
                        }
                        actions={
                            <div className="w-full space-y-4">
                                <button type="button" onClick={createProject} className="w-full py-4 font-bold">
                                    Create &quot;My First Project&quot;
                                </button>
                                {projects.length > 0 && (
                                    <>
                                        <div className="text-sm text-center dark:text-neutral-400">Or use existing</div>
                                        <label className="flex flex-col gap-1">
                                            Existing Projects
                                            <select
                                                id="boarding-existing-project"
                                                name="boarding-existing-project"
                                                onChange={(e) => selectExistingProject(e.target.value)}
                                                defaultValue=""
                                            >
                                                <option value="" disabled>
                                                    Select a project
                                                </option>
                                                {projects.map((project) => (
                                                    <option key={project.uuid} value={project.uuid}>
                                                        {project.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>
                                    </>
                                )}
                                {error && <div className="text-sm text-error">{error}</div>}
                            </div>
                        }
                    />
                </>
            )}

            {step === 'create-resource' && createdProject && (
                <div className="w-full max-w-2xl space-y-8 text-center">
                    <h1 className="text-4xl font-bold">Setup Complete!</h1>
                    <p className="text-lg dark:text-neutral-400">Your server is connected and ready. Start deploying your first resource.</p>
                    <div className="p-8 space-y-3 text-left border rounded-lg border-neutral-200 dark:border-coolgray-400">
                        <div>
                            <div className="font-semibold">Server: {selectedServer?.name}</div>
                        </div>
                        <div>
                            <div className="font-semibold">Project: {createdProject.name}</div>
                            <div className="text-sm dark:text-neutral-400">Production environment ready</div>
                        </div>
                        <div>
                            <div className="font-semibold">Docker Engine</div>
                            <div className="text-sm dark:text-neutral-400">Installed and running</div>
                        </div>
                    </div>
                    <div className="flex flex-col gap-3">
                        <button type="button" onClick={deployFirstResource} className="w-full py-4 text-lg font-bold">
                            Deploy Your First Resource
                        </button>
                        <button type="button" onClick={skipBoarding} className="text-sm dark:text-neutral-400 hover:underline">
                            Go to Dashboard
                        </button>
                    </div>
                </div>
            )}

            {showFooter && (
                <div className="flex justify-center gap-6 pt-8 mt-8 text-sm border-t border-neutral-200 dark:border-coolgray-400">
                    <button type="button" onClick={skipBoarding} className="dark:text-neutral-400 hover:underline">
                        Skip Setup
                    </button>
                    <button type="button" onClick={() => router.get('/onboarding')} className="dark:text-neutral-400 hover:underline">
                        Restart
                    </button>
                </div>
            )}

            <PrivateKeyCreateModal
                open={showCreateKeyModal}
                onClose={() => setShowCreateKeyModal(false)}
                createKeyUrl={privateKeyCreateUrl}
                generateKeyUrl={privateKeyGenerateUrl}
                onCreated={() => {}}
            />
        </div>
    );
}

function ServerDetailsStep({ createServerUrl, privateKeyId, onCreated, error, setError }) {
    const [name, setName] = useState(() => `server-${Math.random().toString(36).slice(2, 8)}`);
    const [description, setDescription] = useState('');
    const [ip, setIp] = useState('');
    const [port, setPort] = useState(22);
    const [user, setUser] = useState('root');
    const [showAdvanced, setShowAdvanced] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    async function submit(e) {
        e.preventDefault();
        if (!privateKeyId) {
            setError('Please select or create a private key first.');

            return;
        }
        setSubmitting(true);
        setError(null);
        const { ok, data } = await postJson(createServerUrl, {
            name,
            description,
            ip,
            port: Number(port),
            user,
            private_key_id: privateKeyId,
        });
        setSubmitting(false);
        if (!ok) {
            const fieldErrors = Object.values(data.errors ?? {})
                .flat()
                .join(' ');
            setError(data.message || fieldErrors || 'Failed to create server.');

            return;
        }
        onCreated(data);
    }

    return (
        <StepShell
            title="Server Configuration"
            question="Provide connection details for your remote server."
            explanation={
                <>
                    <p>
                        <Highlighted text="Connection Requirements:" /> Server must be accessible via SSH on the specified port (default 22).
                    </p>
                    <p>
                        <Highlighted text="User Permissions:" /> Root or sudo-enabled users recommended for full Docker management capabilities.
                    </p>
                </>
            }
            actions={
                <form onSubmit={submit} className="flex flex-col w-full gap-4">
                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <label className="flex flex-col gap-1">
                            Server Name
                            <input
                                id="boarding-server-name"
                                name="boarding-server-name"
                                required
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                placeholder="e.g., production-app-server"
                            />
                        </label>
                        <label className="flex flex-col gap-1">
                            IP Address/Hostname
                            <input
                                id="boarding-server-ip"
                                name="boarding-server-ip"
                                required
                                value={ip}
                                onChange={(e) => setIp(e.target.value)}
                                placeholder="IP address or hostname"
                            />
                        </label>
                    </div>
                    <label className="flex flex-col gap-1">
                        Description
                        <input
                            id="boarding-server-description"
                            name="boarding-server-description"
                            value={description}
                            onChange={(e) => setDescription(e.target.value)}
                            placeholder="Optional: Note what this server hosts"
                        />
                    </label>
                    <button type="button" onClick={() => setShowAdvanced((v) => !v)} className="text-sm font-medium text-left hover:underline">
                        Advanced Connection Settings
                    </button>
                    {showAdvanced && (
                        <div className="grid grid-cols-1 gap-4 p-4 border rounded-lg lg:grid-cols-2 border-neutral-200 dark:border-coolgray-400">
                            <label className="flex flex-col gap-1">
                                SSH Port
                                <input
                                    id="boarding-server-port"
                                    name="boarding-server-port"
                                    type="number"
                                    value={port}
                                    onChange={(e) => setPort(e.target.value)}
                                    placeholder="Default: 22"
                                />
                            </label>
                            <label className="flex flex-col gap-1">
                                SSH User
                                <input
                                    id="boarding-server-user"
                                    name="boarding-server-user"
                                    value={user}
                                    onChange={(e) => setUser(e.target.value)}
                                    placeholder="Default: root"
                                />
                            </label>
                        </div>
                    )}
                    {error && <div className="text-sm text-error">{error}</div>}
                    <button type="submit" disabled={submitting} className="w-full lg:w-auto">
                        {submitting ? 'Validating…' : 'Validate Connection'}
                    </button>
                </form>
            }
        />
    );
}

// Matches ForcePasswordReset.jsx's precedent: opts out of the default AppLayout wrapper (sidebar
// + topbar), replicating layouts/boarding.blade.php's minimal, chrome-less full-screen layout.
Index.layout = (page) => page;
