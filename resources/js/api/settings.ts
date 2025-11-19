import http from './http';
import type { RestockHorizon } from './reports';

export type SettingsResponse = {
    restock: {
        available: Array<RestockHorizon>;
        horizon: Array<RestockHorizon>;
        last_run?: string | null;
        include_zero?: boolean;
        min_days?: number;
    };
    card_charge_percent: number;
    last_closing_balance: number | null;
    analysis?: {
        recommended_percentage: number;
        recommended_months: number;
    };
};

export async function getSystemSettings() {
    const { data } = await http.get<SettingsResponse>('/settings/general');
    return data;
}

export async function updateSystemSettings(payload: {
    horizon?: Array<RestockHorizon>;
    card_charge_percent?: number;
    restock_include_zero?: boolean;
    restock_min_days?: number;
    recommended_percentage?: number;
    recommended_months?: number;
}) {
    const { data } = await http.post<SettingsResponse>('/settings/general', payload);
    return data;
}

export async function runRestockForecastManual(horizon: Array<RestockHorizon>) {
    const { data } = await http.post<{ message: string; horizon: Array<RestockHorizon> }>(
        '/settings/general/run-restock',
        { horizon }
    );
    return data;
}
