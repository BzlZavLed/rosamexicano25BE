const DEFAULT_THEME = 'rosa-mexicano';

type ThemeLoader = () => Promise<unknown>;

const themeModules = import.meta.glob('../css/themes/*.css') as Record<string, ThemeLoader>;

const themeNameMap = Object.entries(themeModules).reduce<Record<string, ThemeLoader>>((acc, [path, loader]) => {
    const filename = path.split('/').pop() ?? '';
    const name = filename.replace('.css', '').toLowerCase();
    acc[name] = loader;
    return acc;
}, {});

export async function loadTheme(): Promise<string> {
    const envTheme = (import.meta.env.VITE_APP_THEME as string | undefined)?.toLowerCase().trim();
    const targetTheme = envTheme && themeNameMap[envTheme] ? envTheme : DEFAULT_THEME;
    await ensureThemeLoaded(targetTheme);
    document.documentElement.dataset.theme = targetTheme;
    return targetTheme;
}

async function ensureThemeLoaded(theme: string) {
    const loader = themeNameMap[theme];
    if (!loader && theme !== DEFAULT_THEME) {
        await ensureThemeLoaded(DEFAULT_THEME);
        return;
    }

    try {
        await (loader?.());
    } catch (error) {
        console.warn(`Failed to load theme "${theme}", falling back to "${DEFAULT_THEME}"`, error);
        if (theme !== DEFAULT_THEME) {
            await ensureThemeLoaded(DEFAULT_THEME);
        }
    }
}
