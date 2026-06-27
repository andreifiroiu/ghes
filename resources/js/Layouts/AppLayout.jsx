import { useState } from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import { Button } from '@/Components/ui/Button';
import { cn } from '@/lib/utils';

const navLinks = [
    { href: '/dashboard', label: 'Acasă' },
    { href: '/events', label: 'Evenimente' },
    { href: '/events/saved', label: 'Salvate' },
    { href: '/profile', label: 'Profil' },
];

/**
 * @param {Object} props
 * @param {React.ReactNode} props.children
 * @param {string} [props.title]
 */
export default function AppLayout({ children, title }) {
    const { auth } = usePage().props;
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const [userMenuOpen, setUserMenuOpen] = useState(false);

    const currentPath = usePage().url;

    const handleLogout = () => {
        router.post('/logout');
    };

    return (
        <div className="min-h-screen bg-[#F8F9FA]">
            <nav className="bg-[#0A1128] border-b border-white/10">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div className="flex justify-between h-16">
                        {/* Left side: logo + nav links */}
                        <div className="flex">
                            <div className="flex-shrink-0 flex items-center">
                                <Link href="/dashboard">
                                    <img
                                        src="/images/logo-dark.png"
                                        alt="Ghes"
                                        className="h-9 w-auto"
                                    />
                                </Link>
                            </div>
                            <div className="hidden sm:ml-8 sm:flex sm:space-x-2">
                                {navLinks.map((link) => (
                                    <Link
                                        key={link.href}
                                        href={link.href}
                                        className={cn(
                                            'inline-flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors',
                                            currentPath === link.href ||
                                                (link.href !== '/dashboard' &&
                                                    currentPath.startsWith(link.href))
                                                ? 'text-[#FF5733] bg-white/10'
                                                : 'text-white/70 hover:text-white hover:bg-white/10'
                                        )}
                                    >
                                        {link.label}
                                    </Link>
                                ))}
                            </div>
                        </div>

                        {/* Right side: user menu */}
                        <div className="hidden sm:flex sm:items-center">
                            <div className="relative">
                                <Button
                                    variant="ghost"
                                    onClick={() => setUserMenuOpen(!userMenuOpen)}
                                    className="flex items-center gap-2 text-white/80 hover:text-white hover:bg-white/10"
                                >
                                    <span className="text-sm">
                                        {auth?.user?.name || 'Cont'}
                                    </span>
                                    <svg
                                        className={cn(
                                            'w-4 h-4 transition-transform',
                                            userMenuOpen && 'rotate-180'
                                        )}
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            strokeWidth={2}
                                            d="M19 9l-7 7-7-7"
                                        />
                                    </svg>
                                </Button>
                                {userMenuOpen && (
                                    <div className="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg border border-gray-200 py-1 z-50">
                                        <Link
                                            href="/profile"
                                            className="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"
                                            onClick={() => setUserMenuOpen(false)}
                                        >
                                            Profilul meu
                                        </Link>
                                        <hr className="my-1 border-gray-100" />
                                        <button
                                            onClick={handleLogout}
                                            className="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"
                                        >
                                            Deconectare
                                        </button>
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Mobile hamburger */}
                        <div className="flex items-center sm:hidden">
                            <Button
                                variant="ghost"
                                size="icon"
                                onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
                                className="text-white"
                            >
                                {mobileMenuOpen ? (
                                    <svg
                                        className="w-6 h-6"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            strokeWidth={2}
                                            d="M6 18L18 6M6 6l12 12"
                                        />
                                    </svg>
                                ) : (
                                    <svg
                                        className="w-6 h-6"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            strokeWidth={2}
                                            d="M4 6h16M4 12h16M4 18h16"
                                        />
                                    </svg>
                                )}
                            </Button>
                        </div>
                    </div>
                </div>

                {/* Mobile menu */}
                {mobileMenuOpen && (
                    <div className="sm:hidden border-t border-white/10">
                        <div className="px-2 pt-2 pb-3 space-y-1">
                            {navLinks.map((link) => (
                                <Link
                                    key={link.href}
                                    href={link.href}
                                    onClick={() => setMobileMenuOpen(false)}
                                    className={cn(
                                        'block px-3 py-2 rounded-md text-base font-medium',
                                        currentPath === link.href ||
                                            (link.href !== '/dashboard' &&
                                                currentPath.startsWith(link.href))
                                            ? 'text-[#FF5733] bg-white/10'
                                            : 'text-white/70 hover:text-white hover:bg-white/10'
                                    )}
                                >
                                    {link.label}
                                </Link>
                            ))}
                        </div>
                        <div className="border-t border-white/10 px-4 py-3">
                            <p className="text-sm font-medium text-white">
                                {auth?.user?.name || 'Cont'}
                            </p>
                            <p className="text-xs text-white/50">
                                {auth?.user?.email || ''}
                            </p>
                            <button
                                onClick={handleLogout}
                                className="mt-2 block text-sm text-[#FF5733] hover:text-red-400"
                            >
                                Logout
                            </button>
                        </div>
                    </div>
                )}
            </nav>

            {/* Page content */}
            <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                {title && (
                    <h1 className="text-2xl font-bold text-[#0A1128] mb-6">
                        {title}
                    </h1>
                )}
                {children}
            </main>
        </div>
    );
}
