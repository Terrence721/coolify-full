import { useEffect, useRef } from 'react';

const MONACO_VERSION = '0.52.2';

let loaderPromise = null;

function loadMonacoLoader() {
    if (window.monaco) {
        return Promise.resolve();
    }
    if (typeof window.require !== 'undefined' && typeof window.require.config === 'function') {
        return Promise.resolve();
    }
    if (loaderPromise) {
        return loaderPromise;
    }

    loaderPromise = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = `/js/monaco-editor-${MONACO_VERSION}/min/vs/loader.js`;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Failed to load Monaco loader'));
        document.head.appendChild(script);
    });

    return loaderPromise;
}

/**
 * React port of resources/views/components/forms/monaco-editor.blade.php. Monaco is only ever
 * loaded via the same static AMD-loader script (public/js/monaco-editor-{version}/min/vs/loader.js)
 * the Livewire/Alpine version uses — not an npm dependency — matching the dynamic-script-loading
 * pattern established for ApexCharts in the Metrics page.
 */
export default function MonacoEditor({ value, onChange, language = 'yaml', readOnly = false, height = 'calc(100vh - 20rem)' }) {
    const containerRef = useRef(null);
    const editorRef = useRef(null);
    const onChangeRef = useRef(onChange);
    onChangeRef.current = onChange;

    useEffect(() => {
        let cancelled = false;
        let observer = null;
        let workerBlobUrl = null;

        loadMonacoLoader().then(() => {
            if (cancelled || !containerRef.current) return;

            window.require.config({ paths: { vs: `/js/monaco-editor-${MONACO_VERSION}/min/vs` } });

            workerBlobUrl = URL.createObjectURL(
                new Blob(
                    [
                        `self.MonacoEnvironment={baseUrl:'${window.location.origin}/js/monaco-editor-${MONACO_VERSION}/min'};importScripts('${window.location.origin}/js/monaco-editor-${MONACO_VERSION}/min/vs/base/worker/workerMain.js');`,
                    ],
                    { type: 'text/javascript' },
                ),
            );
            window.MonacoEnvironment = { getWorkerUrl: () => workerBlobUrl };

            window.require(['vs/editor/editor.main'], () => {
                if (cancelled || !containerRef.current) return;

                const isDark = document.documentElement.classList.contains('dark');
                const editor = window.monaco.editor.create(containerRef.current, {
                    value: value ?? '',
                    theme: isDark ? 'vs-dark' : 'vs',
                    wordWrap: 'on',
                    readOnly,
                    minimap: { enabled: false },
                    fontSize: 15,
                    lineNumbersMinChars: 3,
                    automaticLayout: true,
                    language,
                    domReadOnly: readOnly,
                    contextmenu: !readOnly,
                    renderLineHighlight: readOnly ? 'none' : 'all',
                    stickyScroll: { enabled: false },
                });

                editor.onDidChangeModelContent(() => {
                    onChangeRef.current?.(editor.getValue());
                });

                observer = new MutationObserver((mutations) => {
                    mutations.forEach((mutation) => {
                        if (mutation.attributeName === 'class') {
                            const nowDark = document.documentElement.classList.contains('dark');
                            window.monaco.editor.setTheme(nowDark ? 'vs-dark' : 'vs');
                        }
                    });
                });
                observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

                editorRef.current = editor;
            });
        });

        return () => {
            cancelled = true;
            observer?.disconnect();
            editorRef.current?.dispose();
            editorRef.current = null;
            if (workerBlobUrl) {
                URL.revokeObjectURL(workerBlobUrl);
            }
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (editorRef.current && editorRef.current.getValue() !== value) {
            editorRef.current.setValue(value ?? '');
        }
    }, [value]);

    return <div ref={containerRef} className="w-full" style={{ height, minHeight: '300px' }} />;
}
