import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ServerNavbar from '../../../Components/ServerNavbar';
import ServerSidebar from '../../../Components/ServerSidebar';

const CA_CERT_PATH = '/data/coolify/ssl/coolify-ca.crt';

export default function Show({
    serverNavbar,
    sidebar,
    certificateContent,
    certificateValidUntil,
    canManage,
    canView,
    saveUrl,
    regenerateUrl,
}) {
    const [showCertificate, setShowCertificate] = useState(false);
    const { data, setData, post, processing } = useForm({ certificateContent });

    function confirmAndSubmit(url, confirmMessage) {
        const confirmation = window.prompt(`${confirmMessage} Type the CA certificate path to confirm:`);
        if (confirmation !== CA_CERT_PATH) return;
        post(url, { preserveScroll: true });
    }

    function regenerate() {
        const confirmation = window.prompt(
            'This will generate a new CA certificate and replace the existing one. Type the CA certificate path to confirm:',
        );
        if (confirmation !== CA_CERT_PATH) return;
        router.post(regenerateUrl, {}, { preserveScroll: true });
    }

    let validUntilLabel = null;
    if (certificateValidUntil) {
        const validUntil = new Date(certificateValidUntil);
        const formatted = validUntil.toLocaleString();
        const isExpired = new Date() > validUntil;
        const isExpiringSoon = !isExpired && new Date(Date.now() + 30 * 24 * 60 * 60 * 1000) > validUntil;
        validUntilLabel = (
            <span className="text-sm">
                (Valid until:{' '}
                {isExpired || isExpiringSoon ? (
                    <span className="text-red-500">{formatted} - {isExpired ? 'Expired' : 'Expiring soon'})</span>
                ) : (
                    <span>{formatted})</span>
                )}
            </span>
        );
    }

    return (
        <div>
            <ServerNavbar serverNavbar={serverNavbar} />
            <div className="flex flex-col h-full gap-8 sm:flex-row">
                <ServerSidebar sidebar={sidebar} />
                <div className="flex flex-col gap-4">
                    <div className="flex items-center gap-2">
                        <h2>CA Certificate</h2>
                        {canManage && (
                            <div className="flex gap-2">
                                <button
                                    type="button"
                                    disabled={processing}
                                    onClick={() =>
                                        confirmAndSubmit(
                                            saveUrl,
                                            'This will overwrite the existing CA certificate with your custom CA certificate and regenerate all database SSL certificates on this server, signed with your custom CA.',
                                        )
                                    }
                                >
                                    Save
                                </button>
                                <button type="button" onClick={regenerate}>
                                    Regenerate
                                </button>
                            </div>
                        )}
                    </div>

                    <div className="space-y-4">
                        <div className="text-sm">
                            <p className="font-medium mb-2">Recommended Configuration:</p>
                            <ul className="list-disc pl-5 space-y-1">
                                <li>
                                    Mount this CA certificate of Coolify into all containers that need to connect to one of
                                    your databases over SSL. You can see and copy the bind mount below.
                                </li>
                                <li>
                                    Read more when and why this is needed{' '}
                                    <a className="underline dark:text-white" href="https://coolify.io/docs/databases/ssl" target="_blank" rel="noreferrer">
                                        here
                                    </a>.
                                </li>
                            </ul>
                        </div>
                        <div className="relative">
                            <code>- {CA_CERT_PATH}:/etc/ssl/certs/coolify-ca.crt:ro</code>
                        </div>
                    </div>

                    <div>
                        <div className="flex items-center justify-between mb-2">
                            <div className="flex items-center gap-2">
                                <span className="text-sm">CA Certificate</span>
                                {validUntilLabel}
                            </div>
                            {canView && (
                                <button type="button" className="py-1 px-2 text-sm" onClick={() => setShowCertificate((v) => !v)}>
                                    {showCertificate ? 'Hide' : 'Show'}
                                </button>
                            )}
                        </div>
                        {showCertificate ? (
                            <textarea
                                className="w-full h-[370px] input"
                                placeholder="Paste or edit CA certificate content here..."
                                value={data.certificateContent}
                                onChange={(e) => setData('certificateContent', e.target.value)}
                            />
                        ) : (
                            <div className="w-full h-[370px] input">
                                <div className="h-full flex flex-col items-center justify-center text-gray-300">
                                    <div className="mb-2">━━━━━━━━ CERTIFICATE CONTENT ━━━━━━━━</div>
                                    <div className="text-sm">Click "Show" to view or edit</div>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
