import { useState, useCallback } from 'react';
import { Head, router } from '@inertiajs/react';
import ChatWindow from '@/Components/Chat/ChatWindow';
import ProfilePreviewCard from '@/Components/Chat/ProfilePreviewCard';
import { Button } from '@/Components/ui/Button';
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
        try {
            const res = await fetch('/onboarding/confirm-profile', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
            });

            const data = await res.json();

            if (data.success) {
                setProfile(data.profile);
                setTimeout(() => {
                    router.visit(data.redirectTo || '/');
                }, 2000);
            }
        } catch {
            // ignore
        } finally {
            setIsConfirming(false);
        }
    }, [csrfToken]);

    return (
        <>
            <Head title="Bun venit la Ghes" />
            <div className="min-h-screen bg-gray-50 flex flex-col">
                {/* Header */}
                <div className="bg-white border-b border-gray-200 px-4 py-4">
                    <div className="max-w-2xl mx-auto flex items-center justify-between">
                        <div>
                            <h1 className="text-xl font-bold text-indigo-600">
                                Ghes
                            </h1>
                            <p className="text-sm text-gray-500">
                                Spune-ne ce te interesează
                            </p>
                        </div>
                        {isComplete && !profile && (
                            <Button
                                onClick={handleConfirmProfile}
                                disabled={isConfirming}
                                className="bg-green-600 hover:bg-green-700"
                            >
                                {isConfirming
                                    ? 'Se generează profilul...'
                                    : 'Confirmă și continuă'}
                            </Button>
                        )}
                    </div>
                </div>

                {/* Chat area */}
                <div className="flex-1 max-w-2xl mx-auto w-full flex flex-col">
                    <ChatWindow messages={messages} isTyping={isTyping} />

                    {/* Profile preview after confirmation */}
                    {profile && (
                        <div className="px-4 pb-4">
                            <ProfilePreviewCard profile={profile} />
                            <p className="text-center text-sm text-gray-500 mt-2">
                                Redirecționare către tabloul de bord...
                            </p>
                        </div>
                    )}

                    {/* Input area */}
                    <div className="border-t border-gray-200 bg-white p-4">
                        <form
                            onSubmit={handleSubmit}
                            className="flex items-center gap-2"
                        >
                            <Input
                                value={input}
                                onChange={(e) => setInput(e.target.value)}
                                placeholder={
                                    isComplete
                                        ? 'Adaugă detalii sau apasă Confirmă mai sus...'
                                        : 'Scrie mesajul tău...'
                                }
                                disabled={isSending || !!profile}
                                className="flex-1"
                                autoFocus
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
        </>
    );
}
