import AppLogoIcon from '@/components/app-logo-icon';
import { type ReactNode } from 'react';

const appName = import.meta.env.VITE_APP_NAME || 'AI Automation';

/** Plain, brand-light page for visitors of a public form (no app navigation). */
export default function PublicFormLayout({ children }: { children: ReactNode }) {
    return (
        <div className="bg-muted/40 flex min-h-screen flex-col items-center px-4 py-10">
            <main className="w-full max-w-2xl flex-1">{children}</main>
            <footer className="text-muted-foreground mt-10 flex items-center gap-1.5 text-xs">
                <AppLogoIcon className="size-3.5 fill-current" /> Made with {appName}
            </footer>
        </div>
    );
}
