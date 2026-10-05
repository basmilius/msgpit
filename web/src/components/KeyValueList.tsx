import type { ReactNode } from "react";
import clsx from "clsx";

export interface KeyValueRow {
    key: string;
    value: ReactNode;
    /* The value is a header, an id or a path, read back letter by letter. */
    mono?: boolean;
}

export interface KeyValueListProps {
    rows: readonly KeyValueRow[];
    /* The key column's width, as a Tailwind width class. */
    keyWidth?: string;
    className?: string;
}

// todo(desktop-ui#29): replace with the library component once it ships.
export function KeyValueList({ rows, keyWidth = "w-36", className }: KeyValueListProps) {
    return (
        <dl className={clsx("min-w-0 divide-y divide-border-soft text-xs", className)}>
            {rows.map((row, index) => (
                <div key={`${row.key}:${index}`} className="flex gap-3 py-1.5">
                    <dt className={clsx("shrink-0 text-text-faint", keyWidth)}>{row.key}</dt>
                    <dd
                        className={clsx(
                            "min-w-0 grow break-words text-text",
                            row.mono && "font-mono text-code",
                        )}
                    >
                        {row.value}
                    </dd>
                </div>
            ))}
        </dl>
    );
}
