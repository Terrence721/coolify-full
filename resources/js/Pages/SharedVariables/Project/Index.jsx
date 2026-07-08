export default function Index({ projects }) {
    return (
        <div>
            <div className="flex gap-2">
                <h1>Projects</h1>
            </div>
            <div className="subtitle">List of your projects.</div>
            <div className="flex flex-col gap-2">
                {projects.length === 0 && (
                    <div>
                        <div>No project found.</div>
                    </div>
                )}
                {projects.map((project) => (
                    <a key={project.href} className="coolbox group" href={project.href}>
                        <div className="flex flex-col justify-center mx-6">
                            <div className="box-title">{project.name}</div>
                            <div className="box-description">{project.description}</div>
                        </div>
                    </a>
                ))}
            </div>
        </div>
    );
}
