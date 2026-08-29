import * as React from 'react';
import { cn } from '@/lib/utils';

const Select = React.forwardRef(({ className, children, ...props }, ref) => {
    return (
        <select
            ref={ref}
            className={cn(
                'flex h-11 sm:h-10 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-base sm:text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-50',
                className
            )}
            {...props}
        >
            {children}
        </select>
    );
});
Select.displayName = 'Select';

export { Select };
