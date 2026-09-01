import { useState } from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import { Bookmark, CalendarDays, House, User } from 'lucide-react';
import { Button } from '@/Components/ui/Button';
import { cn } from '@/lib/utils';
import { clearRecentSearches } from '@/lib/recentSearches';

/** Primary destinations. On phones these become the bottom tab bar. */
const navLinks = [
    { href: '/dashboard', label: 'Acasă', Icon: House },
    { href: '/events', label: 'Evenimente', Icon: CalendarDays },
    { href: '/events/saved', label: 'Salvate', Icon: Bookmark },
    { href: '/profile', label: 'Profil', Icon: User },
];

/**
 * Everything that is not a primary destination. On phones the bottom bar owns
 * the primary links, so the hamburger drawer carries only these.
 */
const secondaryLinks = [{ href: '/settings/notifications', label: 'Setări notificări' }];

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

    // Event browsing is public, so this layout also renders for guests: they get
    // the one nav link they can actually use, and auth CTAs in place of the
    // account menu.
    const isGuest = !auth?.user;

    const visibleLinks = isGuest
        ? navLinks.filter((link) => link.href === '/events')
        : auth?.isAdmin
          ? [...navLinks, { href: '/admin', label: 'Admin' }]
          : navLinks;

    const handleLogout = () => {
        // Recent searches live in localStorage, which outlives the session. On
        // a shared browser one person's search terms would otherwise be sitting
        // in the box for whoever signs in next.
        clearRecentSearches();
        router.post('/logout');
    };

    /** A link is active on an exact match, or when the path sits beneath it. */
    const isActive = (href) =>
        currentPath === href ||
        (href !== '/dashboard' && currentPath.startsWith(href));

    const drawerLinks = isGuest
        ? visibleLinks
        : [
              ...secondaryLinks,
              ...(auth?.isAdmin ? [{ href: '/admin', label: 'Admin' }] : []),
          ];

    return (
        <div className="min-h-screen bg-[#F8F9FA]">
            <nav className="bg-[#0A1128] border-b border-white/10">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div className="flex justify-between h-16">
                        {/* Left side: logo + nav links */}
                        <div className="flex">
                            <div className="flex-shrink-0 flex items-center">
                                <Link href={isGuest ? '/' : '/dashboard'}>
                                    <img
                                        src="/images/logo-dark.png"
                                        alt="Ghes"
                                        className="h-9 w-9 rounded-lg"
                                    />
                                </Link>
                            </div>
                            <div className="hidden sm:ml-8 sm:flex sm:space-x-2">
                                {visibleLinks.map((link) => (
                                    <Link
                                        key={link.href}
                                        href={link.href}
                                        className={cn(
                                            'inline-flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors',
                                            isActive(link.href)
                                                ? 'text-[#FF5733] bg-white/10'
                                                : 'text-white/70 hover:text-white hover:bg-white/10'
                                        )}
                                    >
                                        {link.label}
                                    </Link>
                                ))}
                            </div>
                        </div>

                        {/* Right side: account menu, or auth CTAs for guests */}
                        <div className="hidden sm:flex sm:items-center">
                            {isGuest ? (
                                <div className="flex items-center gap-2">
                                    <Link
                                        href="/login"
                                        className="inline-flex min-h-11 items-center px-3 py-2 text-sm font-medium text-white/70 hover:text-white sm:min-h-0"
                                    >
                                        Intră în cont
                                    </Link>
                                    <Link
                                        href="/register"
                                        className="inline-flex min-h-11 items-center rounded-full bg-[#FF5733] px-4 py-2 text-sm font-semibold text-white transition-opacity hover:opacity-90 sm:min-h-0"
                                    >
                                        Înregistrează-te
                                    </Link>
                                </div>
                            ) : (
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
                            )}
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
                            {drawerLinks.map((link) => (
                                <Link
                                    key={link.href}
                                    href={link.href}
                                    onClick={() => setMobileMenuOpen(false)}
                                    className={cn(
                                        'block px-3 py-3 rounded-md text-base font-medium',
                                        isActive(link.href)
                                            ? 'text-[#FF5733] bg-white/10'
                                            : 'text-white/70 hover:text-white hover:bg-white/10'
                                    )}
                                >
                                    {link.label}
                                </Link>
                            ))}
                        </div>
                        <div className="border-t border-white/10 px-4 py-3">
                            {isGuest ? (
                                <div className="flex flex-col gap-2">
                                    <Link
                                        href="/login"
                                        onClick={() => setMobileMenuOpen(false)}
                                        className="flex min-h-11 items-center rounded-md px-2 text-base text-white/70 hover:text-white"
                                    >
                                        Intră în cont
                                    </Link>
                                    <Link
                                        href="/register"
                                        onClick={() => setMobileMenuOpen(false)}
                                        className="flex min-h-11 items-center rounded-md px-2 text-base font-semibold text-[#FF5733]"
                                    >
                                        Înregistrează-te
                                    </Link>
                                </div>
                            ) : (
                                <>
                                    <p className="text-sm font-medium text-white">
                                        {auth?.user?.name || 'Cont'}
                                    </p>
                                    <p className="text-xs text-white/50">
                                        {auth?.user?.email || ''}
                                    </p>
                                    <button
                                        type="button"
                                        onClick={handleLogout}
                                        className="mt-3 flex min-h-11 w-full items-center rounded-md px-2 text-base font-medium text-[#FF5733] hover:bg-white/10"
                                    >
                                        Deconectare
                                    </button>
                                </>
                            )}
                        </div>
                    </div>
                )}
            </nav>

            {/* Page content */}
            <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 pb-28 sm:pb-8">
                {title && (
                    <h1 className="text-2xl font-bold text-[#0A1128] mb-6">
                        {title}
                    </h1>
                )}
                {children}
            </main>

            {/*
              Bottom tab bar — primary navigation on phones, where the top nav
              scrolls away. Signed-in users only: a guest has just one primary
              destination, so a tab bar would be noise.
            */}
            {!isGuest && (
                <nav
                    aria-label="Navigare principală"
                    className="fixed inset-x-0 bottom-0 z-40 border-t border-white/10 bg-[#0A1128] pb-[env(safe-area-inset-bottom)] sm:hidden"
                >
                    <div className="grid grid-cols-4">
                        {navLinks.map(({ href, label, Icon }) => (
                            <Link
                                key={href}
                                href={href}
                                aria-current={isActive(href) ? 'page' : undefined}
                                className={cn(
                                    'flex flex-col items-center justify-center gap-1 py-2.5 text-[11px] font-medium transition-colors',
                                    isActive(href)
                                        ? 'text-[#FF5733]'
                                        : 'text-white/60 hover:text-white'
                                )}
                            >
                                <Icon className="h-5 w-5" aria-hidden="true" />
                                {label}
                            </Link>
                        ))}
                    </div>
                </nav>
            )}
        </div>
    );
}
