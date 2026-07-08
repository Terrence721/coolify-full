import { useForm } from '@inertiajs/react';

export default function ForcePasswordReset({ email, updateUrl }) {
    const { data, setData, put, processing, errors } = useForm({
        password: '',
        password_confirmation: '',
    });

    function submit(e) {
        e.preventDefault();
        put(updateUrl);
    }

    return (
        <section className="bg-gray-50 dark:bg-base">
            <div className="flex flex-col items-center justify-center px-6 py-8 mx-auto md:h-screen lg:py-0">
                <a className="flex items-center mb-6 text-5xl font-extrabold tracking-tight text-gray-900 dark:text-white">
                    Coolify
                </a>
                <div className="w-full bg-white shadow-sm md:mt-0 sm:max-w-md xl:p-0 dark:bg-base">
                    <div className="p-6 space-y-4 md:space-y-6 sm:p-8">
                        <form onSubmit={submit} className="flex flex-col gap-2">
                            <label className="flex flex-col gap-1">
                                Email
                                <input type="email" readOnly value={email} />
                            </label>
                            <label className="flex flex-col gap-1">
                                New Password
                                <input
                                    type="password"
                                    placeholder="New Password"
                                    required
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                />
                                {errors.password && <span className="text-error">{errors.password}</span>}
                            </label>
                            <label className="flex flex-col gap-1">
                                Confirm New Password
                                <input
                                    type="password"
                                    placeholder="Confirm New Password"
                                    required
                                    value={data.password_confirmation}
                                    onChange={(e) => setData('password_confirmation', e.target.value)}
                                />
                            </label>
                            <button type="submit" disabled={processing}>
                                Reset Password
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </section>
    );
}

// Original Livewire component used ->layout('layouts.simple') - a bare page with no app
// shell/navbar. Opt out of the default AppLayout wrapper (see resources/js/inertia-app.jsx)
// to match.
ForcePasswordReset.layout = (page) => page;
