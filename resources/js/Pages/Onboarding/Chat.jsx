import { useState, useCallback } from 'react';
import { Head, router } from '@inertiajs/react';
import ChatWindow from '@/Components/Chat/ChatWindow';
import ProfilePreviewCard from '@/Components/Chat/ProfilePreviewCard';
import { Button } from '@/Components/ui/Button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/Dialog';
import { Input } from '@/Components/ui/Input';

/**
 * @param {Object} props
 * @param {Array<{id: string, role: string, content: string, created_at: string}>} props.messages
 * @param {boolean} props.onboardingComplete
 * @param {boolean} props.profileReady
 */
export default function Chat({
    messages: initialMessages = [],
    onboardingComplete = false,
    profileReady = false,
}) {
    const [messages, setMessages] = useState(initialMessages);
    const [input, setInput] = useState('');
    const [isTyping, setIsTyping] = useState(false);
    const [isSending, setIsSending] = useState(false);
    const [isComplete, setIsComplete] = useState(onboardingComplete);
    const [isConfirming, setIsConfirming] = useState(false);
    const [profile, setProfile] = useState(null);
    const [redirectTo, setRedirectTo] = useState('/dashboard');
    const [confirmError, setConfirmError] = useState(null);

    const csrfToken = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content');

    const handleSubmit = useCallback(
        async (e) => {
            e.preventDefault();
            const text = input.trim();
            if (!text || isSending) return;

            setInput('');
            setIsSending(true);
            setIsTyping(true);

            try {
                const res = await fetch('/onboarding/chat', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({ message: text }),
                });

                if (!res.ok) throw new Error('Request failed');

                const data = await res.json();

                setMessages((prev) => [
                    ...prev,
                    data.userMessage,
                    data.assistantMessage,
                ]);

                if (data.onboardingComplete) {
                    setIsComplete(true);
                }
            } catch {
                setMessages((prev) => [
                    ...prev,
                    {
                        id: `err-${Date.now()}`,
                        role: 'assistant',
                        content:
                            'Ceva a mers greșit. Te rugăm să încerci din nou.',
                        created_at: new Date().toISOString(),
                    },
                ]);
            } finally {
                setIsTyping(false);
                setIsSending(false);
            }
        },
        [input, isSending, csrfToken],
    );

    const handleConfirmProfile = useCallback(async () => {
        setIsConfirming(true);
        setConfirmError(null);
        try {
            const res = await fetch('/onboarding/confirm-profile', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
            });

            const data = await res.json().catch(() => null);

            if (res.ok && data?.success) {
                setProfile(data.profile);
                setRedirectTo(data.redirectTo || '/dashboard');
                return;
            }

            // A 401/419 means the chat outlived its session; anything else
            // carries a server-side reason worth repeating verbatim.
            setConfirmError(
                res.status === 401 || res.status === 419
                    ? 'Sesiunea a expirat. Reîncarcă pagina și încearcă din nou.'
                    : data?.message ||
                          'Nu am putut genera profilul. Încearcă din nou.',
            );
        } catch {
            setConfirmError(
                'Nu am putut lua legătura cu serverul. Verifică conexiunea și încearcă din nou.',
            );
        } finally {
            setIsConfirming(false);
        }
    }, [csrfToken]);

    return (
        <>
            <Head title="Bun venit la Ghes" />
            <div className="h-[100dvh] bg-gray-50 flex flex-col">
                {/* Header */}
                <div className="bg-white border-b border-gray-200 px-4 py-4">
                    <div className="max-w-2xl mx-auto flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h1 className="text-xl font-bold text-indigo-600">
                                Ghes
                            </h1>
                            <p className="text-sm text-gray-500">
                                Spune-ne ce te interesează
                            </p>
                        </div>
                    </div>
                </div>

                {/* Chat area */}
                <div className="flex-1 min-h-0 max-w-2xl mx-auto w-full flex flex-col">
                    <ChatWindow messages={messages} isTyping={isTyping} />

                    {/* Confirm call to action, sat right above the input so
                        it lands where the user is already looking. */}
                    {isComplete && !profile && (
                        <div
                            className={
                                confirmError
                                    ? 'border-t border-red-200 bg-red-50 px-4 py-3'
                                    : 'border-t border-green-200 bg-green-50 px-4 py-3'
                            }
                        >
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <p
                                    role={confirmError ? 'alert' : undefined}
                                    className={
                                        confirmError
                                            ? 'text-sm text-red-700'
                                            : 'text-sm text-green-800'
                                    }
                                >
                                    {confirmError || 'Gata — am înțeles ce-ți place.'}
                                </p>
                                <Button
                                    onClick={handleConfirmProfile}
                                    disabled={isConfirming}
                                    className="w-full bg-green-600 hover:bg-green-700 sm:w-auto"
                                >
                                    {isConfirming
                                        ? 'Se generează profilul...'
                                        : confirmError
                                          ? 'Încearcă din nou'
                                          : 'Confirmă și continuă'}
                                </Button>
                            </div>
                        </div>
                    )}

                    {/* Input area */}
                    <div className="border-t border-gray-200 bg-white p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]">
                        <form
                            onSubmit={handleSubmit}
                            className="flex items-center gap-2"
                        >
                            <Input
                                value={input}
                                onChange={(e) => setInput(e.target.value)}
                                placeholder={
                                    isComplete
                                        ? 'Adaugă detalii sau apasă Confirmă mai jos...'
                                        : 'Scrie mesajul tău...'
                                }
                                disabled={isSending || !!profile}
                                className="flex-1"
                            />
                            <Button
                                type="submit"
                                disabled={isSending || !input.trim() || !!profile}
                            >
                                <svg
                                    className="w-5 h-5"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth={2}
                                        d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"
                                    />
                                </svg>
                                <span className="sr-only sm:not-sr-only">
                                    Trimite
                                </span>
                            </Button>
                        </form>
                    </div>
                </div>
            </div>

            {/* The generated profile, acknowledged explicitly. The only way on
                is the button — dismissing it would strand the user on a chat
                that has nothing left to do. */}
            <Dialog open={!!profile}>
                <DialogContent
                    showCloseButton={false}
                    onInteractOutside={(e) => e.preventDefault()}
                    onEscapeKeyDown={(e) => e.preventDefault()}
                >
                    <DialogHeader>
                        <DialogTitle>Profilul tău e gata</DialogTitle>
                        <DialogDescription>
                            Pe baza asta îți alegem evenimentele. Îl poți
                            ajusta oricând din profil.
                        </DialogDescription>
                    </DialogHeader>
                    {profile && <ProfilePreviewCard profile={profile} />}
                    <DialogFooter>
                        <Button
                            onClick={() => router.visit(redirectTo)}
                            className="w-full bg-green-600 hover:bg-green-700 sm:w-auto"
                        >
                            Continuă spre tabloul de bord
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
