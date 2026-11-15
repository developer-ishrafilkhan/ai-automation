import { Pagination } from '@/components/pagination';
import AppLayout from '@/layouts/app-layout';
import { type Asset } from '@/types/automation';
import { Head } from '@inertiajs/react';
import { Images } from 'lucide-react';

interface Props {
    assets: { data: (Asset & { execution_id: number | null })[]; links: { url: string | null; label: string; active: boolean }[] };
}

export default function Assets({ assets }: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Assets', href: '/assets' }]}>
            <Head title="Assets" />
            <div className="space-y-4 p-6">
                {assets.data.length === 0 && (
                    <div className="text-muted-foreground flex flex-col items-center gap-2 p-10 text-sm">
                        <Images className="size-8" /> Images and videos your flows generate will appear here.
                    </div>
                )}
                <div className="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-4">
                    {assets.data.map((asset) => (
                        <a key={asset.id} href={asset.url} target="_blank" rel="noreferrer" className="group overflow-hidden rounded-xl border">
                            {asset.type === 'video' ? (
                                <video
                                    src={asset.url}
                                    className="aspect-square w-full object-cover"
                                    muted
                                    loop
                                    onMouseEnter={(e) => e.currentTarget.play()}
                                />
                            ) : (
                                <img src={asset.url} alt="" className="aspect-square w-full object-cover" loading="lazy" />
                            )}
                            <div className="text-muted-foreground flex justify-between px-3 py-2 text-xs">
                                <span>
                                    {asset.type} · {(asset.size / 1024 / 1024).toFixed(1)} MB
                                </span>
                                <span>run #{asset.execution_id}</span>
                            </div>
                        </a>
                    ))}
                </div>
                <Pagination links={assets.links} />
            </div>
        </AppLayout>
    );
}
