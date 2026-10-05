import { useState } from "react";
import clsx from "clsx";
import { useTranslation } from "react-i18next";
import { Button, EmptyState, FormError, Pill, useAsyncAction } from "@basmilius/desktop-ui";
import { request, type Detail, type LinkCheckResult } from "./api";
import { statusTone } from "./model";
export function HtmlCheck({ message }: { message: Detail }) {
    const { t } = useTranslation("messages");
    const check = message.htmlCheck;
    if (!check) {
        return <EmptyState>{message.html ? t("check.nothing") : t("mail.noHtml")}</EmptyState>;
    }
    return (
        <div className="flex flex-col gap-4 p-6">
            <div className="flex flex-col gap-1">
                <p className="text-sm font-medium text-text">
                    {t("check.headline", { supported: check.supported })}
                </p>
                <p className="text-xs text-text-muted">
                    {t("check.split", {
                        partial: check.partial,
                        unsupported: check.unsupported,
                        features: check.features,
                    })}
                </p>
            </div>
            {check.warnings.length === 0 ? (
                <p className="text-xs text-text-muted">{t("check.none")}</p>
            ) : (
                <div className="flex flex-col">
                    <p className="pb-2 text-xs font-medium text-text-faint">{t("check.worth")}</p>
                    <div className="grid grid-cols-[minmax(0,1fr)_64px_48px_64px_48px] gap-x-3 border-b border-border pb-1 text-xs text-text-faint">
                        <span>{t("check.columns.feature")}</span>
                        <span>{t("check.columns.category")}</span>
                        <span className="text-right">{t("check.columns.yes")}</span>
                        <span className="text-right">{t("check.columns.partial")}</span>
                        <span className="text-right">{t("check.columns.no")}</span>
                    </div>
                    {check.warnings.map((warning) => (
                        <div
                            key={warning.slug}
                            className="grid grid-cols-[minmax(0,1fr)_64px_48px_64px_48px] gap-x-3 border-b border-border-soft py-1.5 text-xs tabular-nums"
                        >
                            <span className="truncate text-text">{warning.title}</span>
                            <span className="text-text-muted">{warning.category}</span>
                            <span className="text-right text-text-muted">{warning.supported}</span>
                            <span className="text-right text-text-muted">{warning.partial}</span>
                            <span className="text-right text-text-muted">
                                {warning.unsupported}
                            </span>
                        </div>
                    ))}
                </div>
            )}
            {check.dataUpdated !== null && (
                <p className="text-xs text-text-faint">
                    {t("check.attribution", { date: check.dataUpdated.slice(0, 10) })}
                </p>
            )}
        </div>
    );
}

export function Links({ message }: { message: Detail }) {
    const { t } = useTranslation("messages");
    const action = useAsyncAction();
    const [results, setResults] = useState<LinkCheckResult[] | null>(null);
    const links = message.links ?? [];
    const check = (): void => {
        void action.run(async () => {
            const { links: checked } = await request<{ links: LinkCheckResult[] }>(
                `/api/messages/${message.id}/links`,
                { method: "POST" },
            );
            setResults(checked);
        });
    };
    if (links.length === 0) {
        return <EmptyState>{t("links.none")}</EmptyState>;
    }
    const resultOf = (url: string): LinkCheckResult | undefined =>
        results?.find((result) => result.url === url);
    return (
        <div className="flex flex-col gap-3 p-4">
            <div className="flex items-center gap-3">
                <p className="grow text-xs text-text-muted">{t("links.intro")}</p>
                <Button variant="secondary" disabled={action.busy} onClick={check}>
                    {action.busy ? t("links.checking") : t("links.check")}
                </Button>
            </div>
            {action.failure && <FormError>{action.failure}</FormError>}
            <ul className="flex flex-col divide-y divide-border-soft">
                {links.map((link) => {
                    const result = resultOf(link.url);
                    return (
                        <li key={link.url} className="flex flex-col gap-0.5 py-2">
                            <div className="flex items-center gap-2">
                                <span className="min-w-0 grow truncate font-mono text-xs text-text">
                                    {link.url}
                                </span>
                                <Pill>{t(`links.${link.kind}`)}</Pill>
                                {result && (
                                    <Pill tone={statusTone(result.status)}>
                                        {result.status === null
                                            ? (result.reason ?? t("links.failed"))
                                            : result.status}
                                    </Pill>
                                )}
                            </div>
                            {result?.redirect && (
                                <span className="text-xs text-text-muted">
                                    {t("links.redirect", { url: result.redirect })}
                                </span>
                            )}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

export function Spam({ message, configured }: { message: Detail; configured: boolean }) {
    const { t } = useTranslation("messages");
    const spam = message.spam;
    if (!spam) {
        return <EmptyState>{configured ? t("spam.none") : t("spam.off")}</EmptyState>;
    }
    return (
        <div className="flex flex-col gap-4 p-6">
            <div className="flex items-center gap-3">
                <span className="text-lg font-medium text-text tabular-nums">
                    {t("spam.score", { score: spam.score, threshold: spam.threshold })}
                </span>
                <Pill tone={spam.spam ? "error" : "idle"}>
                    {spam.spam ? t("spam.spam") : t("spam.ham")}
                </Pill>
            </div>
            {spam.rules.length > 0 && (
                <div className="flex flex-col">
                    <div className="grid grid-cols-[56px_200px_minmax(0,1fr)] gap-x-3 border-b border-border pb-1 text-xs text-text-faint">
                        <span className="text-right">{t("spam.points")}</span>
                        <span>{t("spam.rule")}</span>
                        <span>{t("spam.description")}</span>
                    </div>
                    {spam.rules.map((rule) => (
                        <div
                            key={rule.name}
                            className="grid grid-cols-[56px_200px_minmax(0,1fr)] gap-x-3 border-b border-border-soft py-1.5 text-xs"
                        >
                            <span
                                className={clsx(
                                    "text-right tabular-nums",
                                    rule.points > 0 ? "text-status-error" : "text-text-muted",
                                )}
                            >
                                {rule.points > 0 ? `+${rule.points}` : rule.points}
                            </span>
                            <span className="truncate font-mono text-text">{rule.name}</span>
                            <span className="text-text-muted">{rule.description}</span>
                        </div>
                    ))}
                </div>
            )}
            <p className="text-xs text-text-faint">{t("spam.caveat")}</p>
        </div>
    );
}
