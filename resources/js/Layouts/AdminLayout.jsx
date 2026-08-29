import { useState } from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import { Menu, X } from 'lucide-react';
import { cn } from '@/lib/utils';

const navLinks = [
    { href: '/admin', label: 'Dashboard', exact: true },
    { href: '/admin/analytics', label: 'Analytics' },
    { href: '/admin/events', label: 'Events' },
    { href: '/admin/events/duplicates', label: 'Duplicates' },
    { href: '/admin/users', label: 'Users' },
    { href: '/admin/scrapers', label: 'Scrapers' },
];

/**
 * The most specific link matching the current path, so /admin/events/duplicates
 * highlights Duplicates rather than both it and Events.
 *
 * @param {string} path
 * @returns {string|null}
 */
function activeHref(path) {
    const matches = navLinks.filter((link) =>
        link.exact ? path === link.href : path.startsWith(link.href)
    );

    return matches.sort((a, b) => b.href.length - a.href.length)[0]?.href ?? null;
}

/**
 * @param {Object} props
 * @param {React.ReactNode} props.children
 * @param {string} [props.title]
 */
export default function AdminLayout({ children, title }) {
    const currentPath = usePage().url;
    const flash = usePage().props.flash || {};
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

    // usePage().url carries the query string; navigation matches on path only.
    const current = activeHref(currentPath.split('?')[0]);
    const isActive = (link) => link.href === current;

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
                        <div className="flex items-center gap-1">
                            <button
                                type="button"
                                onClick={() => router.visit('/dashboard')}
                                className="hidden sm:inline-flex min-h-11 items-center px-2 text-sm text-white/70 hover:text-white"
                            >
                                ← Back to app
                            </button>
                            <button
                                type="button"
                                aria-label={mobileMenuOpen ? 'Închide meniul' : 'Deschide meniul'}
                                aria-expanded={mobileMenuOpen}
                                onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
                                className="inline-flex h-11 w-11 items-center justify-center rounded-md text-white hover:bg-white/10 sm:hidden"
                            >
                                {mobileMenuOpen ? (
                                    <X className="h-6 w-6" aria-hidden="true" />
                                ) : (
                                    <Menu className="h-6 w-6" aria-hidden="true" />
                                )}
                            </button>
                        </div>
                    </div>
                </div>

                {/*
                  Mobile drawer. Without this the admin has no navigation at all
                  below `sm`. Mirrors the pattern in AppLayout.
                */}
                {mobileMenuOpen && (
                    <div className="sm:hidden border-t border-white/10">
                        <div className="px-2 pt-2 pb-3 space-y-1">
                            {navLinks.map((link) => (
                                <Link
                                    key={link.href}
                                    href={link.href}
                                    onClick={() => setMobileMenuOpen(false)}
                                    className={cn(
                                        'block px-3 py-3 rounded-md text-base font-medium',
                                        isActive(link)
                                            ? 'text-[#FF5733] bg-white/10'
                                            : 'text-white/70 hover:text-white hover:bg-white/10'
                                    )}
                                >
                                    {link.label}
                                </Link>
                            ))}
                        </div>
                        <div className="border-t border-white/10 px-2 py-2">
                            <button
                                type="button"
                                onClick={() => router.visit('/dashboard')}
                                className="flex min-h-11 w-full items-center rounded-md px-3 text-base text-white/70 hover:bg-white/10 hover:text-white"
                            >
                                ← Back to app
                            </button>
                        </div>
                    </div>
                )}
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
