import { Head } from '@inertiajs/react';
import { CheckTool } from '@/components/checks/check-tool';

export default function BlacklistCheck() {
    return (
        <>
            <Head title="Blacklist & DNS checker" />
            <CheckTool
                type="blacklist"
                title="Blacklist & DNS checker"
                description="Check a domain or email against DNS blacklists and inspect its MX, SPF, DKIM, DMARC, NS, A, AAAA, CNAME and PTR records."
            />
        </>
    );
}
