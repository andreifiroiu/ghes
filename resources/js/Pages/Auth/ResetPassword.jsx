import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';
import { Label } from '@/Components/ui/Label';
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/Components/ui/Card';

/**
 * Choose a new password from the link in the reset mail.
 *
 * @param {object} props
 * @param {string} props.token The reset token from the mailed link
 * @param {string} props.email The address the link was sent to
 */
export default function ResetPassword({ token, email }) {
    const { data, setData, post, processing, errors } = useForm({
        token,
        email: email || '',
        password: '',
        password_confirmation: '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post('/reset-password');
    };

    return (
        <>
            <Head title="Resetează parola — Ghes" />
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
                            Alege o parolă nouă
                        </CardTitle>
                        <CardDescription>Cel puțin 8 caractere.</CardDescription>
                    </CardHeader>
                    <form onSubmit={handleSubmit}>
                        <CardContent className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="email">Email</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    value={data.email}
                                    onChange={(e) => setData('email', e.target.value)}
                                    autoComplete="email"
                                    required
                                />
                                {errors.email && <p className="text-sm text-red-600">{errors.email}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="password">Parolă nouă</Label>
                                <Input
                                    id="password"
                                    type="password"
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                    autoComplete="new-password"
                                    required
                                />
                                {errors.password && <p className="text-sm text-red-600">{errors.password}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="password_confirmation">Confirmă parola</Label>
                                <Input
                                    id="password_confirmation"
                                    type="password"
                                    value={data.password_confirmation}
                                    onChange={(e) => setData('password_confirmation', e.target.value)}
                                    autoComplete="new-password"
                                    required
                                />
                                {errors.password_confirmation && (
                                    <p className="text-sm text-red-600">{errors.password_confirmation}</p>
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
                                {processing ? 'Se salvează...' : 'Salvează parola'}
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
