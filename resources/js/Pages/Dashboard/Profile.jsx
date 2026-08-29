import { Head, useForm, router, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/Components/ui/Card';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Select } from '@/Components/ui/Select';
import CategoryBadge from '@/Components/Events/CategoryBadge';
import { cn } from '@/lib/utils';

const channelOptions = [
    { value: 'email', label: 'Email' },
    { value: 'push', label: 'Notificări push' },
    { value: 'both', label: 'Email și push' },
];

const frequencyOptions = [
    { value: 'realtime', label: 'În timp real' },
    { value: 'daily', label: 'Digest zilnic' },
    { value: 'weekly', label: 'Digest săptămânal' },
];

/**
 * @param {Object} props
 * @param {Object} props.user
 * @param {string} props.user.name
 * @param {string} props.user.email
 * @param {Object} [props.user.interest_profile]
 * @param {Object} [props.user.interest_profile.categories] - e.g. { Music: 0.8, Tech: 0.6 }
 * @param {number} [props.user.interest_profile.discovery_openness] - 0.0 to 1.0
 * @param {Array<string>} [props.user.interest_profile.tags] - free-form interest tags
 * @param {string} [props.user.notification_channel]
 * @param {string} [props.user.notification_frequency]
 * @param {string|null} [props.user.email_verified_at]
 */
export default function Profile({ user }) {
    const profile = user?.interest_profile || {};
    const categories = profile.categories || {};
    const discoveryOpenness = user?.discovery_openness ?? 0.5;
    const tags = profile.tags || [];
    const isEmailVerified = !!user?.email_verified_at;

    const {
        data: accountData,
        setData: setAccountData,
        put: putAccount,
        processing: accountProcessing,
        recentlySuccessful: accountSaved,
        errors: accountErrors,
    } = useForm({
        name: user?.name || '',
        email: user?.email || '',
    });

    const handleAccountSubmit = (e) => {
        e.preventDefault();
        putAccount('/profile');
    };

    const handleResendVerification = () => {
        router.post('/profile/resend-verification');
    };

    const { data, setData, put, processing, recentlySuccessful } = useForm({
        channel: user?.notification_channel || 'email',
        frequency: user?.notification_frequency || 'daily',
        discovery_openness: discoveryOpenness,
    });

    const handlePreferencesSubmit = (e) => {
        e.preventDefault();
        put('/settings/notifications');
    };

    const sortedCategories = Object.entries(categories).sort(
        ([, a], [, b]) => b - a
    );

    return (
        <AppLayout title="Profilul meu">
            <Head title="Profil" />

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* Account */}
                <div className="lg:col-span-1">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-lg">Cont</CardTitle>
                        </CardHeader>
                        <form onSubmit={handleAccountSubmit}>
                            <CardContent className="space-y-4">
                                <div className="space-y-1">
                                    <Label htmlFor="name">Nume</Label>
                                    <Input
                                        id="name"
                                        value={accountData.name}
                                        onChange={(e) => setAccountData('name', e.target.value)}
                                    />
                                    {accountErrors.name && (
                                        <p className="text-sm text-red-600">{accountErrors.name}</p>
                                    )}
                                </div>
                                <div className="space-y-1">
                                    <Label htmlFor="email">Adresă de email</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        value={accountData.email}
                                        onChange={(e) => setAccountData('email', e.target.value)}
                                    />
                                    {accountErrors.email && (
                                        <p className="text-sm text-red-600">{accountErrors.email}</p>
                                    )}
                                    <div className="flex items-center gap-2 pt-1">
                                        {isEmailVerified ? (
                                            <span className="inline-flex items-center gap-1 text-xs font-medium text-green-700">
                                                <svg className="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                                                </svg>
                                                Verificat
                                            </span>
                                        ) : (
                                            <div className="flex items-center gap-2">
                                                <span className="text-xs text-amber-600 font-medium">Neverificat</span>
                                                <button
                                                    type="button"
                                                    onClick={handleResendVerification}
                                                    className="inline-flex min-h-11 items-center text-xs text-indigo-600 hover:underline sm:min-h-0"
                                                >
                                                    Retrimite emailul
                                                </button>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </CardContent>
                            <CardFooter className="flex items-center gap-4">
                                <Button type="submit" disabled={accountProcessing}>
                                    {accountProcessing ? 'Se salvează...' : 'Salvează'}
                                </Button>
                                {accountSaved && (
                                    <p className="text-sm text-green-600">Salvat.</p>
                                )}
                            </CardFooter>
                        </form>
                    </Card>
                </div>

                {/* Interest profile */}
                <div className="lg:col-span-2 space-y-6">
                    {/* Category scores */}
                    <Card>
                        <CardHeader className="flex flex-col items-start gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <CardTitle className="text-lg">
                                Categorii de interes
                            </CardTitle>
                            <Link
                                href="/profile/chat"
                                className="inline-flex min-h-11 items-center text-sm font-medium text-[#FF5733] hover:underline sm:min-h-0"
                            >
                                Actualizează prin chat
                            </Link>
                        </CardHeader>
                        <CardContent>
                            {sortedCategories.length === 0 ? (
                                <p className="text-sm text-gray-400">
                                    Niciun interes înregistrat încă. Finalizează onboarding-ul sau reacționează la evenimente pentru a-ți construi profilul.
                                </p>
                            ) : (
                                <div className="space-y-4">
                                    {sortedCategories.map(([category, score]) => (
                                        <div key={category}>
                                            <div className="flex items-center justify-between mb-1">
                                                <CategoryBadge category={category} />
                                                <span className="text-sm text-gray-500">
                                                    {Math.round(score * 100)}%
                                                </span>
                                            </div>
                                            <div className="w-full bg-gray-100 rounded-full h-2.5">
                                                <div
                                                    className={cn(
                                                        'h-2.5 rounded-full bg-indigo-500 transition-all duration-500'
                                                    )}
                                                    style={{
                                                        width: `${Math.round(score * 100)}%`,
                                                    }}
                                                />
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Interest tags */}
                    {tags.length > 0 && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-lg">
                                    Etichete de interes
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="flex flex-wrap gap-2">
                                    {tags.map((tag) => (
                                        <span
                                            key={tag}
                                            className="inline-flex items-center rounded-full bg-indigo-50 px-3 py-1 text-sm font-medium text-indigo-700"
                                        >
                                            {tag}
                                        </span>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* Preferences */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-lg">
                                Preferințe
                            </CardTitle>
                            <CardDescription>
                                Controlează experiența de descoperire și modul în care primești recomandări.
                            </CardDescription>
                        </CardHeader>
                        <form onSubmit={handlePreferencesSubmit}>
                            <CardContent className="space-y-6">
                                {/* Discovery openness */}
                                <div className="space-y-3">
                                    <Label className="text-base font-medium">
                                        Deschidere spre descoperire
                                    </Label>
                                    <p className="text-sm text-gray-500">
                                        Cât de deschis ești să primești evenimente în afara intereselor obișnuite.
                                    </p>
                                    <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-4">
                                        <span className="hidden text-sm text-gray-400 shrink-0 sm:inline">
                                            Concentrat
                                        </span>
                                        <input
                                            type="range"
                                            min="0"
                                            max="100"
                                            step="5"
                                            aria-label="Deschidere spre descoperire"
                                            value={Math.round(data.discovery_openness * 100)}
                                            onChange={(e) =>
                                                setData('discovery_openness', parseInt(e.target.value) / 100)
                                            }
                                            className="w-full flex-1 accent-indigo-600"
                                        />
                                        <span className="hidden text-sm text-gray-400 shrink-0 sm:inline">
                                            Aventuros
                                        </span>
                                        <div className="flex justify-between text-sm text-gray-400 sm:hidden">
                                            <span>Concentrat</span>
                                            <span>Aventuros</span>
                                        </div>
                                    </div>
                                    <p className="text-center text-sm font-medium text-gray-700">
                                        {Math.round(data.discovery_openness * 100)}%
                                    </p>
                                </div>

                                <div className="space-y-3">
                                    <Label className="text-base font-medium">
                                        Canal de notificare
                                    </Label>
                                    <div className="space-y-2">
                                        {channelOptions.map((option) => (
                                            <label
                                                key={option.value}
                                                className={cn(
                                                    'flex items-center gap-3 p-3 rounded-lg border cursor-pointer transition-colors',
                                                    data.channel === option.value
                                                        ? 'border-indigo-500 bg-indigo-50'
                                                        : 'border-gray-200 hover:bg-gray-50'
                                                )}
                                            >
                                                <input
                                                    type="radio"
                                                    name="channel"
                                                    value={option.value}
                                                    checked={data.channel === option.value}
                                                    onChange={(e) =>
                                                        setData('channel', e.target.value)
                                                    }
                                                    className="h-4 w-4 text-indigo-600 focus:ring-indigo-500"
                                                />
                                                <span className="text-sm font-medium text-gray-900">
                                                    {option.label}
                                                </span>
                                            </label>
                                        ))}
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="frequency" className="text-base font-medium">
                                        Frecvență
                                    </Label>
                                    <Select
                                        id="frequency"
                                        value={data.frequency}
                                        onChange={(e) =>
                                            setData('frequency', e.target.value)
                                        }
                                    >
                                        {frequencyOptions.map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </Select>
                                </div>
                            </CardContent>
                            <CardFooter className="flex items-center gap-4">
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Se salvează...' : 'Salvează preferințele'}
                                </Button>
                                {recentlySuccessful && (
                                    <p className="text-sm text-green-600">
                                        Salvat cu succes.
                                    </p>
                                )}
                            </CardFooter>
                        </form>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
