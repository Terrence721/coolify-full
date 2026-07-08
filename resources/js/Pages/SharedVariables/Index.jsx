export default function Index({ links }) {
    return (
        <div>
            <div className="flex items-start gap-2">
                <h1>Shared Variables</h1>
            </div>
            <div className="subtitle">Set Team / Project / Environment / Server wide variables.</div>

            <div className="flex flex-col gap-2 -mt-1">
                {links.map((link) => (
                    <a key={link.href} className="coolbox group" href={link.href}>
                        <div className="flex flex-col justify-center mx-6">
                            <div className="box-title">{link.title}</div>
                            <div className="box-description">{link.description}</div>
                        </div>
                    </a>
                ))}
            </div>
        </div>
    );
}
