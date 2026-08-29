import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/Components/ui/Card';

export default function Register() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post('/register');
    };

    return (
        <>
            <Head title="Înregistrare — Ghes" />
            <div
                className="min-h-[100dvh] flex flex-col items-center justify-center px-4 py-8"
                style={{ backgroundColor: '#0A1128' }}
            >
                <Link href="/" className="mb-8">
                    <img
                        src="/images/logo-dark.png"
                        alt="Ghes"
                        className="h-14 w-auto"
                    />
                </Link>
                <Card className="w-full max-w-md border-0 shadow-2xl">
                    <CardHeader className="text-center">
                        <CardTitle className="text-2xl" style={{ fontFamily: 'Montserrat, sans-serif' }}>
                            Creează-ți contul
                        </CardTitle>
                        <CardDescription>
                            Alătură-te și descoperă ce se întâmplă în oraș
                        </CardDescription>
                    </CardHeader>
                    <form onSubmit={handleSubmit}>
                        <CardContent className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="name">Nume</Label>
                                <Input
                                    id="name"
                                    type="text"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    placeholder="Numele tău"
                                    autoComplete="name"
                                    required
                                />
                                {errors.name && (
                                    <p className="text-sm text-red-600">{errors.name}</p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="email">Email</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    value={data.email}
                                    onChange={(e) => setData('email', e.target.value)}
                                    placeholder="tu@exemplu.com"
                                    autoComplete="email"
                                    required
                                />
                                {errors.email && (
                                    <p className="text-sm text-red-600">{errors.email}</p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="password">Parolă</Label>
                                <Input
                                    id="password"
                                    type="password"
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                    placeholder="Creează o parolă"
                                    autoComplete="new-password"
                                    required
                                />
                                {errors.password && (
                                    <p className="text-sm text-red-600">
                                        {errors.password}
                                    </p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="password_confirmation">
                                    Confirmă parola
                                </Label>
                                <Input
                                    id="password_confirmation"
                                    type="password"
                                    value={data.password_confirmation}
                                    onChange={(e) =>
                                        setData('password_confirmation', e.target.value)
                                    }
                                    placeholder="Confirmă parola"
                                    autoComplete="new-password"
                                    required
                                />
                                {errors.password_confirmation && (
                                    <p className="text-sm text-red-600">
                                        {errors.password_confirmation}
                                    </p>
                                )}
                            </div>
                        </CardContent>
                        <CardFooter className="flex flex-col gap-4">
                            <Button
                                type="submit"
                                className="w-full font-semibold"
                                style={{ backgroundColor: '#FF5733', color: '#fff' }}
                                disabled={processing}
                            >
                                {processing ? 'Se creează contul...' : 'Creează cont'}
                            </Button>
                            <div className="flex items-center w-full gap-3 text-xs text-gray-400">
                                <span className="flex-1 border-t border-gray-200" />
                                sau
                                <span className="flex-1 border-t border-gray-200" />
                            </div>
                            <a
                                href="/auth/google/redirect"
                                className="w-full inline-flex min-h-11 sm:min-h-10 items-center justify-center gap-2 rounded-md border border-gray-300 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                            >
                                <img src="https://www.google.com/favicon.ico" alt="" className="h-4 w-4" />
                                Continuă cu Google
                            </a>
                            <p className="text-sm text-gray-500 text-center">
                                Ai deja cont?{' '}
                                <Link
                                    href="/login"
                                    className="font-medium hover:underline"
                                    style={{ color: '#FF5733' }}
                                >
                                    Intră în cont
                                </Link>
                            </p>
                        </CardFooter>
                    </form>
                </Card>
            </div>
        </>
    );
}
