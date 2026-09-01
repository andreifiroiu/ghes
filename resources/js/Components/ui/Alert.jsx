import * as React from 'react';
import { cva } from 'class-variance-authority';
import { cn } from '@/lib/utils';

const alertVariants = cva(
    'flex items-start gap-3 rounded-md border px-4 py-3 text-sm',
    {
        variants: {
            variant: {
                success: 'bg-green-50 border-green-200 text-green-800',
                error: 'bg-red-50 border-red-200 text-red-800',
                info: 'bg-blue-50 border-blue-200 text-blue-800',
            },
        },
        defaultVariants: {
            variant: 'success',
        },
    }
);

/**
 * A short, inline status message.
 *
 * `role="status"` rather than `alert`: these announce something that already
 * succeeded, so a screen reader should mention it politely without cutting off
 * whatever it is currently reading.
 *
 * @param {Object} props
 * @param {'success'|'error'|'info'} [props.variant]
 * @param {React.ReactNode} [props.icon] Rendered before the content; decorative.
 * @param {() => void} [props.onDismiss] Omit to render without a close button.
 * @param {string} [props.dismissLabel] Accessible name for the close button.
 * @param {string} [props.className]
 * @param {React.ReactNode} props.children
 */
function Alert({
    variant,
    icon,
    onDismiss,
    dismissLabel = 'Închide',
    className,
    children,
    ...props
}) {
    return (
        <div
            role="status"
            className={cn(alertVariants({ variant }), className)}
            {...props}
        >
            {icon && (
                <span className="shrink-0 leading-5" aria-hidden="true">
                    {icon}
                </span>
            )}
            <div className="flex-1">{children}</div>
            {onDismiss && (
                <button
                    type="button"
                    onClick={onDismiss}
                    aria-label={dismissLabel}
                    className="shrink-0 rounded p-0.5 opacity-60 transition-opacity hover:opacity-100 focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-current"
                >
                    <svg
                        className="h-4 w-4"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            strokeWidth={2}
                            d="M6 18L18 6M6 6l12 12"
                        />
                    </svg>
                </button>
            )}
        </div>
    );
}

export { Alert, alertVariants };
