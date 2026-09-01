import { Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/Card';
import { formatDayMonth } from '@/lib/dates';

/**
 * What the onboarding chat concluded about the user, in their own terms.
 *
 * Written when the profile is generated, so an account that onboarded before
 * this existed has none until its next profile chat — which is why the empty
 * state points at that chat rather than apologising.
 *
 * @param {Object} props
 * @param {string|null} [props.summary]
 * @param {string|null} [props.updatedAt]
 */
export default function ChatSummaryCard({ summary, updatedAt }) {
    return (
        <Card>
            <CardHeader className="flex flex-col items-start gap-2 sm:flex-row sm:items-center sm:justify-between">
                <CardTitle className="text-lg">Ce ne-ai spus despre tine</CardTitle>
                {summary && updatedAt && (
                    <span className="text-xs text-gray-400">
                        Actualizat {formatDayMonth(updatedAt)}
                    </span>
                )}
            </CardHeader>
            <CardContent>
                {/* whitespace-pre-line, because the fallback path is a
                    [PROFILE_READY] recap and the onboarding prompt asks for that
                    as a bullet list — collapsing it would print the bullets as
                    one run-on line. ChatBubble renders the same text this way. */}
                {summary ? (
                    <blockquote className="whitespace-pre-line border-l-2 border-[#FF5733] pl-4 text-sm leading-relaxed text-gray-700">
                        {summary}
                    </blockquote>
                ) : (
                    <p className="text-sm text-gray-400">
                        Încă nu avem un rezumat al conversației tale.{' '}
                        <Link
                            href="/profile/chat"
                            className="font-medium text-[#FF5733] hover:underline"
                        >
                            Stai puțin de vorbă cu Ghes
                        </Link>{' '}
                        și îl scriem pe loc.
                    </p>
                )}
            </CardContent>
        </Card>
    );
}
