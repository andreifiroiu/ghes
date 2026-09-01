import * as React from 'react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';

const Dialog = DialogPrimitive.Root;
const DialogTrigger = DialogPrimitive.Trigger;
const DialogPortal = DialogPrimitive.Portal;
const DialogClose = DialogPrimitive.Close;

const DialogOverlay = React.forwardRef(({ className, ...props }, ref) => (
    <DialogPrimitive.Overlay
        ref={ref}
        className={cn(
            'fixed inset-0 z-50 bg-black/50',
            className,
        )}
        {...props}
    />
));
DialogOverlay.displayName = 'DialogOverlay';

/**
 * @param {Object} props
 * @param {boolean} [props.showCloseButton] Render the corner dismiss button.
 */
const DialogContent = React.forwardRef(
    ({ className, children, showCloseButton = true, ...props }, ref) => (
        <DialogPortal>
            <DialogOverlay />
            <DialogPrimitive.Content
                ref={ref}
                className={cn(
                    'fixed left-1/2 top-1/2 z-50 grid max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 gap-4 overflow-y-auto rounded-lg border border-gray-200 bg-white p-6 shadow-lg',
                    className,
                )}
                {...props}
            >
                {children}
                {showCloseButton && (
                    <DialogPrimitive.Close className="absolute right-4 top-4 rounded-sm text-gray-400 opacity-70 transition-opacity hover:opacity-100 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:pointer-events-none">
                        <X className="h-4 w-4" />
                        <span className="sr-only">Închide</span>
                    </DialogPrimitive.Close>
                )}
            </DialogPrimitive.Content>
        </DialogPortal>
    ),
);
DialogContent.displayName = 'DialogContent';

const DialogHeader = ({ className, ...props }) => (
    <div
        className={cn('flex flex-col space-y-1.5 text-center sm:text-left', className)}
        {...props}
    />
);
DialogHeader.displayName = 'DialogHeader';

const DialogFooter = ({ className, ...props }) => (
    <div
        className={cn(
            'flex flex-col-reverse gap-2 sm:flex-row sm:justify-end',
            className,
        )}
        {...props}
    />
);
DialogFooter.displayName = 'DialogFooter';

const DialogTitle = React.forwardRef(({ className, ...props }, ref) => (
    <DialogPrimitive.Title
        ref={ref}
        className={cn(
            'text-lg font-semibold leading-none tracking-tight text-gray-900',
            className,
        )}
        {...props}
    />
));
DialogTitle.displayName = 'DialogTitle';

const DialogDescription = React.forwardRef(({ className, ...props }, ref) => (
    <DialogPrimitive.Description
        ref={ref}
        className={cn('text-sm text-gray-500', className)}
        {...props}
    />
));
DialogDescription.displayName = 'DialogDescription';

export {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogOverlay,
    DialogPortal,
    DialogTitle,
    DialogTrigger,
};
