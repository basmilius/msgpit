import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { FormError, Pill, Segmented, useAsyncAction } from "@basmilius/desktop-ui";
import { SettingsRow, SettingsSection } from "@basmilius/desktop-ui/settings";
import { CodeBlock } from "./components/CodeBlock";
import { KeyValueList } from "./components/KeyValueList";
import { request } from "./api";

const SCENARIOS = ["InvalidNumber", "Unauthorized", "RateLimited", "ServerError"] as const;

export function SetupPanel({ scenario, refresh }: { scenario: string; refresh(): Promise<void> }) {
    const { t } = useTranslation("messages");
    const [host, setHost] = useState("local");
    const [scenarios, setScenarios] = useState<{ id: string; recipient: string }[] | null>(null);
    const action = useAsyncAction();
    const [catalogueError, setCatalogueError] = useState<string | null>(null);
    const base = host === "local" ? location.origin : "http://msgpit:8080";
    const smtpHost = host === "local" ? "localhost" : "msgpit";
    const smtpPort = host === "local" ? 11025 : 1025;
    const mailerDsn = `smtp://${smtpHost}:${smtpPort}`;
    useEffect(() => {
        const controller = new AbortController();
        void request<{ scenarios: { id: string; recipient: string }[] }>("/api/scenarios", {
            signal: controller.signal,
        })
            .then((result) => setScenarios(result.scenarios))
            .catch((error) => {
                if (!controller.signal.aborted) setCatalogueError(error.message);
            });
        return () => controller.abort();
    }, []);
    return (
        <div className="h-full overflow-y-auto">
            <div className="mx-auto flex max-w-3xl flex-col gap-7 px-8 py-6">
                <header className="flex flex-col gap-1">
                    <h2 className="text-base font-medium text-text">{t("setup.title")}</h2>
                    <p className="text-xs text-text-muted">
                        Use this container for the mail and SMS your application sends during
                        development.
                    </p>
                </header>
                <SettingsSection>
                    <SettingsRow
                        label={t("setup.host")}
                        description="Use localhost from your Mac, or the service name from another container on the same Docker network."
                        control={
                            <Segmented
                                label={t("setup.host")}
                                value={host}
                                onValueChange={setHost}
                                options={[
                                    { id: "local", label: "Local" },
                                    { id: "container", label: "Container" },
                                ]}
                            />
                        }
                    />
                </SettingsSection>
                <SettingsSection
                    title={t("setup.mail")}
                    description="No authentication or TLS. Use the mapped host port from your Compose configuration."
                >
                    <SettingsRow label={t("setup.symfony")}>
                        <CodeBlock
                            label={t("setup.symfony")}
                            copyLabel={t("setup.copy")}
                            className="mt-2"
                            wrap
                        >{`MAILER_DSN=${mailerDsn}`}</CodeBlock>
                    </SettingsRow>
                    <SettingsRow label={t("setup.laravel")}>
                        <CodeBlock
                            label={t("setup.laravel")}
                            copyLabel={t("setup.copy")}
                            className="mt-2"
                        >{`MAIL_MAILER=smtp\nMAIL_HOST=${smtpHost}\nMAIL_PORT=${smtpPort}`}</CodeBlock>
                    </SettingsRow>
                    <SettingsRow label={t("setup.smtpServer")}>
                        <KeyValueList
                            className="mt-2"
                            rows={[
                                { key: t("setup.host"), value: smtpHost, mono: true },
                                { key: "Port", value: smtpPort, mono: true },
                            ]}
                        />
                    </SettingsRow>
                </SettingsSection>
                <SettingsSection
                    title={t("setup.providers")}
                    description={t("setup.providersHint")}
                >
                    <SettingsRow
                        label={
                            <span className="flex items-center gap-2">
                                spryng<Pill>SMS</Pill>
                            </span>
                        }
                    >
                        <CodeBlock
                            label="spryng"
                            copyLabel={t("setup.copy")}
                            className="mt-2"
                            wrap
                        >{`${base}/spryng`}</CodeBlock>
                    </SettingsRow>
                    <SettingsRow label={t("setup.api")} description={t("setup.apiHint")}>
                        <CodeBlock
                            label={t("setup.api")}
                            copyLabel={t("setup.copy")}
                            className="mt-2"
                            wrap
                        >{`${base}/api/messages`}</CodeBlock>
                    </SettingsRow>
                </SettingsSection>
                <SettingsSection
                    title={t("scenario.title")}
                    description={t("scenario.description")}
                    footer={t("setup.magicHint")}
                >
                    <SettingsRow label="Next provider request">
                        <div className="mt-2 flex flex-col gap-2">
                            <Segmented
                                label={t("scenario.title")}
                                value={scenario || "off"}
                                disabled={action.busy}
                                onValueChange={(choice) =>
                                    void action.run(async () => {
                                        await request("/api/scenario", {
                                            method: "POST",
                                            headers: { "Content-Type": "application/json" },
                                            body: JSON.stringify({
                                                scenario: choice === "off" ? null : choice,
                                            }),
                                        });
                                        await refresh();
                                    })
                                }
                                options={[
                                    { id: "off", label: t("scenario.none") },
                                    ...SCENARIOS.map((id) => ({ id, label: t(`scenario.${id}`) })),
                                ]}
                            />
                            {scenario && (
                                <p className="text-xs text-text-muted">
                                    The next valid provider request fails, then the switch resets.
                                </p>
                            )}
                            {action.failure && <FormError>{action.failure}</FormError>}
                        </div>
                    </SettingsRow>
                    {catalogueError && <FormError>{catalogueError}</FormError>}
                    <SettingsRow label={t("setup.magic")}>
                        <KeyValueList
                            className="mt-2"
                            keyWidth="w-32"
                            rows={(scenarios ?? []).map((entry) => ({
                                key: entry.recipient,
                                value: t(`scenario.${entry.id}`),
                            }))}
                        />
                    </SettingsRow>
                </SettingsSection>
            </div>
        </div>
    );
}
