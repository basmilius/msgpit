export function tokenize(
    source: string,
): { raw: string; kind: string; name?: string; closing?: boolean; selfClosing?: boolean }[];
export function renderHtmlSource(source: string, options?: { format?: boolean }): string;
