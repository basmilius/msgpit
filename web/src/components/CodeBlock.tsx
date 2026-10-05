import { Copy } from "lucide-react";
import clsx from "clsx";
import { copyText, IconButton } from "@basmilius/desktop-ui";

export function CodeBlock({
    children,
    label,
    copyLabel,
    wrap = false,
    className,
}: {
    children: string;
    label: string;
    copyLabel?: string;
    wrap?: boolean;
    className?: string;
}) {
    return (
        <div
            role="group"
            aria-label={label}
            className={clsx("relative flex min-w-0 flex-col", className)}
        >
            <pre
                className={clsx(
                    "min-h-0 grow overflow-auto rounded-lg border border-border bg-surface p-3 font-mono text-code text-text",
                    wrap && "break-words whitespace-pre-wrap",
                )}
            >
                <code className={copyLabel ? "block pr-7" : undefined}>{children}</code>
            </pre>
            {copyLabel !== undefined && (
                <IconButton
                    icon={Copy}
                    size="sm"
                    label={copyLabel}
                    className="absolute top-1.5 right-1.5"
                    onClick={() => void copyText(children)}
                />
            )}
        </div>
    );
}
