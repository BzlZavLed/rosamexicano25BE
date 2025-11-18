import http from './http';

export type SettingsResponse = {
    restock: {
        available: Array<'day' | 'week' | 'month'>;
        horizon: Array<'day' | 'week' | 'month'>;
        last_run?: string | null;
    };
    card_charge_percent: number;
    last_closing_balance: number | null;
};

export async function getSystemSettings() {
    const { data } = await http.get<SettingsResponse>('/settings/general');
    return data;
}

export async function updateSystemSettings(payload: {
    horizon?: Array<'day' | 'week' | 'month'>;
    card_charge_percent?: number;
}) {
    const { data } = await http.post<SettingsResponse>('/settings/general', payload);
    return data;
}

export async function runRestockForecastManual(horizon: Array<'day' | 'week' | 'month'>) {
    const { data } = await http.post<{ message: string; horizon: Array<'day' | 'week' | 'month'> }>(
        '/settings/general/run-restock',
        { horizon }
    );
    return data;
}
