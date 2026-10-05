import { useState } from "react";
import { Button, FormError, Pill, SectionLabel, useAsyncAction } from "@basmilius/desktop-ui";
import { request, type Detail, type Report } from "./api";

export function ReportView({ message }: { message: Detail }) {
    const action = useAsyncAction();
    const [networkReport, setNetworkReport] = useState<Report | null>(null);
    const [lookups, setLookups] = useState<number | null>(null);
    const report = networkReport ?? message.report;
    if (!report) return null;
    return (
        <div className="flex flex-col gap-4 p-6">
            <div className="flex flex-wrap items-center gap-3">
                <span className="grow text-lg font-medium text-text tabular-nums">
                    {report.score.toFixed(1)} / {report.max}
                </span>
                <Button
                    variant="secondary"
                    disabled={!message.dnsEnabled || action.busy}
                    onClick={() =>
                        void action.run(async () => {
                            const result = await request<{ report: Report; lookups: number }>(
                                `/api/messages/${message.id}/authentication`,
                                { method: "POST" },
                            );
                            setNetworkReport(result.report);
                            setLookups(result.lookups);
                        })
                    }
                >
                    {action.busy ? "Checking sender…" : "Check sender DNS"}
                </Button>
            </div>
            <p className="text-xs text-text-muted">
                {report.passed} passed · {report.applicable} applicable · {report.skipped} skipped.
                This score describes the captured message; real filters also use sender reputation
                and history.
            </p>
            <p className="text-xs text-text-faint">
                {message.dnsEnabled
                    ? "Sender checks query SPF, DKIM, DMARC, reverse DNS and blocklists on demand."
                    : "Sender DNS checks are disabled with MSGPIT_DNS."}
                {lookups !== null && ` ${lookups} DNS lookups; cached answers are reused.`}
            </p>
            {action.failure && <FormError>{action.failure}</FormError>}
            <div className="flex flex-col divide-y divide-border-soft">
                {report.findings.map((finding) => (
                    <details key={finding.id} className="group py-3">
                        <summary className="flex cursor-pointer list-none items-start gap-3 text-xs">
                            <span className="flex min-w-0 grow flex-col gap-1">
                                <SectionLabel>{finding.section}</SectionLabel>
                                <span className="text-text">{finding.title}</span>
                            </span>
                            {finding.penalty > 0 && (
                                <span className="text-text-faint tabular-nums">
                                    −{finding.penalty}
                                </span>
                            )}
                            <Pill
                                tone={
                                    finding.status === "fail"
                                        ? "error"
                                        : finding.status === "warn"
                                          ? "needsYou"
                                          : finding.status === "pass"
                                            ? "idle"
                                            : "muted"
                                }
                            >
                                {finding.status}
                            </Pill>
                        </summary>
                        <div className="mt-2 flex flex-col gap-2 text-xs text-text-muted">
                            {finding.explanation && <p>{finding.explanation}</p>}
                            {finding.evidence.length > 0 && (
                                <ul className="flex flex-col gap-1 font-mono break-words">
                                    {finding.evidence.map((entry, index) => (
                                        <li key={index}>{entry}</li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </details>
                ))}
            </div>
        </div>
    );
}
