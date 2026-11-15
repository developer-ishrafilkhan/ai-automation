import { SVGAttributes } from 'react';

/** Brand mark: a canvas node wired to an AI sparkle. Uses currentColor. */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" {...props}>
            <rect x="2" y="13.5" width="8.5" height="8.5" rx="2.5" />
            <path d="M10.5 17.75C14.5 17.75 17 17.5 17 13" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
            <path d="M17 2c.6 4.2 1.8 5.4 6 6-4.2.6-5.4 1.8-6 6-.6-4.2-1.8-5.4-6-6 4.2-.6 5.4-1.8 6-6Z" />
        </svg>
    );
}
