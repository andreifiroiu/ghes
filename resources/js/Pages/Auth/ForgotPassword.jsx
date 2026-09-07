import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/Components/ui/Card';

/**
 * Ask for a password reset link. The confirmation text is the same whether
 * or not the address has an account, so the page cannot be used to find out.
 */
export default function ForgotPassword() {
    const flash = usePage().props.flash || {};
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post('/forgot-password');
    };

    return (
        <>
            <Head title="Ai uitat parola — Ghes" />
            <div
                className="min-h-[100dvh] flex flex-col items-center justify-center px-4 py-8"
                style={{ backgroundColor: '#0A1128' }}
            >
                <Link href="/" className="mb-8">
                    <img src="/images/logo-dark.png" alt="Ghes" className="h-14 w-auto" />
                </Link>
                <Card className="w-full max-w-md border-0 shadow-2xl">
                    <CardHeader className="text-center">
                        <CardTitle className="text-2xl" style={{ fontFamily: 'Montserrat, sans-serif' }}>
                            Ai uitat parola?
                        </CardTitle>
                        <CardDescription>
                            Scrie adresa de email a contului și îți trimitem un link de resetare.
                        </CardDescription>
                    </CardHeader>
                    <form onSubmit={handleSubmit}>
                        <CardContent className="space-y-4">
                            {flash.success && (
                                <p className="rounded-md bg-green-50 px-3 py-2 text-sm text-green-800">
                                    {flash.success}
                                </p>
                            )}
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
                                {errors.email && <p className="text-sm text-red-600">{errors.email}</p>}
                            </div>
                        </CardContent>
                        <CardFooter className="flex flex-col gap-4">
                            <Button
                                type="submit"
                                className="w-full font-semibold"
                                style={{ backgroundColor: '#FF5733', color: '#fff' }}
                                disabled={processing}
                            >
                                {processing ? 'Se trimite...' : 'Trimite linkul'}
                            </Button>
                            <p className="text-sm text-gray-500 text-center">
                                <Link href="/login" className="font-medium hover:underline" style={{ color: '#FF5733' }}>
                                    Înapoi la autentificare
                                </Link>
                            </p>
                        </CardFooter>
                    </form>
                </Card>
            </div>
        </>
    );
}
