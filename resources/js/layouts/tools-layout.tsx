import { Link, usePage } from '@inertiajs/react';
import { Globe2 } from 'lucide-react';
import { cn } from '@/lib/utils';

const links = [
    { href: '/blacklist', label: 'Blacklist & DNS' },
    { href: '/provider', label: 'Mail provider' },
];

/** Public layout for the two checking tools (no authentication needed). */
export default function ToolsLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    const { url } = usePage();

    return (
        <div className="min-h-screen bg-background text-foreground">
            <header className="border-b bg-card">
                <nav className="mx-auto flex max-w-6xl items-center gap-6 px-4 py-3 md:px-8">
                    <span className="flex items-center gap-2 font-semibold">
                        <Globe2 className="size-5" /> Domain Checks
                    </span>
                    {links.map((link) => (
                        <Link
                            key={link.href}
                            href={link.href}
                            className={cn(
                                'text-sm text-muted-foreground transition-colors hover:text-foreground',
                                url.startsWith(link.href) &&
                                    'font-medium text-foreground',
                            )}
                        >
                            {link.label}
                        </Link>
                    ))}
                </nav>
            </header>
            <main>{children}</main>
        </div>
    );
}
