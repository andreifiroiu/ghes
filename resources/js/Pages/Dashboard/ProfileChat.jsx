import { useState, useCallback } from 'react';
import { Head, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import ChatWindow from '@/Components/Chat/ChatWindow';
import ProfilePreviewCard from '@/Components/Chat/ProfilePreviewCard';
import { Button } from '@/Components/ui/Button';
import { Input } from '@/Components/ui/Input';

/**
 * Ongoing profile-update chat: the user describes changes ("I'm into pottery
 * now", "stop networking events") and applies them to their interest profile.
 *
 * @param {Object} props
 * @param {Array<{id: string, role: string, content: string, created_at: string}>} props.messages
 */
export default function ProfileChat({ messages: initialMessages = [] }) {
    const [messages, setMessages] = useState(initialMessages);
    const [input, setInput] = useState('');
    const [isTyping, setIsTyping] = useState(false);
    const [isSending, setIsSending] = useState(false);
    const [isApplying, setIsApplying] = useState(false);
    const [profile, setProfile] = useState(null);
    const [error, setError] = useState(null);

    const csrfToken = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content');

    const postJson = useCallback(
        (url, body) =>
            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: body ? JSON.stringify(body) : undefined,
            }),
        [csrfToken],
    );

    const handleSubmit = useCallback(
        async (e) => {
            e.preventDefault();
            const text = input.trim();
            if (!text || isSending) return;

            setInput('');
            setIsSending(true);
            setIsTyping(true);

            try {
                const res = await postJson('/profile/chat', { message: text });
                if (!res.ok) throw new Error('Request failed');
                const data = await res.json();
                setMessages((prev) => [
                    ...prev,
                    data.userMessage,
                    data.assistantMessage,
                ]);
            } catch {
                setMessages((prev) => [
                    ...prev,
                    {
                        id: `err-${Date.now()}`,
                        role: 'assistant',
                        content: 'Ceva a mers greșit. Te rugăm să încerci din nou.',
                        created_at: new Date().toISOString(),
                    },
                ]);
            } finally {
                setIsTyping(false);
                setIsSending(false);
            }
        },
        [input, isSending, postJson],
    );

    const handleApply = useCallback(async () => {
        setIsApplying(true);
        setError(null);
        try {
            const res = await postJson('/profile/chat/apply');
            const data = await res.json();

            if (data.success) {
                setProfile(data.profile);
                setTimeout(() => {
                    router.visit(data.redirectTo || '/profile');
                }, 2000);
            } else {
                setError(data.message || 'Nu am putut aplica modificările.');
            }
        } catch {
            setError('Nu am putut aplica modificările.');
        } finally {
            setIsApplying(false);
        }
    }, [postJson]);

    return (
        <AppLayout title="Actualizează-ți preferințele">
            <Head title="Actualizează preferințele" />

            <div className="max-w-2xl mx-auto flex flex-col h-[70vh] bg-white border border-gray-200 rounded-lg overflow-hidden">
                <ChatWindow messages={messages} isTyping={isTyping} />

                {profile && (
                    <div className="px-4 pb-4">
                        <ProfilePreviewCard profile={profile} />
                        <p className="text-center text-sm text-gray-500 mt-2">
                            Preferințe actualizate. Redirecționare...
                        </p>
                    </div>
                )}

                {error && (
                    <p className="px-4 pb-2 text-sm text-red-600">{error}</p>
                )}

                <div className="border-t border-gray-200 bg-white p-4 space-y-3">
                    <form onSubmit={handleSubmit} className="flex items-center gap-2">
                        <Input
                            value={input}
                            onChange={(e) => setInput(e.target.value)}
                            placeholder="ex. „M-am apucat de ceramică” sau „nu mai vreau evenimente de networking”"
                            disabled={isSending || !!profile}
                            className="flex-1"
                            autoFocus
                        />
                        <Button
                            type="submit"
                            disabled={isSending || !input.trim() || !!profile}
                        >
                            Trimite
                        </Button>
                    </form>

                    <Button
                        type="button"
                        onClick={handleApply}
                        disabled={isApplying || !!profile}
                        className="w-full bg-[#FF5733] hover:bg-[#e04a2b]"
                    >
                        {isApplying
                            ? 'Se aplică modificările...'
                            : 'Aplică modificările în profil'}
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}
