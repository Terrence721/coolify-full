import { router, useForm } from '@inertiajs/react';
import ServerNavbar from '../../Components/ServerNavbar';
import ServerSidebar from '../../Components/ServerSidebar';

export default function LogDrains({
    serverNavbar,
    sidebar,
    isFunctional,
    isLogDrainEnabled,
    isLogDrainNewRelicEnabled,
    logDrainNewRelicLicenseKey,
    logDrainNewRelicBaseUri,
    isLogDrainAxiomEnabled,
    logDrainAxiomDatasetName,
    logDrainAxiomApiKey,
    isLogDrainCustomEnabled,
    logDrainCustomConfig,
    logDrainCustomConfigParser,
    toggleUrl,
    submitUrl,
}) {
    const newRelic = useForm({
        type: 'newrelic',
        logDrainNewRelicLicenseKey: logDrainNewRelicLicenseKey ?? '',
        logDrainNewRelicBaseUri: logDrainNewRelicBaseUri ?? '',
    });
    const axiom = useForm({
        type: 'axiom',
        logDrainAxiomDatasetName: logDrainAxiomDatasetName ?? '',
        logDrainAxiomApiKey: logDrainAxiomApiKey ?? '',
    });
    const custom = useForm({
        type: 'custom',
        logDrainCustomConfig: logDrainCustomConfig ?? '',
        logDrainCustomConfigParser: logDrainCustomConfigParser ?? '',
    });

    function toggle(type, enabled, fields = {}) {
        router.post(toggleUrl, { type, enabled, ...fields }, { preserveScroll: true });
    }

    if (!isFunctional) {
        return (
            <div>
                <ServerNavbar serverNavbar={serverNavbar} />
                <div className="flex flex-col h-full gap-8 sm:flex-row">
                    <ServerSidebar sidebar={sidebar} />
                    <div className="w-full">Server is not validated. Validate first.</div>
                </div>
            </div>
        );
    }

    return (
        <div>
            <ServerNavbar serverNavbar={serverNavbar} />
            <div className="flex flex-col h-full gap-8 sm:flex-row">
                <ServerSidebar sidebar={sidebar} />
                <div className="w-full">
                    <h2>Log Drains</h2>
                    <div>Sends service logs to 3rd party tools.</div>

                    <div className="flex flex-col gap-4 pt-4">
                        <div className="p-4 border dark:border-coolgray-300 border-neutral-200">
                            <h3>New Relic</h3>
                            <div className="w-32">
                                <label className="flex items-center gap-2">
                                    <input
                                        id="log-drain-newrelic-enabled"
                                        type="checkbox"
                                        disabled={isLogDrainAxiomEnabled || isLogDrainCustomEnabled}
                                        checked={isLogDrainNewRelicEnabled}
                                        onChange={(e) =>
                                            toggle('newrelic', e.target.checked, {
                                                logDrainNewRelicLicenseKey: newRelic.data.logDrainNewRelicLicenseKey,
                                                logDrainNewRelicBaseUri: newRelic.data.logDrainNewRelicBaseUri,
                                            })
                                        }
                                    />
                                    Enabled
                                </label>
                            </div>
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    newRelic.post(submitUrl, { preserveScroll: true });
                                }}
                                className="flex flex-col gap-4"
                            >
                                <div className="flex flex-col w-full gap-2 xl:flex-row">
                                    <label className="flex flex-col gap-1">
                                        License Key
                                        <input
                                            id="log-drain-newrelic-license-key"
                                            name="log-drain-newrelic-license-key"
                                            type="password"
                                            required
                                            disabled={isLogDrainEnabled}
                                            value={newRelic.data.logDrainNewRelicLicenseKey}
                                            onChange={(e) => newRelic.setData('logDrainNewRelicLicenseKey', e.target.value)}
                                        />
                                        {newRelic.errors.logDrainNewRelicLicenseKey && (
                                            <span className="text-error">{newRelic.errors.logDrainNewRelicLicenseKey}</span>
                                        )}
                                    </label>
                                    <label className="flex flex-col gap-1">
                                        Endpoint
                                        <input
                                            id="log-drain-newrelic-base-uri"
                                            name="log-drain-newrelic-base-uri"
                                            required
                                            disabled={isLogDrainEnabled}
                                            placeholder="https://log-api.eu.newrelic.com/log/v1"
                                            value={newRelic.data.logDrainNewRelicBaseUri}
                                            onChange={(e) => newRelic.setData('logDrainNewRelicBaseUri', e.target.value)}
                                        />
                                        {newRelic.errors.logDrainNewRelicBaseUri && (
                                            <span className="text-error">{newRelic.errors.logDrainNewRelicBaseUri}</span>
                                        )}
                                    </label>
                                </div>
                                <div className="flex justify-end gap-4 pt-6">
                                    <button type="submit" disabled={newRelic.processing || isLogDrainEnabled}>Save</button>
                                </div>
                            </form>

                            <h3>Axiom</h3>
                            <div className="w-32">
                                <label className="flex items-center gap-2">
                                    <input
                                        id="log-drain-axiom-enabled"
                                        type="checkbox"
                                        disabled={isLogDrainNewRelicEnabled || isLogDrainCustomEnabled}
                                        checked={isLogDrainAxiomEnabled}
                                        onChange={(e) =>
                                            toggle('axiom', e.target.checked, {
                                                logDrainAxiomDatasetName: axiom.data.logDrainAxiomDatasetName,
                                                logDrainAxiomApiKey: axiom.data.logDrainAxiomApiKey,
                                            })
                                        }
                                    />
                                    Enabled
                                </label>
                            </div>
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    axiom.post(submitUrl, { preserveScroll: true });
                                }}
                                className="flex flex-col gap-4"
                            >
                                <div className="flex flex-col w-full gap-2 xl:flex-row">
                                    <label className="flex flex-col gap-1">
                                        API Key
                                        <input
                                            id="log-drain-axiom-api-key"
                                            name="log-drain-axiom-api-key"
                                            type="password"
                                            required
                                            disabled={isLogDrainEnabled}
                                            value={axiom.data.logDrainAxiomApiKey}
                                            onChange={(e) => axiom.setData('logDrainAxiomApiKey', e.target.value)}
                                        />
                                        {axiom.errors.logDrainAxiomApiKey && <span className="text-error">{axiom.errors.logDrainAxiomApiKey}</span>}
                                    </label>
                                    <label className="flex flex-col gap-1">
                                        Dataset Name
                                        <input
                                            id="log-drain-axiom-dataset-name"
                                            name="log-drain-axiom-dataset-name"
                                            required
                                            disabled={isLogDrainEnabled}
                                            value={axiom.data.logDrainAxiomDatasetName}
                                            onChange={(e) => axiom.setData('logDrainAxiomDatasetName', e.target.value)}
                                        />
                                        {axiom.errors.logDrainAxiomDatasetName && (
                                            <span className="text-error">{axiom.errors.logDrainAxiomDatasetName}</span>
                                        )}
                                    </label>
                                </div>
                                <div className="flex justify-end gap-4 pt-6">
                                    <button type="submit" disabled={axiom.processing || isLogDrainEnabled}>Save</button>
                                </div>
                            </form>

                            <h3>Custom FluentBit</h3>
                            <div className="w-32">
                                <label className="flex items-center gap-2">
                                    <input
                                        id="log-drain-custom-enabled"
                                        type="checkbox"
                                        disabled={isLogDrainNewRelicEnabled || isLogDrainAxiomEnabled}
                                        checked={isLogDrainCustomEnabled}
                                        onChange={(e) =>
                                            toggle('custom', e.target.checked, {
                                                logDrainCustomConfig: custom.data.logDrainCustomConfig,
                                                logDrainCustomConfigParser: custom.data.logDrainCustomConfigParser,
                                            })
                                        }
                                    />
                                    Enabled
                                </label>
                            </div>
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    custom.post(submitUrl, { preserveScroll: true });
                                }}
                                className="flex flex-col gap-4"
                            >
                                <label className="flex flex-col gap-1">
                                    Custom FluentBit Configuration
                                    <textarea
                                        id="log-drain-custom-config"
                                        name="log-drain-custom-config"
                                        rows={6}
                                        required
                                        disabled={isLogDrainEnabled}
                                        value={custom.data.logDrainCustomConfig}
                                        onChange={(e) => custom.setData('logDrainCustomConfig', e.target.value)}
                                    />
                                    {custom.errors.logDrainCustomConfig && <span className="text-error">{custom.errors.logDrainCustomConfig}</span>}
                                </label>
                                <label className="flex flex-col gap-1">
                                    Custom Parser Configuration
                                    <textarea
                                        id="log-drain-custom-config-parser"
                                        name="log-drain-custom-config-parser"
                                        disabled={isLogDrainEnabled}
                                        value={custom.data.logDrainCustomConfigParser}
                                        onChange={(e) => custom.setData('logDrainCustomConfigParser', e.target.value)}
                                    />
                                </label>
                                <div className="flex justify-end gap-4 pt-6">
                                    <button type="submit" disabled={custom.processing || isLogDrainEnabled}>Save</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
