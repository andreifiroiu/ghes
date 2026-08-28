import { Link, usePage, router } from '@inertiajs/react';
import { cn } from '@/lib/utils';

const navLinks = [
    { href: '/admin', label: 'Dashboard', exact: true },
    { href: '/admin/events', label: 'Events' },
    { href: '/admin/users', label: 'Users' },
    { href: '/admin/scrapers', label: 'Scrapers' },
];

/**
 * @param {Object} props
 * @param {React.ReactNode} props.children
 * @param {string} [props.title]
 */
export default function AdminLayout({ children, title }) {
    const currentPath = usePage().url;
    const flash = usePage().props.flash || {};

    const isActive = (link) =>
        link.exact ? currentPath === link.href : currentPath.startsWith(link.href);

    return (
        <div className="min-h-screen bg-[#F8F9FA]">
            <nav className="bg-[#0A1128] border-b border-white/10">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between h-16">
                        <div className="flex items-center gap-6">
                            <span className="text-white font-bold">Ghes Admin</span>
                            <div className="hidden sm:flex gap-1">
                                {navLinks.map((link) => (
                                    <Link
                                        key={link.href}
                                        href={link.href}
                                        className={cn(
                                            'px-3 py-2 text-sm font-medium rounded-md transition-colors',
                                            isActive(link)
                                                ? 'text-[#FF5733] bg-white/10'
                                                : 'text-white/70 hover:text-white hover:bg-white/10'
                                        )}
                                    >
                                        {link.label}
                                    </Link>
                                ))}
                            </div>
                        </div>
                        <button
                            onClick={() => router.visit('/dashboard')}
                            className="text-sm text-white/70 hover:text-white"
                        >
                            ← Back to app
                        </button>
                    </div>
                </div>
            </nav>

            <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                {title && (
                    <h1 className="text-2xl font-bold text-[#0A1128] mb-6">{title}</h1>
                )}
                {flash.success && (
                    <div className="mb-4 rounded-md bg-green-50 border border-green-200 px-4 py-2 text-sm text-green-700">
                        {flash.success}
                    </div>
                )}
                {flash.error && (
                    <div className="mb-4 rounded-md bg-red-50 border border-red-200 px-4 py-2 text-sm text-red-700">
                        {flash.error}
                    </div>
                )}
                {children}
            </main>
        </div>
    );
}
