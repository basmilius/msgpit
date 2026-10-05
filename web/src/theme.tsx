import { createContext, useContext, useEffect, useState, type ReactNode } from "react";
import { Segmented } from "@basmilius/desktop-ui";
import { SettingsRow, SettingsSection } from "@basmilius/desktop-ui/settings";

type Theme = "system" | "light" | "dark";
const ThemeContext = createContext<{ theme: Theme; setTheme(theme: Theme): void }>({
    theme: "system",
    setTheme: () => {},
});

export function ThemeProvider({ children }: { children: ReactNode }) {
    const [theme, setTheme] = useState<Theme>(() => {
        const saved = localStorage.getItem("msgpit.theme");
        return saved === "light" || saved === "dark" ? saved : "system";
    });
    useEffect(() => {
        const media = matchMedia("(prefers-color-scheme: dark)");
        function apply() {
            const dark = theme === "dark" || (theme === "system" && media.matches);
            document.documentElement.dataset.theme = dark ? "dark" : "light";
            document.documentElement.style.colorScheme = dark ? "dark" : "light";
        }
        localStorage.setItem("msgpit.theme", theme);
        apply();
        media.addEventListener("change", apply);
        return () => media.removeEventListener("change", apply);
    }, [theme]);
    return <ThemeContext value={{ theme, setTheme }}>{children}</ThemeContext>;
}

export function AppearancePane() {
    const { theme, setTheme } = useContext(ThemeContext);
    return (
        <SettingsSection title="Appearance">
            <SettingsRow
                label="Theme"
                description="Use your system appearance or choose a light or dark theme."
                control={
                    <Segmented<Theme>
                        label="Theme"
                        value={theme}
                        onValueChange={setTheme}
                        options={[
                            { id: "system", label: "System" },
                            { id: "light", label: "Light" },
                            { id: "dark", label: "Dark" },
                        ]}
                    />
                }
            />
        </SettingsSection>
    );
}
