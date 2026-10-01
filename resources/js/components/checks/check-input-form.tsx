import { FileUp, Loader2, Search } from 'lucide-react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type { CheckType } from '@/types/checks';

type Mode = 'single' | 'bulk';

type Props = {
    type: CheckType;
    busy: boolean;
    error: string | null;
    onSingle: (input: string, dkimSelector: string) => void;
    onBulk: (file: File, dkimSelector: string) => void;
};

export function CheckInputForm({ type, busy, error, onSingle, onBulk }: Props) {
    const [mode, setMode] = useState<Mode>('single');
    const [input, setInput] = useState('');
    const [selector, setSelector] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [dragging, setDragging] = useState(false);
    const fileInput = useRef<HTMLInputElement>(null);

    const showSelector = type === 'blacklist';

    function submit(e: FormEvent) {
        e.preventDefault();
        if (mode === 'single') {
            if (input.trim()) onSingle(input.trim(), selector.trim());
        } else if (file) {
            onBulk(file, selector.trim());
        }
    }

    return (
        <form
            onSubmit={submit}
            className="space-y-4 rounded-xl border bg-card p-5"
        >
            <div className="inline-flex rounded-lg bg-muted p-1" role="tablist">
                {(['single', 'bulk'] as const).map((m) => (
                    <button
                        key={m}
                        type="button"
                        role="tab"
                        aria-selected={mode === m}
                        onClick={() => setMode(m)}
                        className={cn(
                            'rounded-md px-4 py-1.5 text-sm font-medium transition-colors',
                            mode === m
                                ? 'bg-background shadow-sm'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {m === 'single' ? 'Single check' : 'Bulk upload'}
                    </button>
                ))}
            </div>

            {mode === 'single' ? (
                <div className="space-y-2">
                    <Label htmlFor="domain-input">Domain or email</Label>
                    <Input
                        id="domain-input"
                        value={input}
                        onChange={(e) => setInput(e.target.value)}
                        placeholder="example.com or user@example.com"
                        autoComplete="off"
                    />
                </div>
            ) : (
                <div className="space-y-2">
                    <Label>
                        CSV or TXT file (one domain or email per line, max
                        10,000)
                    </Label>
                    <div
                        onDragOver={(e) => {
                            e.preventDefault();
                            setDragging(true);
                        }}
                        onDragLeave={() => setDragging(false)}
                        onDrop={(e) => {
                            e.preventDefault();
                            setDragging(false);
                            setFile(e.dataTransfer.files[0] ?? null);
                        }}
                        onClick={() => fileInput.current?.click()}
                        className={cn(
                            'flex cursor-pointer flex-col items-center gap-2 rounded-lg border-2 border-dashed p-8 text-sm text-muted-foreground transition-colors hover:border-foreground/40',
                            dragging && 'border-primary bg-accent',
                        )}
                    >
                        <FileUp className="size-6" />
                        {file ? (
                            <span className="font-medium text-foreground">
                                {file.name} (
                                {Math.max(1, Math.round(file.size / 1024))} KB)
                            </span>
                        ) : (
                            <span>Drop a file here or click to browse</span>
                        )}
                        <input
                            ref={fileInput}
                            type="file"
                            accept=".csv,.txt,text/csv,text/plain"
                            className="hidden"
                            onChange={(e) =>
                                setFile(e.target.files?.[0] ?? null)
                            }
                        />
                    </div>
                </div>
            )}

            {showSelector && (
                <div className="space-y-2">
                    <Label htmlFor="dkim-selector">
                        DKIM selector (optional)
                    </Label>
                    <Input
                        id="dkim-selector"
                        value={selector}
                        onChange={(e) => setSelector(e.target.value)}
                        placeholder="google, selector1, default…"
                        autoComplete="off"
                        className="max-w-xs"
                    />
                </div>
            )}

            {error && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}

            <Button
                type="submit"
                disabled={busy || (mode === 'single' ? !input.trim() : !file)}
            >
                {busy ? <Loader2 className="animate-spin" /> : <Search />}
                {mode === 'single' ? 'Check domain' : 'Upload and check'}
            </Button>
        </form>
    );
}
