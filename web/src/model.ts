import type { Message } from "./api";

export const subjectOf = (message: Pick<Message, "meta" | "body">): string => {
    const subject = message.meta.subject;
    return typeof subject === "string" && subject !== "" ? subject : message.body;
};

export const previewOf = (message: Pick<Message, "channel" | "body" | "to">): string => {
    return message.channel === "email" ? message.to : message.body.replace(/\s+/g, " ").trim();
};

export const titleOf = (message: Pick<Message, "meta" | "body" | "channel" | "to">): string => {
    return message.channel === "email" ? subjectOf(message) : message.to;
};

export interface BodyRun {
    text: string;
    flagged: boolean;
}

// Offsets are code points, as the Rust server counts them.
export const bodyRuns = (body: string, offsets: readonly number[]): BodyRun[] => {
    const flagged = new Set(offsets);
    const runs: BodyRun[] = [];
    Array.from(body).forEach((character, offset) => {
        const isFlagged = flagged.has(offset);
        const last = runs.at(-1);
        if (last && last.flagged === isFlagged) last.text += character;
        else runs.push({ text: character, flagged: isFlagged });
    });
    return runs;
};

export function segmentBars(message: Message): { used: number; capacity: number; ratio: number }[] {
    if (!message.segments) return [];
    const unicode = message.encoding === "UCS-2";
    const capacity = message.segments === 1 ? (unicode ? 70 : 160) : unicode ? 67 : 153;
    const fill = [0];
    for (const character of Array.from(message.body)) {
        const cost = unicode ? character.length : "\f^{}\\[~]|€".includes(character) ? 2 : 1;
        if (fill[fill.length - 1]! + cost > capacity) fill.push(0);
        fill[fill.length - 1]! += cost;
    }
    return fill.map((used) => ({ used, capacity, ratio: used / capacity }));
}

export const foldBase64 = (source: string, marker: (bytes: number) => string): string => {
    const lines = source.split("\n");
    const out: string[] = [];
    let run: string[] = [];
    const flush = (): void => {
        if (run.length >= 4) {
            const characters = run.reduce((sum, line) => sum + line.trim().length, 0);
            out.push(marker(Math.floor((characters * 3) / 4)));
        } else out.push(...run);
        run = [];
    };
    for (const line of lines) {
        if (/^[A-Za-z0-9+/]{40,}={0,2}\r?$/.test(line)) run.push(line);
        else {
            flush();
            out.push(line);
        }
    }
    flush();
    return out.join("\n");
};

export const statusTone = (status: number | null): "muted" | "error" | "needsYou" | "idle" =>
    status === null ? "muted" : status >= 400 ? "error" : status >= 300 ? "needsYou" : "idle";
