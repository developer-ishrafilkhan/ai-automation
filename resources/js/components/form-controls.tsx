import { cn } from '@/lib/utils';
import * as React from 'react';

const base =
    'w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50';

export function NativeSelect({ className, ...props }: React.ComponentProps<'select'>) {
    return <select className={cn(base, 'h-9 py-1', className)} {...props} />;
}

export function Textarea({ className, ...props }: React.ComponentProps<'textarea'>) {
    return <textarea className={cn(base, 'min-h-24 font-mono text-xs leading-relaxed', className)} {...props} />;
}
