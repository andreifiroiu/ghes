import { cn } from '@/lib/utils';

/**
 * @param {Object} props
 * @param {'user'|'assistant'} props.role
 * @param {string} props.content
 * @param {string} [props.timestamp]
 */
export default function ChatBubble({ role, content, timestamp }) {
    const isUser = role === 'user';

    return (
        <div
            className={cn(
                'flex w-full mb-4',
                isUser ? 'justify-end' : 'justify-start'
            )}
        >
            <div
                className={cn(
                    'max-w-[75%] rounded-2xl px-4 py-3 text-sm leading-relaxed',
                    isUser
                        ? 'bg-indigo-600 text-white rounded-br-md'
                        : 'bg-gray-100 text-gray-900 rounded-bl-md'
                )}
            >
                <p className="whitespace-pre-wrap break-words">{content}</p>
                {timestamp && (
                    <p
                        className={cn(
                            'mt-1 text-xs',
                            isUser ? 'text-indigo-200' : 'text-gray-400'
                        )}
                    >
                        {new Date(timestamp).toLocaleTimeString([], {
                            hour: '2-digit',
                            minute: '2-digit',
                        })}
                    </p>
                )}
            </div>
        </div>
    );
}
