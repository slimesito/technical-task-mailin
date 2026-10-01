import { Head } from '@inertiajs/react';
import { CheckTool } from '@/components/checks/check-tool';

export default function ProviderCheck() {
    return (
        <>
            <Head title="Mail provider detection" />
            <CheckTool
                type="provider"
                title="Google Workspace / Microsoft 365 detection"
                description="Find out which mail platform a domain uses, based on its MX records and supporting DNS evidence."
            />
        </>
    );
}
