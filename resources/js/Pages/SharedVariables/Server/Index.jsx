export default function Index({ servers }) {
    return (
        <div>
            <div className="flex gap-2">
                <h1>Servers</h1>
            </div>
            <div className="subtitle">List of your servers.</div>
            <div className="flex flex-col gap-2">
                {servers.length === 0 && (
                    <div>
                        <div>No server found.</div>
                    </div>
                )}
                {servers.map((server) => (
                    <a key={server.href} className="coolbox group" href={server.href}>
                        <div className="flex flex-col justify-center mx-6">
                            <div className="box-title">{server.name}</div>
                            <div className="box-description">{server.description}</div>
                        </div>
                    </a>
                ))}
            </div>
        </div>
    );
}
